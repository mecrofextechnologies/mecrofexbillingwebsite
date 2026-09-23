<?php
/* Automatic creator withdrawals: Creator → request → Daraja B2C → confirmation.

   State machine on withdrawal_requests (payout_method = 'b2c'):

     pending ──dispatch──▶ processing ──result OK──▶ successful  (balance finalized)
        │                     │
        └─────────────────────┴──rejected/failed──▶ failed      (balance released)

   Money-safety rules:
   • The creator's balance is debited ("reserved") in the same DB
     transaction that creates the request, under a row lock on my_balance,
     so concurrent requests can never spend the same shillings.
   • Every transition is a status-guarded UPDATE / SELECT … FOR UPDATE, so
     duplicate submits, page refreshes, network retries and repeated
     Safaricom callbacks each take effect at most once.
   • A reserved balance is only released by a transition to 'failed', and
     balance_state ('reserved' → 'released'/'finalized') guards against a
     double refund.
   • An ambiguous B2C response (timeout) keeps the row 'processing' with the
     funds still reserved — refunding there could pay the creator twice.
     The result callback, or an admin reconciliation on
     admin011/approve_withdrawal.php, settles it.

   Legacy rows (payout_method = 'manual', and fundraiser withdrawals with a
   campaign_id) keep the old admin-approval flow untouched. */

require_once __DIR__ . '/daraja.php';
require_once __DIR__ . '/talksasa.php';

const KC_WD_MIN_AMOUNT = 10;      // Safaricom B2C minimum
const KC_WD_MAX_AMOUNT = 250000;  // Safaricom B2C per-transaction maximum

/* Idempotent schema upgrade (same self-healing approach used elsewhere in
   this codebase, e.g. approve_withdrawal.php's campaign_id check). Runs DDL,
   which commits implicitly — never call inside an open transaction.
   The equivalent one-off migration is
   admin011/database/2026_daraja_auto_withdrawals.sql. */
function kc_wd_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $cols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM withdrawal_requests")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cols[$c['Field']] = strtolower($c['Type']);
    }
    $add = [
        'campaign_id'          => "INT UNSIGNED NULL DEFAULT NULL",
        'payout_method'        => "VARCHAR(20) NOT NULL DEFAULT 'manual'",
        'reference'            => "VARCHAR(40) NULL",
        'idempotency_key'      => "VARCHAR(64) NULL",
        'creator_username'     => "VARCHAR(100) NULL",
        'conversation_id'      => "VARCHAR(100) NULL",
        'provider_result_code' => "VARCHAR(20) NULL",
        'provider_response'    => "TEXT NULL",
        'failure_reason'       => "VARCHAR(255) NULL",
        'balance_state'        => "VARCHAR(20) NULL",
        'completed_at'         => "DATETIME NULL",
        'sms_status'           => "VARCHAR(20) NULL",
        'sms_error'            => "VARCHAR(255) NULL",
        'sms_sent_at'          => "DATETIME NULL",
    ];
    foreach ($add as $name => $def) {
        if (!isset($cols[$name])) $pdo->exec("ALTER TABLE withdrawal_requests ADD COLUMN `$name` $def");
    }
    // 'successful' / 'failed' must be storable whatever the legacy column type was.
    if (isset($cols['status']) && str_starts_with($cols['status'], 'enum')) {
        $pdo->exec("ALTER TABLE withdrawal_requests MODIFY `status` VARCHAR(20) NOT NULL DEFAULT 'pending'");
    }
    $idx = array_column($pdo->query("SHOW INDEX FROM withdrawal_requests")->fetchAll(PDO::FETCH_ASSOC), 'Key_name');
    if (!in_array('uq_wd_reference', $idx, true)) $pdo->exec("ALTER TABLE withdrawal_requests ADD UNIQUE KEY uq_wd_reference (reference)");
    if (!in_array('uq_wd_idem', $idx, true))      $pdo->exec("ALTER TABLE withdrawal_requests ADD UNIQUE KEY uq_wd_idem (idempotency_key)");
    if (!in_array('idx_wd_conv', $idx, true))     $pdo->exec("ALTER TABLE withdrawal_requests ADD INDEX idx_wd_conv (conversation_id)");
    if (!in_array('idx_wd_creator_status', $idx, true)) $pdo->exec("ALTER TABLE withdrawal_requests ADD INDEX idx_wd_creator_status (creator_id, status)");
    $done = true;
}

