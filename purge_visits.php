<?php
/*
 * ════════════════════════════════════════════════════════════════
 *  purge_visits.php  –  Keychele Ticket Visit Log Pruner
 * ════════════════════════════════════════════════════════════════
 *
 *  Run via cPanel Cron Jobs (recommended: daily at 2am):
 *      0 2 * * *  /usr/local/bin/php /home1/keychele/keychele.co.ke/cron/purge_visits.php
 *
 *  Or call directly via CLI:
 *      php purge_visits.php --days=60
 *
 *  Deletes ticket_page_visits rows older than PURGE_AFTER_DAYS.
 *  Logs every run to purge_visits.log in the same directory.
 *
 * ════════════════════════════════════════════════════════════════
 */

// ── Only allow CLI or localhost web calls ─────────────────────────
$is_cli = (PHP_SAPI === 'cli');
$is_web = isset($_SERVER['REMOTE_ADDR']);

if ($is_web) {
    $allowed_ips = ['127.0.0.1', '::1'];
    if (!in_array($_SERVER['REMOTE_ADDR'], $allowed_ips, true)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

// ── Config ────────────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_db_name');       // ← update
define('DB_USER', 'your_db_user');       // ← update
define('DB_PASS', 'your_db_password');   // ← update

// How many days of visits to keep
define('PURGE_AFTER_DAYS', 90);

// Max rows deleted per run (prevents long locks on large tables)
define('BATCH_LIMIT', 5000);

// Log file (same directory as this script)
define('LOG_FILE', __DIR__ . '/purge_visits.log');

// ── Parse optional CLI override: php purge_visits.php --days=60 ──
$days = PURGE_AFTER_DAYS;
if ($is_cli) {
    $opts = getopt('', ['days:']);
    if (isset($opts['days']) && is_numeric($opts['days'])) {
        $days = max(1, (int)$opts['days']);
    }
}

// ── Logger ────────────────────────────────────────────────────────
function purge_log(string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    if (PHP_SAPI === 'cli') echo $line;
}

// ── DB connect ────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    purge_log('DB connect failed: ' . $e->getMessage());
    exit(1);
}

// ── Count rows to be purged (for the log) ────────────────────────
try {
    $count_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM ticket_page_visits
        WHERE visited_at < NOW() - INTERVAL ? DAY
    ");
    $count_stmt->execute([$days]);
    $total_eligible = (int)$count_stmt->fetchColumn();
} catch (PDOException $e) {
    purge_log('Count query failed: ' . $e->getMessage());
    exit(1);
}

if ($total_eligible === 0) {
    purge_log("Nothing to purge (threshold: {$days} days). Table is clean.");
    exit(0);
}

purge_log("Starting purge — {$total_eligible} row(s) older than {$days} days found.");

// ── Batch delete loop (avoids locking the table for too long) ─────
$total_deleted = 0;

try {
    $del_stmt = $pdo->prepare("
        DELETE FROM ticket_page_visits
        WHERE visited_at < NOW() - INTERVAL ? DAY
        LIMIT ?
    ");

    do {
        $del_stmt->execute([$days, BATCH_LIMIT]);
        $deleted = $del_stmt->rowCount();
        $total_deleted += $deleted;

        if ($deleted > 0) {
            purge_log("  Batch: deleted {$deleted} row(s). Running total: {$total_deleted}.");
        }

        // Brief pause between batches to ease DB load
        if ($deleted === BATCH_LIMIT) usleep(250000); // 250ms

    } while ($deleted === BATCH_LIMIT);

} catch (PDOException $e) {
    purge_log("Delete failed after {$total_deleted} row(s): " . $e->getMessage());
    exit(1);
}

purge_log("Purge complete. Total deleted: {$total_deleted} row(s). Threshold: {$days} days.");
exit(0);