function kc_wd_format_kes(float $amount): string {
    return number_format($amount, floor($amount) == $amount ? 0 : 2);
}

/* Step 1 of a payout: validate against the live balance and reserve it.
   $p keys: creator_id, username, phone (2547…/2541…), net, charge, total,
            idem_key, notes.
   Returns ['ok'=>true,'id','reference','duplicate'] or ['ok'=>false,'error']. */
function kc_wd_create_and_reserve(PDO $pdo, array $p): array {
    $creatorId = (int)$p['creator_id'];
    $total     = round((float)$p['total'], 2);
    if ($total <= 0) return ['ok' => false, 'error' => 'Invalid withdrawal amount.'];

    $pdo->beginTransaction();
    try {
        // Row lock on the balance serialises every withdrawal for this creator.
        $bal = $pdo->prepare("SELECT amount FROM my_balance WHERE creator_id = ? FOR UPDATE");
        $bal->execute([$creatorId]);
        $balance = $bal->fetchColumn();

        $dup = $pdo->prepare("SELECT id, reference FROM withdrawal_requests WHERE idempotency_key = ? LIMIT 1");
        $dup->execute([$p['idem_key']]);
        if ($existing = $dup->fetch(PDO::FETCH_ASSOC)) {
            $pdo->commit();
            return ['ok' => true, 'id' => (int)$existing['id'], 'reference' => $existing['reference'], 'duplicate' => true];
        }

        $inflight = $pdo->prepare("SELECT COUNT(*) FROM withdrawal_requests WHERE creator_id = ? AND campaign_id IS NULL AND status IN ('pending','processing')");
        $inflight->execute([$creatorId]);
        if ((int)$inflight->fetchColumn() > 0) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'You already have a withdrawal in progress. Please wait for it to complete.'];
        }

        if ($balance === false) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Insufficient balance.'];
        }

        $debit = $pdo->prepare("UPDATE my_balance SET amount = amount - ? WHERE creator_id = ? AND amount >= ?");
        $debit->execute([$total, $creatorId, $total]);
        if ($debit->rowCount() !== 1) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Insufficient balance. You need KES ' . number_format($total, 2)
                                             . ' but have KES ' . number_format((float)$balance, 2) . '.'];
        }

        $reference = 'KCW' . date('ymdHis') . strtoupper(bin2hex(random_bytes(3)));
        $pdo->prepare("
            INSERT INTO withdrawal_requests
                (creator_id, mpesa_number, amount, charge, total_amount, status, requested_at, notes,
                 payout_method, reference, idempotency_key, creator_username, balance_state)
            VALUES (?, ?, ?, ?, ?, 'pending', NOW(), ?, 'b2c', ?, ?, ?, 'reserved')
        ")->execute([
            $creatorId, kc_encrypt($p['phone']), $p['net'], $p['charge'], $total, kc_encrypt($p['notes']),
            $reference, $p['idem_key'], substr((string)$p['username'], 0, 100),
        ]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        kc_payment_log('withdrawals', "Reserved #$id $reference creator $creatorId total $total");
        return ['ok' => true, 'id' => $id, 'reference' => $reference, 'duplicate' => false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // Unique-key race on idempotency_key: the other request won — report its row.
        if ($e instanceof PDOException && ($e->errorInfo[1] ?? null) == 1062) {
            $dup = $pdo->prepare("SELECT id, reference FROM withdrawal_requests WHERE idempotency_key = ? LIMIT 1");
            $dup->execute([$p['idem_key']]);
            if ($existing = $dup->fetch(PDO::FETCH_ASSOC)) {
                return ['ok' => true, 'id' => (int)$existing['id'], 'reference' => $existing['reference'], 'duplicate' => true];
            }
        }
        kc_payment_log('withdrawals', 'Reserve failed for creator ' . $creatorId . ': ' . get_class($e) . ' ' . substr($e->getMessage(), 0, 200));
        return ['ok' => false, 'error' => 'Could not create the withdrawal. Please try again.'];
    }
}

/* True once B2C credentials are configured. Until then, creator withdrawals
   fall back to the legacy admin-approval flow (see kc_wd_create_manual), so
   enabling B2C later is just a matter of entering the credentials. */
function kc_wd_auto_enabled(PDO $pdo): bool {
    return !kc_daraja_missing(kc_daraja_config($pdo), 'b2c');
}

/* Legacy manual request (admin approves and deducts on completion, exactly
   as before), with the same duplicate/in-flight protection as automatic
   payouts. The balance is checked but not reserved — unchanged behaviour. */
function kc_wd_create_manual(PDO $pdo, array $p): array {
    $creatorId = (int)$p['creator_id'];
    $total     = round((float)$p['total'], 2);
    $pdo->beginTransaction();
    try {
        $bal = $pdo->prepare("SELECT amount FROM my_balance WHERE creator_id = ? FOR UPDATE");
        $bal->execute([$creatorId]);
        $balance = $bal->fetchColumn();

        $dup = $pdo->prepare("SELECT id FROM withdrawal_requests WHERE idempotency_key = ? LIMIT 1");
        $dup->execute([$p['idem_key']]);
        if ($existing = $dup->fetchColumn()) {
            $pdo->commit();
            return ['ok' => true, 'id' => (int)$existing, 'duplicate' => true];
        }
        $inflight = $pdo->prepare("SELECT COUNT(*) FROM withdrawal_requests WHERE creator_id = ? AND campaign_id IS NULL AND status IN ('pending','processing')");
        $inflight->execute([$creatorId]);
        if ((int)$inflight->fetchColumn() > 0) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'You already have a withdrawal in progress. Please wait for it to complete.'];
        }
        if ($balance === false || (float)$balance < $total) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Insufficient balance.'];
        }
        $pdo->prepare("
            INSERT INTO withdrawal_requests
                (creator_id, mpesa_number, amount, charge, total_amount, status, requested_at, notes,
                 payout_method, idempotency_key, creator_username)
            VALUES (?, ?, ?, ?, ?, 'pending', NOW(), ?, 'manual', ?, ?)
        ")->execute([
            $creatorId, kc_encrypt($p['phone_display']), $p['net'], $p['charge'], $total, kc_encrypt($p['notes']),
            $p['idem_key'], substr((string)$p['username'], 0, 100),
        ]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        return ['ok' => true, 'id' => $id, 'duplicate' => false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        kc_payment_log('withdrawals', 'Manual request failed for creator ' . $creatorId . ': ' . get_class($e));
        return ['ok' => false, 'error' => 'Could not create the withdrawal. Please try again.'];
    }
}

/* Step 2: send the B2C request. Only the caller that flips pending →
   processing sends anything, so a repeated call can never pay twice. */
function kc_wd_dispatch(PDO $pdo, int $id): void {
    $claim = $pdo->prepare("UPDATE withdrawal_requests SET status = 'processing' WHERE id = ? AND status = 'pending' AND payout_method = 'b2c'");
    $claim->execute([$id]);
    if ($claim->rowCount() !== 1) return;

    $stmt = $pdo->prepare("SELECT reference, amount, mpesa_number, creator_username FROM withdrawal_requests WHERE id = ?");
    $stmt->execute([$id]);
    $row   = $stmt->fetch(PDO::FETCH_ASSOC);
    $phone = kc_normalize_phone(kc_decrypt($row['mpesa_number']));
    if (!$phone) {
        kc_wd_mark_failed($pdo, $id, 'Invalid payout phone number', null, null);
        return;
    }

    $res = kc_daraja_b2c_pay($pdo, $phone, (float)$row['amount'], $row['reference'],
        'Keychele withdrawal ' . $row['reference'], 'Creator withdrawal');

    if ($res['state'] === 'accepted') {
        // The result callback can beat this UPDATE; don't overwrite its data.
        $pdo->prepare("UPDATE withdrawal_requests SET conversation_id = ?, provider_response = IF(status = 'processing', ?, provider_response) WHERE id = ?")
            ->execute([$res['conversation_id'], $res['raw'], $id]);
    } elseif ($res['state'] === 'rejected') {
        kc_wd_mark_failed($pdo, $id, 'Payout not accepted: ' . $res['error'], null, $res['raw'] ?? null);
    } else {
        $pdo->prepare("UPDATE withdrawal_requests SET failure_reason = ?, provider_response = ? WHERE id = ? AND status = 'processing'")
            ->execute(['Awaiting M-Pesa confirmation (' . $res['error'] . ')', $res['raw'] ?? null, $id]);
    }
}

/* Final success. Returns true only for the call that actually made the
   transition (so the caller sends the SMS exactly once). */
function kc_wd_mark_success(PDO $pdo, int $id, string $receipt, ?string $raw): bool {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT status FROM withdrawal_requests WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $status = $stmt->fetchColumn();

        if ($receipt !== '') {
            $clash = $pdo->prepare("SELECT id FROM withdrawal_requests WHERE transaction_code = ? AND id <> ? LIMIT 1");
            $clash->execute([$receipt, $id]);
            if ($clash->fetchColumn()) {
                $pdo->rollBack();
                kc_payment_log('withdrawals', "CRITICAL #$id: receipt $receipt already used by another withdrawal — not applied");
                return false;
            }
        }

        if ($status === 'failed') {
            // Money went out after this row was already refunded (e.g. an
            // admin reconciled it as failed). Flag loudly; never auto-debit.
            $pdo->prepare("UPDATE withdrawal_requests SET failure_reason = ?, provider_response = ? WHERE id = ?")
                ->execute(['LATE SUCCESS — M-Pesa paid (receipt ' . $receipt . ') after refund. Reconcile manually.', $raw, $id]);
            $pdo->commit();
            kc_payment_log('withdrawals', "CRITICAL #$id: success result $receipt arrived after row was marked failed");
            return false;
        }
        if (!in_array($status, ['pending', 'processing'], true)) {
            $pdo->commit();
            return false;
        }
        $pdo->prepare("
            UPDATE withdrawal_requests
            SET status = 'successful', transaction_code = ?, provider_result_code = '0', provider_response = ?,
                failure_reason = NULL, balance_state = 'finalized', processed_at = NOW(), completed_at = NOW()
            WHERE id = ?
        ")->execute([$receipt !== '' ? $receipt : null, $raw, $id]);
        $pdo->commit();
        kc_payment_log('withdrawals', "Successful #$id receipt $receipt");
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/* Final failure: mark failed and put the reserved funds back, exactly once. */
function kc_wd_mark_failed(PDO $pdo, int $id, string $reason, ?string $resultCode, ?string $raw): bool {
    $peek = $pdo->prepare("SELECT creator_id FROM withdrawal_requests WHERE id = ?");
    $peek->execute([$id]);
    $creatorId = $peek->fetchColumn();
    if ($creatorId === false) return false;

    $pdo->beginTransaction();
    try {
        // Same lock order as kc_wd_create_and_reserve: balance, then request.
        $pdo->prepare("SELECT amount FROM my_balance WHERE creator_id = ? FOR UPDATE")->execute([$creatorId]);
        $stmt = $pdo->prepare("SELECT status, balance_state, total_amount FROM withdrawal_requests WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !in_array($row['status'], ['pending', 'processing'], true)) {
            $pdo->commit();
            return false;
        }
        $release = $row['balance_state'] === 'reserved';
        $pdo->prepare("
            UPDATE withdrawal_requests
            SET status = 'failed', failure_reason = ?, provider_result_code = ?, provider_response = COALESCE(?, provider_response),
                balance_state = ?, processed_at = NOW(), completed_at = NOW()
            WHERE id = ?
        ")->execute([substr($reason, 0, 255), $resultCode, $raw, $release ? 'released' : $row['balance_state'], $id]);
        if ($release) {
            $pdo->prepare("UPDATE my_balance SET amount = amount + ? WHERE creator_id = ?")
                ->execute([$row['total_amount'], $creatorId]);
        }
        $pdo->commit();
        kc_payment_log('withdrawals', "Failed #$id" . ($release ? " (released {$row['total_amount']})" : '') . ": $reason");
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/* Success SMS, at most once per withdrawal. An SMS failure is recorded but
   never touches the (already successful) withdrawal. */
function kc_wd_send_success_sms(PDO $pdo, int $id): void {
    $claim = $pdo->prepare("UPDATE withdrawal_requests SET sms_status = 'sending' WHERE id = ? AND status = 'successful' AND sms_status IS NULL");
    $claim->execute([$id]);
    if ($claim->rowCount() !== 1) return;

    $stmt = $pdo->prepare("SELECT amount, transaction_code, mpesa_number FROM withdrawal_requests WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $msg = 'Your withdrawal of KSh ' . kc_wd_format_kes((float)$row['amount']) . ' was successful. Ref Code: '
         . ($row['transaction_code'] ?: '-') . '.';
    try {
        $res = kc_talksasa_send($pdo, (string)kc_decrypt($row['mpesa_number']), $msg);
    } catch (Throwable $e) {
        $res = ['ok' => false, 'reason' => 'Exception: ' . get_class($e)];
    }
    $pdo->prepare("UPDATE withdrawal_requests SET sms_status = ?, sms_error = ?, sms_sent_at = ? WHERE id = ?")
        ->execute([$res['ok'] ? 'sent' : 'failed', $res['ok'] ? null : substr($res['reason'], 0, 255), $res['ok'] ? date('Y-m-d H:i:s') : null, $id]);
    if (!$res['ok']) kc_payment_log('withdrawals', "SMS failed for #$id: {$res['reason']}");
}

/* What the creator's browser is allowed to see about one of their own
   withdrawals. Provider internals and raw failure reasons stay server-side. */
function kc_wd_public_status(PDO $pdo, int $creatorId, string $reference): ?array {
    $stmt = $pdo->prepare("
        SELECT reference, status, amount, charge, total_amount, transaction_code, requested_at, completed_at
        FROM withdrawal_requests WHERE creator_id = ? AND reference = ? AND payout_method = 'b2c' LIMIT 1
    ");
    $stmt->execute([$creatorId, $reference]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    return [
        'reference'      => $row['reference'],
        'status'         => $row['status'],
        'amount'         => (float)$row['amount'],
        'amount_display' => kc_wd_format_kes((float)$row['amount']),
        'charge'         => (float)$row['charge'],
        'total'          => (float)$row['total_amount'],
        'transaction_id' => $row['status'] === 'successful' ? $row['transaction_code'] : null,
        'message'        => match ($row['status']) {
            'successful' => 'Your withdrawal of KSh ' . kc_wd_format_kes((float)$row['amount']) . ' was successful.',
            'failed'     => 'The withdrawal could not be completed. KES ' . number_format((float)$row['total_amount'], 2) . ' has been returned to your balance.',
            default      => 'Your withdrawal is being processed by M-Pesa.',
        },
    ];
}
