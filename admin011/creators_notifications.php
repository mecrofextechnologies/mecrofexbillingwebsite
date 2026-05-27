<?php
// creators_notifications.php
/*
 ══════════════════════════════════════════════════════════════════
  REQUIRED DB TABLES — run once, then remove this block
 ══════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS notification_rules (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(120) NOT NULL,
    description     TEXT,
    trigger_type    ENUM(
                      'first_purchase',
                      'nth_purchase',
                      'no_purchase_after_days',
                      'ticket_created_no_purchase',
                      'manual_blast',
                      'pre_event_reminder',
                      'post_event_followup',
                      'low_stock_alert',
                      'milestone_reached'
                    ) NOT NULL,
    trigger_value   INT DEFAULT NULL COMMENT 'n for nth_purchase; days for no_purchase/pre_event; % for milestone',
    channel         SET('email','sms') NOT NULL DEFAULT 'email',
    target_type     ENUM('all_creators','specific_creator','specific_ticket','tag') NOT NULL DEFAULT 'all_creators',
    target_ids      TEXT DEFAULT NULL COMMENT 'JSON array of creator/ticket IDs or tag names',
    subject_tpl     VARCHAR(255) DEFAULT NULL COMMENT 'Email subject template',
    message_tpl     TEXT NOT NULL COMMENT 'Body template with {{variables}}',
    delay_hours     INT DEFAULT 0 COMMENT 'Send X hours after trigger fires',
    status          ENUM('active','paused','draft') NOT NULL DEFAULT 'draft',
    send_once_per   ENUM('ticket','creator','global') DEFAULT 'ticket' COMMENT 'Dedup scope',
    created_by      INT DEFAULT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notification_logs (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    rule_id         INT DEFAULT NULL,
    creator_id      INT DEFAULT NULL,
    ticket_id       INT DEFAULT NULL,
    channel         ENUM('email','sms') NOT NULL,
    recipient       VARCHAR(255) NOT NULL COMMENT 'email address or phone',
    subject         VARCHAR(255) DEFAULT NULL,
    message         TEXT,
    status          ENUM('sent','failed','pending','skipped') NOT NULL DEFAULT 'pending',
    error_info      TEXT DEFAULT NULL,
    sent_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
    meta            JSON DEFAULT NULL COMMENT 'extra context snapshot'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notification_templates (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    category    VARCHAR(60) DEFAULT NULL,
    subject     VARCHAR(255) DEFAULT NULL,
    body        TEXT NOT NULL,
    is_system   TINYINT(1) DEFAULT 0,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed system templates
INSERT IGNORE INTO notification_templates (name, category, subject, body, is_system) VALUES
('First Ticket Sale 🎉','milestone',
 '🎉 First sale! {{ticket_title}} just sold its first ticket',
 "Hi {{creator_name}},\n\nCongratulations! Your first ticket for **{{ticket_title}}** just sold.\n\n📍 Venue: {{venue}}\n📅 Date: {{event_date}}\n💰 Sale amount: KSh {{sale_amount}}\n🎟 Remaining: {{remaining_tickets}} tickets left\n\nShare your ticket link to keep the momentum going:\n👉 {{ticket_url}}\n\nKeep it up!\nKeychele Team",
 1),
('No Sales Reminder','reminder',
 '⏰ Your ticket {{ticket_title}} has no sales yet — time to share!',
 "Hi {{creator_name}},\n\nIt's been {{days_since_created}} day(s) since you created **{{ticket_title}}** and there are no sales yet.\n\nDon't worry — sharing is the fastest way to get bookings!\n\nYour ticket link:\n👉 {{ticket_url}}\n\nShare on WhatsApp, Instagram, X, or Facebook to get your first buyers.\n\nWe're rooting for you! 🚀\nKeychele Team",
 1),
('Nth Purchase Milestone','milestone',
 '🔥 {{nth}} tickets sold for {{ticket_title}}!',
 "Hi {{creator_name}},\n\nAmazing — you just hit **{{purchase_count}} ticket sales** for {{ticket_title}}!\n\n{{remaining_tickets}} tickets still available. Share the link to sell out:\n👉 {{ticket_url}}\n\nKeep the energy going! 🎶\nKeychele Team",
 1),
('Pre-Event Countdown','reminder',
 '📅 {{ticket_title}} is in {{days_to_event}} day(s) — final push!',
 "Hi {{creator_name}},\n\nYour event **{{ticket_title}}** is coming up in {{days_to_event}} day(s)!\n\nYou've sold {{purchase_count}} tickets so far. Don't miss last-minute buyers — share your link:\n👉 {{ticket_url}}\n\nExcited for your event!\nKeychele Team",
 1),
('Low Stock Alert','alert',
 '⚠️ Only {{remaining_tickets}} tickets left for {{ticket_title}}!',
 "Hi {{creator_name}},\n\nAlmost sold out! Only **{{remaining_tickets}} tickets** remain for **{{ticket_title}}**.\n\nCreate urgency — share the link now:\n👉 {{ticket_url}}\n\nAmazing work! 🔥\nKeychele Team",
 1);
 ══════════════════════════════════════════════════════════════════
*/

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../vendor/autoload.php';
include '../msanii/includes/connect.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], [1, 2])) {
    echo '<script>window.location.href="../login.php";</script>'; exit();
}
$admin_id = $_SESSION['user_id'] ?? 0;

/* ──────────────────────────────────────────────────────────────
   HELPERS
────────────────────────────────────────────────────────────── */
function flash(string $msg, string $type = 'ok'): void {
    $_SESSION['notif_flash'] = compact('msg','type');
}
function getFlash(): ?array {
    $f = $_SESSION['notif_flash'] ?? null;
    unset($_SESSION['notif_flash']);
    return $f;
}

/* ──────────────────────────────────────────────────────────────
   VARIABLE LEGEND  (shown in UI)
────────────────────────────────────────────────────────────── */
$VAR_LEGEND = [
    '{{creator_name}}'      => "Creator's full name",
    '{{creator_email}}'     => "Creator's email address",
    '{{creator_phone}}'     => "Creator's phone number",
    '{{ticket_title}}'      => "Event/ticket title",
    '{{ticket_url}}'        => "Full ticket purchase URL",
    '{{venue}}'             => "Event venue",
    '{{event_date}}'        => "Formatted event date",
    '{{purchase_count}}'    => "Total tickets sold so far",
    '{{remaining_tickets}}' => "Tickets still available",
    '{{sale_amount}}'       => "Amount of latest sale (KSh)",
    '{{nth}}'               => "Ordinal of latest sale (1st, 2nd…)",
    '{{days_since_created}}'=> "Days since ticket was created",
    '{{days_to_event}}'     => "Days until the event",
    '{{admin_note}}'        => "Custom note typed when sending",
];

/* ──────────────────────────────────────────────────────────────
   POST HANDLERS
────────────────────────────────────────────────────────────── */
$active_tab = $_GET['tab'] ?? 'rules';

/* ── Save / Update Rule ── */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_rule'])) {
    $rule_id       = (int)($_POST['rule_id'] ?? 0);
    $name          = trim($_POST['name'] ?? '');
    $description   = trim($_POST['description'] ?? '');
    $trigger_type  = trim($_POST['trigger_type'] ?? '');
    $trigger_value = (int)($_POST['trigger_value'] ?? 0);
    $channel_arr   = $_POST['channel'] ?? ['email'];
    $channel       = implode(',', array_intersect($channel_arr, ['email','sms']));
    $target_type   = trim($_POST['target_type'] ?? 'all_creators');
    $target_ids    = trim($_POST['target_ids'] ?? '');
    $subject_tpl   = trim($_POST['subject_tpl'] ?? '');
    $message_tpl   = trim($_POST['message_tpl'] ?? '');
    $delay_hours   = (int)($_POST['delay_hours'] ?? 0);
    $status        = trim($_POST['status'] ?? 'draft');
    $send_once_per = trim($_POST['send_once_per'] ?? 'ticket');

    // Basic validation
    $errs = [];
    if (!$name)         $errs[] = 'Rule name is required.';
    if (!$trigger_type) $errs[] = 'Trigger type is required.';
    if (!$message_tpl)  $errs[] = 'Message template is required.';
    if (!$channel)      $errs[] = 'Select at least one channel.';

    if (!$errs) {
        try {
            if ($rule_id > 0) {
                $stmt = $pdo->prepare("UPDATE notification_rules SET
                    name=?,description=?,trigger_type=?,trigger_value=?,channel=?,
                    target_type=?,target_ids=?,subject_tpl=?,message_tpl=?,
                    delay_hours=?,status=?,send_once_per=?,updated_at=NOW()
                    WHERE id=?");
                $stmt->execute([$name,$description,$trigger_type,$trigger_value,$channel,
                    $target_type,$target_ids,$subject_tpl,$message_tpl,
                    $delay_hours,$status,$send_once_per,$rule_id]);
                flash("Rule updated successfully.", 'ok');
            } else {
                $stmt = $pdo->prepare("INSERT INTO notification_rules
                    (name,description,trigger_type,trigger_value,channel,
                     target_type,target_ids,subject_tpl,message_tpl,
                     delay_hours,status,send_once_per,created_by)
                    VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$name,$description,$trigger_type,$trigger_value,$channel,
                    $target_type,$target_ids,$subject_tpl,$message_tpl,
                    $delay_hours,$status,$send_once_per,$admin_id]);
                flash("Notification rule created!", 'ok');
            }
        } catch (PDOException $e) {
            flash("DB error: ".$e->getMessage(), 'err');
        }
    } else {
        flash(implode(' ', $errs), 'err');
    }
    echo '<script>window.location.href="?page=creators_notifications&tab=rules";</script>'; exit();
}

/* ── Toggle Rule Status ── */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['toggle_rule'])) {
    $rid = (int)$_POST['toggle_rule'];
    $pdo->prepare("UPDATE notification_rules SET status = CASE WHEN status='active' THEN 'paused' ELSE 'active' END WHERE id=?")->execute([$rid]);
    flash("Rule status toggled.", 'ok');
    echo '<script>window.location.href="?page=creators_notifications&tab=rules";</script>'; exit();
}

/* ── Delete Rule ── */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_rule'])) {
    $rid = (int)$_POST['delete_rule'];
    $pdo->prepare("DELETE FROM notification_rules WHERE id=?")->execute([$rid]);
    flash("Rule deleted.", 'ok');
    echo '<script>window.location.href="?page=creators_notifications&tab=rules";</script>'; exit();
}

/* ── Quick Send ── */
$qs_results = [];
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['quick_send'])) {
    $active_tab    = 'send-now';
    $qs_channel_arr= $_POST['qs_channel'] ?? ['email'];
    $qs_target     = trim($_POST['qs_target'] ?? 'all');
    $qs_creator_ids= $_POST['qs_creator_ids'] ?? [];
    $qs_ticket_id  = (int)($_POST['qs_ticket_id'] ?? 0);
    $qs_subject    = trim($_POST['qs_subject'] ?? '(No subject)');
    $qs_message    = trim($_POST['qs_message'] ?? '');
    $qs_admin_note = trim($_POST['qs_admin_note'] ?? '');

    if (!$qs_message) {
        flash("Message body is required.", 'err');
    } else {
        // Build recipient list
        $creator_rows = [];
        if ($qs_target === 'all') {
            $creator_rows = $pdo->query("SELECT id, full_name, email, phone FROM creators ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($qs_target === 'specific' && !empty($qs_creator_ids)) {
            $in = implode(',', array_map('intval', $qs_creator_ids));
            $creator_rows = $pdo->query("SELECT id, full_name, email, phone FROM creators WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($qs_target === 'ticket' && $qs_ticket_id > 0) {
            $rstmt = $pdo->prepare("SELECT c.id, c.full_name, c.email, c.phone FROM creators c JOIN tickets t ON t.creator_id=c.id WHERE t.id=?");
            $rstmt->execute([$qs_ticket_id]);
            $creator_rows = $rstmt->fetchAll(PDO::FETCH_ASSOC);
        }

        foreach ($creator_rows as $cr) {
            // Build context
            $ctx = [
                '{{creator_name}}'      => $cr['full_name'],
                '{{creator_email}}'     => $cr['email'],
                '{{creator_phone}}'     => $cr['phone'],
                '{{admin_note}}'        => $qs_admin_note,
                '{{ticket_title}}'      => '',
                '{{ticket_url}}'        => '',
                '{{venue}}'             => '',
                '{{event_date}}'        => '',
                '{{purchase_count}}'    => '0',
                '{{remaining_tickets}}'=> '',
                '{{sale_amount}}'       => '',
                '{{nth}}'               => '',
                '{{days_since_created}}'=>'',
                '{{days_to_event}}'     => '',
            ];
            // Enrich with ticket data if target = specific ticket
            if ($qs_ticket_id > 0) {
                $tstmt = $pdo->prepare("SELECT title,venue,start_date FROM tickets WHERE id=?");
                $tstmt->execute([$qs_ticket_id]);
                $tk = $tstmt->fetch(PDO::FETCH_ASSOC);
                if ($tk) {
                    $ctx['{{ticket_title}}'] = $tk['title'];
                    $ctx['{{ticket_url}}']   = "https://keychele.co.ke/ticket/".$qs_ticket_id;
                    $ctx['{{venue}}']        = $tk['venue'];
                    $ctx['{{event_date}}']   = $tk['start_date'] ? date('d M Y', strtotime($tk['start_date'])) : 'TBD';
                    $pc = $pdo->prepare("SELECT COUNT(*) FROM ticket_purchases WHERE ticket_id=? AND status='completed'");
                    $pc->execute([$qs_ticket_id]);
                    $ctx['{{purchase_count}}'] = (string)$pc->fetchColumn();
                }
            }
            $body    = str_replace(array_keys($ctx), array_values($ctx), $qs_message);
            $subject = str_replace(array_keys($ctx), array_values($ctx), $qs_subject);

            $sent_email = false; $sent_sms = false;

            // ── Email
            if (in_array('email', $qs_channel_arr) && !empty($cr['email'])) {
                try {
                    $mail = new PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host       = 'mail.keychele.co.ke';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'notifications@keychele.co.ke';
                    $mail->Password   = 'King20241064.';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;
                    $mail->SMTPOptions = ['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true]];
                    $mail->setFrom('notifications@keychele.co.ke','Keychele Platform');
                    $mail->addAddress($cr['email'], $cr['full_name']);
                    $mail->isHTML(true);
                    $mail->CharSet = 'UTF-8';
                    $mail->Subject = $subject;
                    // HTML wrap
                    $html_body = nl2br(htmlspecialchars($body));
                    $mail->Body = "
                    <div style='font-family:Arial,sans-serif;max-width:580px;margin:0 auto;background:#fff;border-radius:14px;border:1px solid #E8E4DF;overflow:hidden;'>
                      <div style='background:linear-gradient(135deg,#052e64,#0a4494);padding:32px;text-align:center;'>
                        <h2 style='color:#fff;margin:0;font-size:20px;'>".htmlspecialchars($subject)."</h2>
                      </div>
                      <div style='padding:28px 32px;font-size:14px;line-height:1.75;color:#374151;'>".
                        $html_body.
                      "</div>
                      <div style='background:#f9fafb;border-top:1px solid #e5e7eb;padding:16px 32px;text-align:center;font-size:11px;color:#9ca3af;'>
                        © ".date('Y')." Keychele &nbsp;·&nbsp; This is an automated message from your admin team.
                      </div>
                    </div>";
                    $mail->AltBody = $body;
                    $mail->send();
                    $sent_email = true;
                    $pdo->prepare("INSERT INTO notification_logs (rule_id,creator_id,ticket_id,channel,recipient,subject,message,status) VALUES(NULL,?,?,?,?,?,?,'sent')")
                        ->execute([$cr['id'],$qs_ticket_id?:null,'email',$cr['email'],$subject,$body]);
                } catch (Exception $e) {
                    $pdo->prepare("INSERT INTO notification_logs (rule_id,creator_id,ticket_id,channel,recipient,subject,message,status,error_info) VALUES(NULL,?,?,?,?,?,?,'failed',?)")
                        ->execute([$cr['id'],$qs_ticket_id?:null,'email',$cr['email'],$subject,$body,$mail->ErrorInfo]);
                }
            }
            // ── SMS (stub — integrate with Africa's Talking / Twilio)
            if (in_array('sms', $qs_channel_arr) && !empty($cr['phone'])) {
                // TODO: wire up real SMS gateway
                $sms_status = 'pending'; // placeholder
                $pdo->prepare("INSERT INTO notification_logs (rule_id,creator_id,ticket_id,channel,recipient,message,status,error_info) VALUES(NULL,?,?,?,?,?,'pending','SMS gateway not yet configured')")
                    ->execute([$cr['id'],$qs_ticket_id?:null,'sms',$cr['phone'],$body]);
                $sent_sms = true; // optimistic for UI
            }

            $qs_results[] = [
                'creator' => $cr['full_name'],
                'email'   => $cr['email'],
                'phone'   => $cr['phone'],
                'email_ok'=> $sent_email,
                'sms_ok'  => $sent_sms,
            ];
        }
        if (empty($creator_rows)) flash("No recipients matched the selected target.", 'err');
        else flash("Notification sent to ".count($qs_results)." creator(s).", 'ok');
    }
}

/* ── Save Template ── */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_template'])) {
    $tname   = trim($_POST['tpl_name'] ?? '');
    $tcat    = trim($_POST['tpl_category'] ?? '');
    $tsubj   = trim($_POST['tpl_subject'] ?? '');
    $tbody   = trim($_POST['tpl_body'] ?? '');
    $tid     = (int)($_POST['tpl_id'] ?? 0);
    if ($tname && $tbody) {
        if ($tid > 0) {
            $pdo->prepare("UPDATE notification_templates SET name=?,category=?,subject=?,body=? WHERE id=? AND is_system=0")
                ->execute([$tname,$tcat,$tsubj,$tbody,$tid]);
            flash("Template updated.", 'ok');
        } else {
            $pdo->prepare("INSERT INTO notification_templates (name,category,subject,body) VALUES(?,?,?,?)")
                ->execute([$tname,$tcat,$tsubj,$tbody]);
            flash("Template saved.", 'ok');
        }
    } else flash("Name and body are required.", 'err');
    echo '<script>window.location.href="?page=creators_notifications&tab=templates";</script>'; exit();
}
/* ── Delete Template ── */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_template'])) {
    $pdo->prepare("DELETE FROM notification_templates WHERE id=? AND is_system=0")->execute([(int)$_POST['delete_template']]);
    flash("Template deleted.", 'ok');
    echo '<script>window.location.href="?page=creators_notifications&tab=templates";</script>'; exit();
}

/* ──────────────────────────────────────────────────────────────
   DATA FETCH
────────────────────────────────────────────────────────────── */
try {
    $rules     = $pdo->query("SELECT * FROM notification_rules ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $templates = $pdo->query("SELECT * FROM notification_templates ORDER BY is_system DESC, name")->fetchAll(PDO::FETCH_ASSOC);
    $logs      = $pdo->query("
        SELECT nl.*, c.full_name AS creator_name, t.title AS ticket_title
        FROM notification_logs nl
        LEFT JOIN creators c ON c.id = nl.creator_id
        LEFT JOIN tickets t  ON t.id  = nl.ticket_id
        ORDER BY nl.sent_at DESC LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);
    $all_creators = $pdo->query("SELECT id, full_name, username FROM creators ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    $all_tickets  = $pdo->query("SELECT t.id, t.title, t.start_date, c.full_name AS creator_name FROM tickets t LEFT JOIN creators c ON c.id=t.creator_id ORDER BY t.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

    // Log stats
    $log_sent   = $pdo->query("SELECT COUNT(*) FROM notification_logs WHERE status='sent'")->fetchColumn();
    $log_failed = $pdo->query("SELECT COUNT(*) FROM notification_logs WHERE status='failed'")->fetchColumn();
    $log_pending= $pdo->query("SELECT COUNT(*) FROM notification_logs WHERE status='pending'")->fetchColumn();
    $rules_active= $pdo->query("SELECT COUNT(*) FROM notification_rules WHERE status='active'")->fetchColumn();
} catch (PDOException $e) {
    $db_err = $e->getMessage();
}

$flash = getFlash();

/* ── Edit-rule pre-fill ── */
$editing_rule = null;
if (isset($_GET['edit_rule'])) {
    $er = $pdo->prepare("SELECT * FROM notification_rules WHERE id=?");
    $er->execute([(int)$_GET['edit_rule']]);
    $editing_rule = $er->fetch(PDO::FETCH_ASSOC);
    $active_tab = 'new-rule';
}

$TRIGGER_LABELS = [
    'first_purchase'           => '1st Ticket Purchase',
    'nth_purchase'             => 'Nth Purchase Milestone',
    'no_purchase_after_days'   => 'No Sales After X Days',
    'ticket_created_no_purchase'=> 'Ticket Created — Still No Sales',
    'manual_blast'             => 'Manual Blast',
    'pre_event_reminder'       => 'Pre-Event Countdown',
    'post_event_followup'      => 'Post-Event Follow-up',
    'low_stock_alert'          => 'Low Stock Alert',
    'milestone_reached'        => 'Sales Milestone %',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Creator Notifications • Keychele Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
  --crimson:#C8102E;--crimson-deep:#9B0B22;--crimson-light:#FF3356;
  --ink:#0D0D0D;--ink-soft:#1A1A2E;--slate:#64748B;--mist:#F5F4F1;
  --white:#FFFFFF;--border:#E8E4DF;--success:#059669;--warning:#D97706;
  --navy:#052e64;--navy-l:#0a4494;--info:#0EA5E9;
  --font-d:'Syne',sans-serif;--font-b:'DM Sans',sans-serif;
  --r:14px;--r-sm:9px;
  --shadow:0 8px 40px rgba(13,13,13,0.10),0 2px 8px rgba(13,13,13,0.05);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{font-family:var(--font-b);background:var(--mist);color:var(--ink);-webkit-font-smoothing:antialiased}

/* HERO */
.hero{background:linear-gradient(135deg,#0a0a1a 0%,#1a0a30 40%,#052e64 80%,#0a4494 100%);padding:2.6rem 0 6.5rem;position:relative;overflow:hidden;text-align:center}
.hero::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse at 70% 30%,rgba(200,16,46,0.18),transparent 60%)}
.hero::after{content:'';position:absolute;bottom:-1px;left:0;right:0;height:68px;background:var(--mist);clip-path:ellipse(58% 100% at 50% 100%)}
.hero-grid{position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,0.03) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,0.03) 1px,transparent 1px);background-size:48px 48px}
.hero-inner{position:relative;z-index:2}
.hero-badge{display:inline-flex;align-items:center;gap:7px;background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.12);border-radius:100px;padding:6px 16px;font-size:0.72rem;font-weight:700;color:rgba(255,255,255,0.6);letter-spacing:0.08em;text-transform:uppercase;margin-bottom:1rem}
.hero-badge i{color:#4ADE80;font-size:0.6rem}
.hero-title{font-family:var(--font-d);font-weight:800;font-size:clamp(1.6rem,4vw,2.2rem);color:#fff;letter-spacing:-0.03em;margin-bottom:0.4rem}
.hero-sub{font-size:0.85rem;color:rgba(255,255,255,0.42)}
.hero-chips{display:flex;justify-content:center;flex-wrap:wrap;gap:0.75rem;margin-top:1.25rem}
.h-chip{display:flex;align-items:center;gap:8px;background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.1);border-radius:10px;padding:0.55rem 1rem;font-size:0.78rem;color:rgba(255,255,255,0.7)}
.h-chip strong{color:#fff;font-family:var(--font-d);font-size:0.95rem}
.h-chip i{font-size:0.72rem;color:rgba(255,255,255,0.35)}

.wrap{max-width:1100px;margin:0 auto;padding:0 1.25rem 3rem}

/* FLASH */
.k-alert{display:flex;align-items:flex-start;gap:9px;padding:.85rem 1rem;border-radius:var(--r-sm);font-size:.81rem;margin-bottom:1.1rem;line-height:1.55}
.k-alert i{margin-top:2px;flex-shrink:0}
.k-ok{background:rgba(5,150,105,.08);border:1px solid rgba(5,150,105,.22);color:#065F46}
.k-err{background:rgba(200,16,46,.07);border:1px solid rgba(200,16,46,.18);color:#7F1D1D}
.k-info{background:rgba(14,165,233,.07);border:1px solid rgba(14,165,233,.2);color:#0369a1}

/* MAIN CARD */
.main-card{background:var(--white);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--shadow);overflow:hidden;margin-top:-3rem;position:relative;z-index:10}

/* TABS */
.tab-nav{display:flex;border-bottom:1px solid var(--border);padding:0 1.5rem;overflow-x:auto;scrollbar-width:none}
.tab-nav::-webkit-scrollbar{display:none}
.tab-btn{display:flex;align-items:center;gap:6px;padding:.9rem 1rem;font-family:var(--font-b);font-size:.79rem;font-weight:600;color:var(--slate);background:none;border:none;border-bottom:2.5px solid transparent;margin-bottom:-1px;cursor:pointer;white-space:nowrap;transition:all .18s}
.tab-btn:hover{color:var(--ink)}
.tab-btn.active{color:var(--navy);border-bottom-color:var(--navy)}
.tab-btn i{font-size:.72rem}
.tab-btn .cnt{background:var(--mist);border:1px solid var(--border);border-radius:100px;font-size:.62rem;font-weight:700;padding:1px 6px;color:var(--slate);min-width:18px;text-align:center}
.tab-btn.active .cnt{background:rgba(5,46,100,.1);border-color:rgba(5,46,100,.2);color:var(--navy)}

/* PANE */
.tc-area{padding:1.75rem}
.tab-pane{display:none;animation:ti .22s ease both}
.tab-pane.active{display:block}
@keyframes ti{from{opacity:0;transform:translateY(7px)}to{opacity:1;transform:translateY(0)}}

/* SECTION HEAD */
.sh{display:flex;align-items:center;gap:10px;margin-bottom:1.35rem;padding-bottom:.85rem;border-bottom:1px solid var(--border)}
.sh-icon{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.8rem;flex-shrink:0}
.sh h5{font-family:var(--font-d);font-weight:700;font-size:.95rem;color:var(--ink)}
.sh p{font-size:.75rem;color:var(--slate)}
.ic-r{background:rgba(200,16,46,.1);color:var(--crimson)}
.ic-b{background:rgba(59,130,246,.1);color:#3B82F6}
.ic-g{background:rgba(5,150,105,.1);color:var(--success)}
.ic-a{background:rgba(217,119,6,.1);color:var(--warning)}
.ic-p{background:rgba(5,46,100,.1);color:var(--navy)}
.ic-i{background:rgba(14,165,233,.1);color:var(--info)}

/* RULES TABLE */
.t-wrap{border:1px solid var(--border);border-radius:var(--r-sm);overflow:hidden;overflow-x:auto}
table{width:100%;border-collapse:separate;border-spacing:0;font-size:.81rem}
thead th{background:var(--mist);padding:.65rem .85rem;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--slate);border-bottom:1px solid var(--border);white-space:nowrap}
tbody tr:hover td{background:#FAFAF9}
tbody td{padding:.72rem .85rem;border-bottom:1px solid #F1EDE8;color:#374151;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}

/* BADGES */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:100px;font-size:.67rem;font-weight:700;letter-spacing:.02em}
.bg-ok{background:rgba(5,150,105,.1);color:var(--success)}
.bg-err{background:rgba(200,16,46,.09);color:var(--crimson)}
.bg-w{background:rgba(217,119,6,.1);color:var(--warning)}
.bg-sl{background:rgba(100,116,139,.1);color:var(--slate)}
.bg-b{background:rgba(59,130,246,.1);color:#3B82F6}
.bg-p{background:rgba(5,46,100,.1);color:var(--navy)}
.bg-i{background:rgba(14,165,233,.1);color:var(--info)}

/* EMPTY */
.empty{text-align:center;padding:3rem 1rem;color:var(--slate)}
.empty-ic{width:52px;height:52px;border-radius:14px;background:var(--mist);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;margin:0 auto .9rem;font-size:1.3rem;color:#CBD5E1}
.empty p{font-size:.83rem}

/* FORM */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
@media(max-width:640px){.form-grid{grid-template-columns:1fr}}
.form-grid.col3{grid-template-columns:1fr 1fr 1fr}
@media(max-width:720px){.form-grid.col3{grid-template-columns:1fr 1fr}}
.f-group{display:flex;flex-direction:column;gap:6px}
.f-group.full{grid-column:1/-1}
.f-group label{font-size:.71rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.04em}
.f-group label span{color:var(--crimson);margin-left:2px}
.f-group input,.f-group select,.f-group textarea{width:100%;padding:0 13px;border:1.5px solid var(--border);border-radius:var(--r-sm);font-family:var(--font-b);font-size:.88rem;color:var(--ink);background:var(--mist);outline:none;transition:all .18s;appearance:none;-webkit-appearance:none}
.f-group input,.f-group select{height:44px}
.f-group textarea{padding:10px 13px;resize:vertical;min-height:120px;line-height:1.6}
.f-group input:focus,.f-group select:focus,.f-group textarea:focus{border-color:var(--navy);background:#fff;box-shadow:0 0 0 3px rgba(5,46,100,.08)}
.f-hint{font-size:.71rem;color:var(--slate);line-height:1.5}
.f-section{font-family:var(--font-d);font-weight:700;font-size:.78rem;color:var(--slate);text-transform:uppercase;letter-spacing:.07em;padding:.5rem 0 .3rem;border-bottom:1px solid var(--border);margin-bottom:.1rem;grid-column:1/-1}

/* CHECKBOX GROUP */
.chk-group{display:flex;flex-wrap:wrap;gap:.65rem}
.chk-pill{display:flex;align-items:center;gap:7px;padding:.55rem .95rem;border:1.5px solid var(--border);border-radius:9px;background:var(--mist);cursor:pointer;transition:all .18s;font-size:.82rem;font-weight:500;color:var(--slate)}
.chk-pill:hover{border-color:#CBD5E1;color:var(--ink)}
.chk-pill input{accent-color:var(--navy);width:14px;height:14px;cursor:pointer}
.chk-pill.checked{border-color:var(--navy);background:rgba(5,46,100,.06);color:var(--navy);font-weight:600}

/* RADIO CARDS */
.radio-cards{display:flex;flex-wrap:wrap;gap:.6rem}
.r-card{display:flex;align-items:flex-start;gap:9px;padding:.75rem 1rem;border:1.5px solid var(--border);border-radius:10px;background:var(--mist);cursor:pointer;transition:all .18s;flex:1;min-width:140px}
.r-card:hover{border-color:#CBD5E1}
.r-card input{accent-color:var(--navy);margin-top:2px;flex-shrink:0}
.r-card.checked{border-color:var(--navy);background:rgba(5,46,100,.05)}
.r-card-ico{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:.75rem;flex-shrink:0}
.r-card-lbl{font-size:.82rem;font-weight:600;color:var(--ink)}
.r-card-sub{font-size:.7rem;color:var(--slate);margin-top:2px}

/* VARIABLE LEGEND */
.var-legend{background:#f8faff;border:1px solid rgba(5,46,100,.12);border-radius:var(--r-sm);padding:1rem}
.var-legend h6{font-size:.7rem;font-weight:700;color:var(--navy);text-transform:uppercase;letter-spacing:.07em;margin-bottom:.65rem}
.var-grid{display:grid;grid-template-columns:1fr 1fr;gap:5px}
@media(max-width:600px){.var-grid{grid-template-columns:1fr}}
.var-item{display:flex;align-items:center;gap:7px;font-size:.72rem}
.var-tag{background:rgba(5,46,100,.08);border:1px solid rgba(5,46,100,.15);border-radius:5px;padding:2px 7px;font-family:monospace;font-size:.7rem;color:var(--navy);cursor:pointer;white-space:nowrap;transition:background .15s}
.var-tag:hover{background:rgba(5,46,100,.15)}
.var-desc{color:var(--slate)}

/* BUTTONS */
.btn-solid{height:40px;padding:0 1.1rem;border-radius:8px;font-family:var(--font-b);font-size:.8rem;font-weight:700;cursor:pointer;border:none;background:linear-gradient(135deg,var(--navy),var(--navy-l));color:#fff;display:inline-flex;align-items:center;gap:6px;text-decoration:none;transition:all .18s;box-shadow:0 2px 10px rgba(5,46,100,.28)}
.btn-solid:hover{transform:translateY(-1px);box-shadow:0 4px 16px rgba(5,46,100,.4)}
.btn-solid.danger{background:linear-gradient(135deg,var(--crimson-deep),var(--crimson));box-shadow:0 2px 10px rgba(200,16,46,.28)}
.btn-solid.danger:hover{box-shadow:0 4px 16px rgba(200,16,46,.4)}
.btn-solid.success{background:linear-gradient(135deg,#047857,#059669)}
.btn-outline{height:38px;padding:0 1rem;border-radius:8px;font-family:var(--font-b);font-size:.78rem;font-weight:600;cursor:pointer;border:1.5px solid var(--border);background:#fff;color:var(--slate);display:inline-flex;align-items:center;gap:6px;text-decoration:none;transition:all .18s}
.btn-outline:hover{border-color:#CBD5E1;color:var(--ink)}
.btn-icon{width:32px;height:32px;border-radius:7px;border:1.5px solid var(--border);background:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:.72rem;color:var(--slate);cursor:pointer;transition:all .18s}
.btn-icon:hover{border-color:#CBD5E1;color:var(--ink)}
.btn-icon.red:hover{border-color:rgba(200,16,46,.3);background:rgba(200,16,46,.05);color:var(--crimson)}
.btn-icon.green:hover{border-color:rgba(5,150,105,.3);background:rgba(5,150,105,.05);color:var(--success)}
.action-row{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.65rem;margin-bottom:1.25rem}

/* RULE CARD */
.rule-card{border:1.5px solid var(--border);border-radius:var(--r);padding:1.1rem 1.25rem;margin-bottom:.75rem;transition:all .2s;background:#fff}
.rule-card:hover{border-color:#CBD5E1;box-shadow:0 4px 20px rgba(0,0,0,.07)}
.rule-card.active-rule{border-left:3px solid var(--success)}
.rule-card.paused-rule{border-left:3px solid var(--warning)}
.rule-card.draft-rule{border-left:3px solid #CBD5E1}
.rc-top{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:.6rem}
.rc-title{font-family:var(--font-d);font-weight:700;font-size:.95rem;color:var(--ink);margin-bottom:3px}
.rc-desc{font-size:.77rem;color:var(--slate);line-height:1.5}
.rc-chips{display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.75rem}
.rc-chip{display:inline-flex;align-items:center;gap:5px;padding:3px 8px;border-radius:6px;font-size:.68rem;font-weight:600}
.rc-actions{display:flex;gap:.4rem;flex-shrink:0}

/* QUICK SEND RESULT */
.qs-result-row{display:flex;align-items:center;justify-content:space-between;padding:.65rem .9rem;border-bottom:1px solid #F1EDE8;font-size:.8rem}
.qs-result-row:last-child{border-bottom:none}
.qs-name{font-weight:600;color:var(--ink)}
.qs-detail{font-size:.72rem;color:var(--slate);margin-top:1px}
.qs-ch{display:flex;gap:5px}

/* LOG */
.log-item{display:flex;align-items:flex-start;gap:.85rem;padding:.8rem 0;border-bottom:1px solid #F1EDE8}
.log-item:last-child{border-bottom:none}
.log-ico{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.75rem;flex-shrink:0}
.log-body{flex:1;min-width:0}
.log-meta{font-size:.72rem;color:var(--slate);margin-top:2px}
.log-msg{font-size:.78rem;color:#374151;margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

/* TEMPLATE CARDS */
.tpl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:.85rem}
.tpl-card{border:1.5px solid var(--border);border-radius:var(--r);padding:1.1rem 1.15rem;background:#fff;transition:all .2s;position:relative}
.tpl-card:hover{border-color:#CBD5E1;box-shadow:0 4px 18px rgba(0,0,0,.07)}
.tpl-sys{position:absolute;top:10px;right:10px}
.tpl-name{font-family:var(--font-d);font-weight:700;font-size:.9rem;color:var(--ink);margin-bottom:3px}
.tpl-cat{font-size:.68rem;font-weight:700;color:var(--slate);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.55rem}
.tpl-prev{font-size:.77rem;color:var(--slate);line-height:1.55;overflow:hidden;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical}
.tpl-actions{display:flex;gap:.4rem;margin-top:.9rem}

/* DIVIDER */
.div-lbl{display:flex;align-items:center;gap:.75rem;margin:1.35rem 0;font-size:.72rem;font-weight:700;color:var(--slate);text-transform:uppercase;letter-spacing:.06em}
.div-lbl::before,.div-lbl::after{content:'';flex:1;height:1px;background:var(--border)}

/* TOGGLE SWITCH */
.toggle-sw{position:relative;display:inline-block;width:42px;height:22px}
.toggle-sw input{opacity:0;width:0;height:0}
.toggle-sw .slider{position:absolute;inset:0;background:#CBD5E1;border-radius:22px;cursor:pointer;transition:.25s}
.toggle-sw input:checked + .slider{background:var(--success)}
.toggle-sw .slider::before{content:'';position:absolute;height:16px;width:16px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.25s}
.toggle-sw input:checked + .slider::before{transform:translateX(20px)}

/* MOBILE */
@media(max-width:480px){.form-grid.col3{grid-template-columns:1fr}.h-chip{display:none}}

@keyframes spin{to{transform:rotate(360deg)}}
.spinner{animation:spin .8s linear infinite;display:inline-block}
</style>
</head>
<body>

<!-- HERO -->
<div class="hero">
  <div class="hero-grid"></div>
  <div class="wrap hero-inner">
    <div class="hero-badge"><i class="fas fa-circle"></i>Admin Panel</div>
    <h1 class="hero-title"><i class="fas fa-bell" style="font-size:.85em;opacity:.7;margin-right:.3em"></i>Creator Notifications</h1>
    <p class="hero-sub">Automate and manage all communications with your event creators</p>
    <div class="hero-chips">
      <div class="h-chip"><i class="fas fa-robot"></i><div><div style="font-size:.62rem;opacity:.6;line-height:1">Active Rules</div><strong><?= number_format($rules_active??0) ?></strong></div></div>
      <div class="h-chip"><i class="fas fa-paper-plane"></i><div><div style="font-size:.62rem;opacity:.6;line-height:1">Emails Sent</div><strong><?= number_format($log_sent??0) ?></strong></div></div>
      <div class="h-chip"><i class="fas fa-clock"></i><div><div style="font-size:.62rem;opacity:.6;line-height:1">Pending</div><strong><?= number_format($log_pending??0) ?></strong></div></div>
      <div class="h-chip"><i class="fas fa-times-circle"></i><div><div style="font-size:.62rem;opacity:.6;line-height:1">Failed</div><strong><?= number_format($log_failed??0) ?></strong></div></div>
    </div>
  </div>
</div>

<div class="wrap">

<?php if (isset($db_err)): ?>
  <div class="k-alert k-err"><i class="fas fa-database"></i>Database error: <?= htmlspecialchars($db_err) ?></div>
<?php endif; ?>

<?php if ($flash): ?>
  <div class="k-alert <?= $flash['type']==='ok'?'k-ok':'k-err' ?>" style="margin-bottom:1rem">
    <i class="fas <?= $flash['type']==='ok'?'fa-check-circle':'fa-exclamation-circle' ?>"></i>
    <span><?= htmlspecialchars($flash['msg']) ?></span>
  </div>
<?php endif; ?>

<div class="main-card">

  <!-- ── TABS ── -->
  <div class="tab-nav" id="tabNav">
    <button class="tab-btn <?= $active_tab==='rules'    ?'active':'' ?>" data-tab="rules">
      <i class="fas fa-robot"></i>Rules <span class="cnt"><?= count($rules??[]) ?></span>
    </button>
    <button class="tab-btn <?= $active_tab==='new-rule' ?'active':'' ?>" data-tab="new-rule">
      <i class="fas fa-plus"></i><?= $editing_rule ? 'Edit Rule' : 'New Rule' ?>
    </button>
    <button class="tab-btn <?= $active_tab==='send-now' ?'active':'' ?>" data-tab="send-now">
      <i class="fas fa-paper-plane"></i>Quick Send
    </button>
    <button class="tab-btn <?= $active_tab==='templates'?'active':'' ?>" data-tab="templates">
      <i class="fas fa-file-alt"></i>Templates <span class="cnt"><?= count($templates??[]) ?></span>
    </button>
    <button class="tab-btn <?= $active_tab==='log'      ?'active':'' ?>" data-tab="log">
      <i class="fas fa-list-ul"></i>Activity Log <span class="cnt"><?= count($logs??[]) ?></span>
    </button>
  </div>

  <div class="tc-area">

  <!-- ══════════════════════════════════════════════════════════
       TAB: RULES
  ══════════════════════════════════════════════════════════ -->
  <div class="tab-pane <?= $active_tab==='rules'?'active':'' ?>" id="tab-rules">
    <div class="sh">
      <div class="sh-icon ic-p"><i class="fas fa-robot"></i></div>
      <div><h5>Automation Rules</h5><p>Triggers that fire automatically when conditions are met</p></div>
    </div>

    <div class="action-row">
      <div style="font-size:.82rem;color:var(--slate)"><?= count($rules??[]) ?> rule(s) configured</div>
      <button class="btn-solid" onclick="switchTab('new-rule')"><i class="fas fa-plus"></i>New Rule</button>
    </div>

    <?php if (empty($rules)): ?>
      <div class="empty">
        <div class="empty-ic"><i class="fas fa-robot"></i></div>
        <p>No automation rules yet. Create your first rule to get started.</p>
        <br>
        <button class="btn-solid" onclick="switchTab('new-rule')"><i class="fas fa-plus"></i>Create First Rule</button>
      </div>
    <?php else: ?>
      <?php foreach ($rules as $rule):
        $status_cls = ['active'=>'active-rule','paused'=>'paused-rule','draft'=>'draft-rule'][$rule['status']] ?? 'draft-rule';
        $ch_arr = explode(',', $rule['channel']);
      ?>
      <div class="rule-card <?= $status_cls ?>">
        <div class="rc-top">
          <div style="flex:1;min-width:0">
            <div class="rc-title"><?= htmlspecialchars($rule['name']) ?></div>
            <?php if ($rule['description']): ?>
              <div class="rc-desc"><?= htmlspecialchars($rule['description']) ?></div>
            <?php endif; ?>
          </div>
          <div class="rc-actions">
            <a href="?page=creators_notifications&edit_rule=<?= $rule['id'] ?>" class="btn-icon" title="Edit"><i class="fas fa-pencil"></i></a>
            <form method="POST" style="display:inline">
              <input type="hidden" name="toggle_rule" value="<?= $rule['id'] ?>">
              <button type="submit" class="btn-icon <?= $rule['status']==='active'?'':'green' ?>" title="<?= $rule['status']==='active'?'Pause':'Activate' ?>">
                <i class="fas <?= $rule['status']==='active'?'fa-pause':'fa-play' ?>"></i>
              </button>
            </form>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this rule?')">
              <input type="hidden" name="delete_rule" value="<?= $rule['id'] ?>">
              <button type="submit" class="btn-icon red" title="Delete"><i class="fas fa-trash"></i></button>
            </form>
          </div>
        </div>
        <div class="rc-chips">
          <!-- Status -->
          <?php
            $sc = ['active'=>'bg-ok','paused'=>'bg-w','draft'=>'bg-sl'][$rule['status']] ?? 'bg-sl';
            $si = ['active'=>'fa-circle','paused'=>'fa-pause','draft'=>'fa-pencil'][$rule['status']] ?? 'fa-circle';
          ?>
          <span class="badge <?= $sc ?> rc-chip"><i class="fas <?= $si ?>" style="font-size:.4rem"></i><?= ucfirst($rule['status']) ?></span>
          <!-- Trigger -->
          <span class="badge bg-p rc-chip"><i class="fas fa-bolt"></i><?= htmlspecialchars($TRIGGER_LABELS[$rule['trigger_type']] ?? $rule['trigger_type']) ?><?= $rule['trigger_value']>0?' ('.$rule['trigger_value'].')':'' ?></span>
          <!-- Channels -->
          <?php foreach ($ch_arr as $ch): ?>
            <span class="badge bg-b rc-chip"><i class="fas <?= $ch==='sms'?'fa-sms':'fa-envelope' ?>"></i><?= strtoupper($ch) ?></span>
          <?php endforeach; ?>
          <!-- Target -->
          <?php
            $tgt_map = ['all_creators'=>'All Creators','specific_creator'=>'Specific Creator','specific_ticket'=>'Specific Ticket','tag'=>'Tag Group'];
            $tgt_lbl = $tgt_map[$rule['target_type']] ?? $rule['target_type'];
          ?>
          <span class="badge bg-i rc-chip"><i class="fas fa-users"></i><?= $tgt_lbl ?></span>
          <!-- Delay -->
          <?php if ($rule['delay_hours']>0): ?>
            <span class="badge bg-sl rc-chip"><i class="fas fa-clock"></i>+<?= $rule['delay_hours'] ?>h delay</span>
          <?php endif; ?>
          <!-- Dedup -->
          <span class="badge bg-sl rc-chip"><i class="fas fa-ban"></i>Once per <?= $rule['send_once_per'] ?></span>
        </div>
        <?php if ($rule['subject_tpl']): ?>
          <div style="margin-top:.6rem;font-size:.75rem;color:var(--slate)"><i class="fas fa-at" style="margin-right:4px;opacity:.5"></i><?= htmlspecialchars($rule['subject_tpl']) ?></div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- ══════════════════════════════════════════════════════════
       TAB: NEW / EDIT RULE
  ══════════════════════════════════════════════════════════ -->
  <div class="tab-pane <?= $active_tab==='new-rule'?'active':'' ?>" id="tab-new-rule">
    <div class="sh">
      <div class="sh-icon ic-p"><i class="fas fa-<?= $editing_rule?'pencil':'plus' ?>"></i></div>
      <div>
        <h5><?= $editing_rule?'Edit Rule':'Create Automation Rule' ?></h5>
        <p>Define trigger, message, and recipients</p>
      </div>
    </div>

    <form method="POST" action="?page=creators_notifications" id="ruleForm">
      <input type="hidden" name="save_rule" value="1">
      <input type="hidden" name="rule_id" value="<?= $editing_rule['id'] ?? 0 ?>">

      <div class="form-grid" style="row-gap:1.1rem">

        <div class="f-section">① Basic Info</div>

        <div class="f-group">
          <label>Rule Name <span>*</span></label>
          <input type="text" name="name" placeholder="e.g. First Purchase Congrats" required
                 value="<?= htmlspecialchars($editing_rule['name']??'') ?>">
        </div>
        <div class="f-group">
          <label>Status</label>
          <select name="status">
            <?php foreach (['draft','active','paused'] as $s): ?>
              <option value="<?= $s ?>" <?= ($editing_rule['status']??'draft')===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="f-hint">Set to <strong>Active</strong> for the rule to fire automatically.</div>
        </div>

        <div class="f-group full">
          <label>Description</label>
          <input type="text" name="description" placeholder="Internal note about this rule's purpose"
                 value="<?= htmlspecialchars($editing_rule['description']??'') ?>">
        </div>

        <div class="f-section">② Trigger</div>

        <div class="f-group">
          <label>Trigger Type <span>*</span></label>
          <select name="trigger_type" id="triggerType" onchange="onTriggerChange(this)">
            <option value="">— Select trigger —</option>
            <?php foreach ($TRIGGER_LABELS as $tv => $tl): ?>
              <option value="<?= $tv ?>" <?= ($editing_rule['trigger_type']??'')===$tv?'selected':'' ?>><?= htmlspecialchars($tl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="f-group" id="trigValWrap" style="<?= (isset($editing_rule['trigger_value']) && $editing_rule['trigger_value']>0)?'':'display:none' ?>">
          <label id="trigValLabel">Value</label>
          <input type="number" name="trigger_value" id="trigValInput" min="1" placeholder="e.g. 5"
                 value="<?= $editing_rule['trigger_value'] ?? '' ?>">
          <div class="f-hint" id="trigValHint"></div>
        </div>

        <div class="f-section">③ Channel</div>

        <div class="f-group full">
          <label style="font-size:.71rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:8px">Send Via <span style="color:var(--crimson)">*</span></label>
          <div style="display:flex;flex-wrap:wrap;gap:.65rem">
            <label id="ch_email" style="display:inline-flex;align-items:center;gap:8px;padding:.6rem 1.1rem;border:1.5px solid var(--navy);border-radius:9px;background:rgba(5,46,100,.06);cursor:pointer;font-size:.83rem;font-weight:600;color:var(--navy)">
              <input type="checkbox" name="channel[]" value="email" checked onchange="toggleChk(this,'ch_email')" style="accent-color:var(--navy);width:15px;height:15px">
              <i class="fas fa-envelope"></i> Email
            </label>
            <span style="display:inline-flex;align-items:center;gap:8px;padding:.6rem 1.1rem;border:1.5px solid #e2e8f0;border-radius:9px;background:#f8fafc;font-size:.83rem;font-weight:500;color:#b0bec5;cursor:not-allowed;user-select:none">
              <i class="fas fa-sms" style="color:#cbd5e1"></i> SMS
              <span style="background:#fef3c7;color:#92400e;font-size:.62rem;font-weight:700;padding:2px 6px;border-radius:100px;border:1px solid #fde68a">COMING SOON</span>
            </span>
          </div>
          <div style="font-size:.71rem;color:var(--slate);margin-top:6px">SMS gateway (Africa's Talking / Twilio) integration coming soon.</div>
        </div>

        <div class="f-section">④ Target Recipients</div>

        <div class="f-group full">
          <label style="font-size:.71rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:8px">Send To <span style="color:var(--crimson)">*</span></label>
          <?php $sel_tgt = $editing_rule['target_type'] ?? 'all_creators'; ?>
          <div style="display:flex;flex-direction:column;gap:.5rem" id="targetCards">

            <div onclick="onTargetChange('all_creators')" id="tgt_all_creators"
                 style="display:flex;align-items:center;gap:12px;padding:.8rem 1rem;border:1.5px solid <?= $sel_tgt==='all_creators'?'var(--navy)':' var(--border)' ?>;border-radius:10px;background:<?= $sel_tgt==='all_creators'?'rgba(5,46,100,.05)':' var(--mist)' ?>;cursor:pointer">
              <input type="radio" name="target_type" value="all_creators" <?= $sel_tgt==='all_creators'?'checked':'' ?> style="accent-color:var(--navy);width:16px;height:16px;flex-shrink:0">
              <div style="width:34px;height:34px;border-radius:8px;background:rgba(59,130,246,.1);display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="fas fa-users" style="color:#3B82F6;font-size:.8rem"></i></div>
              <div>
                <div style="font-size:.85rem;font-weight:700;color:#0f1923">All Creators</div>
                <div style="font-size:.72rem;color:#64748b;margin-top:2px">Send to every creator on the platform</div>
              </div>
            </div>

            <div onclick="onTargetChange('specific_creator')" id="tgt_specific_creator"
                 style="display:flex;align-items:center;gap:12px;padding:.8rem 1rem;border:1.5px solid <?= $sel_tgt==='specific_creator'?'var(--navy)':' var(--border)' ?>;border-radius:10px;background:<?= $sel_tgt==='specific_creator'?'rgba(5,46,100,.05)':' var(--mist)' ?>;cursor:pointer">
              <input type="radio" name="target_type" value="specific_creator" <?= $sel_tgt==='specific_creator'?'checked':'' ?> style="accent-color:var(--navy);width:16px;height:16px;flex-shrink:0">
              <div style="width:34px;height:34px;border-radius:8px;background:rgba(5,46,100,.1);display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="fas fa-user" style="color:var(--navy);font-size:.8rem"></i></div>
              <div>
                <div style="font-size:.85rem;font-weight:700;color:#0f1923">Specific Creator(s)</div>
                <div style="font-size:.72rem;color:#64748b;margin-top:2px">Choose one or more individual creators from a list</div>
              </div>
            </div>

            <div onclick="onTargetChange('specific_ticket')" id="tgt_specific_ticket"
                 style="display:flex;align-items:center;gap:12px;padding:.8rem 1rem;border:1.5px solid <?= $sel_tgt==='specific_ticket'?'var(--navy)':' var(--border)' ?>;border-radius:10px;background:<?= $sel_tgt==='specific_ticket'?'rgba(5,46,100,.05)':' var(--mist)' ?>;cursor:pointer">
              <input type="radio" name="target_type" value="specific_ticket" <?= $sel_tgt==='specific_ticket'?'checked':'' ?> style="accent-color:var(--navy);width:16px;height:16px;flex-shrink:0">
              <div style="width:34px;height:34px;border-radius:8px;background:rgba(200,16,46,.1);display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="fas fa-ticket-alt" style="color:var(--crimson);font-size:.8rem"></i></div>
              <div>
                <div style="font-size:.85rem;font-weight:700;color:#0f1923">Specific Ticket(s)</div>
                <div style="font-size:.72rem;color:#64748b;margin-top:2px">Scope this rule to one or more specific events</div>
              </div>
            </div>

          </div>
        </div>


        <!-- Specific IDs sub-panel -->
        <div class="f-group full" id="targetIdsPanel" style="<?= $sel_tgt==='all_creators'?'display:none':'' ?>">
          <label id="targetIdsLabel">Select Creators / Tickets <span>*</span></label>
          <div id="targetIdsCreators" style="<?= $sel_tgt==='specific_creator'?'':'display:none' ?>">
            <select name="target_ids" id="creatorMulti" multiple size="6"
                    style="height:auto;padding:6px;border:1.5px solid var(--border);border-radius:var(--r-sm);background:var(--mist);font-family:var(--font-b);font-size:.85rem;width:100%">
              <?php
                $existing_ids = json_decode($editing_rule['target_ids']??'[]',true) ?? [];
                foreach ($all_creators as $ac):
              ?>
                <option value="<?= $ac['id'] ?>" <?= in_array($ac['id'],$existing_ids)?'selected':'' ?>>
                  <?= htmlspecialchars($ac['full_name']) ?> (@<?= htmlspecialchars($ac['username']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
            <div class="f-hint" style="margin-top:5px">Hold Ctrl / Cmd to select multiple.</div>
          </div>
          <div id="targetIdsTickets" style="<?= $sel_tgt==='specific_ticket'?'':'display:none' ?>">
            <select name="target_ids_ticket" id="ticketMulti" multiple size="6"
                    style="height:auto;padding:6px;border:1.5px solid var(--border);border-radius:var(--r-sm);background:var(--mist);font-family:var(--font-b);font-size:.85rem;width:100%">
              <?php foreach ($all_tickets as $at): ?>
                <option value="<?= $at['id'] ?>" <?= in_array($at['id'],$existing_ids)?'selected':'' ?>>
                  <?= htmlspecialchars($at['title']) ?>
                  <?= $at['start_date']?' – '.date('d M Y',strtotime($at['start_date'])):'' ?>
                  (<?= htmlspecialchars($at['creator_name']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
            <div class="f-hint" style="margin-top:5px">Hold Ctrl / Cmd to select multiple.</div>
          </div>
        </div>

        <div class="f-section">⑤ Timing</div>

        <div class="f-group">
          <label>Delay After Trigger</label>
          <div style="display:flex;align-items:center;gap:8px">
            <input type="number" name="delay_hours" min="0" placeholder="0" style="width:90px"
                   value="<?= $editing_rule['delay_hours'] ?? 0 ?>">
            <span style="font-size:.83rem;color:var(--slate);white-space:nowrap">hours (0 = immediate)</span>
          </div>
          <div class="f-hint">E.g. 24 = send the day after the trigger fires.</div>
        </div>
        <div class="f-group">
          <label>Deduplication Scope</label>
          <select name="send_once_per">
            <?php foreach (['ticket'=>'Once per Ticket','creator'=>'Once per Creator','global'=>'Global (fire once ever)'] as $k=>$v): ?>
              <option value="<?= $k ?>" <?= ($editing_rule['send_once_per']??'ticket')===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
          <div class="f-hint">Prevents the same rule from spamming the same creator/ticket.</div>
        </div>

        <div class="f-section">⑥ Message</div>

        <div class="f-group full">
          <label>Email Subject Template</label>
          <input type="text" name="subject_tpl" placeholder="e.g. 🎉 First sale! {{ticket_title}} just sold its first ticket"
                 value="<?= htmlspecialchars($editing_rule['subject_tpl']??'') ?>">
          <div class="f-hint">Leave blank for SMS-only rules.</div>
        </div>

        <div class="f-group full">
          <label>Message Body Template <span>*</span></label>
          <textarea name="message_tpl" id="messageTpl" placeholder="Hi {{creator_name}},&#10;&#10;Congratulations! Your first ticket for {{ticket_title}} just sold.&#10;&#10;Share the link to keep it going: {{ticket_url}}" required><?= htmlspecialchars($editing_rule['message_tpl']??'') ?></textarea>
          <div class="f-hint">Plain text. HTML not rendered in SMS. Click a variable below to insert it.</div>
        </div>

        <!-- Variable Legend -->
        <div class="f-group full">
          <div class="var-legend">
            <h6><i class="fas fa-code" style="margin-right:5px"></i>Available Template Variables — click to insert</h6>
            <div class="var-grid">
              <?php foreach ($VAR_LEGEND as $var => $desc): ?>
              <div class="var-item">
                <span class="var-tag" onclick="insertVar('<?= $var ?>')"><?= $var ?></span>
                <span class="var-desc"><?= $desc ?></span>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

      </div><!-- /form-grid -->

      <div style="display:flex;gap:.65rem;margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid var(--border)">
        <button type="submit" class="btn-solid" id="saveRuleBtn">
          <i class="fas fa-save"></i><?= $editing_rule?'Save Changes':'Create Rule' ?>
        </button>
        <a href="?page=creators_notifications&tab=rules" class="btn-outline"><i class="fas fa-times"></i>Cancel</a>
      </div>

    </form>
  </div>

  <!-- ══════════════════════════════════════════════════════════
       TAB: QUICK SEND
  ══════════════════════════════════════════════════════════ -->
  <div class="tab-pane <?= $active_tab==='send-now'?'active':'' ?>" id="tab-send-now">
    <div class="sh">
      <div class="sh-icon ic-b"><i class="fas fa-paper-plane"></i></div>
      <div><h5>Quick Send — One-Off Notification</h5><p>Send an ad-hoc message to creators right now</p></div>
    </div>

    <?php if (!empty($qs_results)): ?>
      <div style="margin-bottom:1.5rem">
        <div class="div-lbl">Send Results</div>
        <div class="t-wrap">
          <?php foreach ($qs_results as $qr): ?>
          <div class="qs-result-row">
            <div>
              <div class="qs-name"><?= htmlspecialchars($qr['creator']) ?></div>
              <div class="qs-detail"><?= htmlspecialchars($qr['email']) ?> · <?= htmlspecialchars($qr['phone']) ?></div>
            </div>
            <div class="qs-ch">
              <?php if (isset($qr['email_ok'])): ?>
                <span class="badge <?= $qr['email_ok']?'bg-ok':'bg-err' ?>"><i class="fas <?= $qr['email_ok']?'fa-check':'fa-times' ?>"></i>Email</span>
              <?php endif; ?>
              <?php if (isset($qr['sms_ok'])): ?>
                <span class="badge bg-w"><i class="fas fa-clock"></i>SMS (queued)</span>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="div-lbl">Compose Another</div>
      </div>
    <?php endif; ?>

    <form method="POST" action="?page=creators_notifications" id="quickSendForm">
      <input type="hidden" name="quick_send" value="1">
      <div class="form-grid" style="row-gap:1.1rem">


        <div class="f-section">① Channel</div>

        <div class="f-group full">
          <label style="font-size:.71rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:8px">Send Via <span style="color:var(--crimson)">*</span></label>
          <div style="display:flex;flex-wrap:wrap;gap:.65rem">
            <label id="qsch_email" style="display:inline-flex;align-items:center;gap:8px;padding:.6rem 1.1rem;border:1.5px solid var(--navy);border-radius:9px;background:rgba(5,46,100,.06);cursor:pointer;font-size:.83rem;font-weight:600;color:var(--navy)">
              <input type="checkbox" name="qs_channel[]" value="email" checked onchange="toggleChk(this,'qsch_email')" style="accent-color:var(--navy);width:15px;height:15px">
              <i class="fas fa-envelope"></i> Email
            </label>
            <span style="display:inline-flex;align-items:center;gap:8px;padding:.6rem 1.1rem;border:1.5px solid #e2e8f0;border-radius:9px;background:#f8fafc;font-size:.83rem;font-weight:500;color:#b0bec5;cursor:not-allowed;user-select:none">
              <i class="fas fa-sms" style="color:#cbd5e1"></i> SMS
              <span style="background:#fef3c7;color:#92400e;font-size:.62rem;font-weight:700;padding:2px 6px;border-radius:100px;border:1px solid #fde68a">COMING SOON</span>
            </span>
          </div>
        </div>

        <div class="f-section">② Recipients</div>

        <div class="f-group full">
          <label style="font-size:.71rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:8px">Send To <span style="color:var(--crimson)">*</span></label>
          <div style="display:flex;flex-direction:column;gap:.5rem" id="qsTargetCards">

            <div onclick="onQsTarget('all')" id="qstgt_all"
                 style="display:flex;align-items:center;gap:12px;padding:.8rem 1rem;border:1.5px solid var(--navy);border-radius:10px;background:rgba(5,46,100,.05);cursor:pointer">
              <input type="radio" name="qs_target" value="all" checked style="accent-color:var(--navy);width:16px;height:16px;flex-shrink:0">
              <div style="width:34px;height:34px;border-radius:8px;background:rgba(59,130,246,.1);display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="fas fa-users" style="color:#3B82F6;font-size:.8rem"></i></div>
              <div>
                <div style="font-size:.85rem;font-weight:700;color:#0f1923">All Creators</div>
                <div style="font-size:.72rem;color:#64748b;margin-top:2px">Send to all <?= count($all_creators) ?> creator(s) on the platform</div>
              </div>
            </div>

            <div onclick="onQsTarget('specific')" id="qstgt_specific"
                 style="display:flex;align-items:center;gap:12px;padding:.8rem 1rem;border:1.5px solid var(--border);border-radius:10px;background:var(--mist);cursor:pointer">
              <input type="radio" name="qs_target" value="specific" style="accent-color:var(--navy);width:16px;height:16px;flex-shrink:0">
              <div style="width:34px;height:34px;border-radius:8px;background:rgba(5,46,100,.1);display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="fas fa-user" style="color:var(--navy);font-size:.8rem"></i></div>
              <div>
                <div style="font-size:.85rem;font-weight:700;color:#0f1923">Specific Creator(s)</div>
                <div style="font-size:.72rem;color:#64748b;margin-top:2px">Pick one or more creators from a list</div>
              </div>
            </div>

            <div onclick="onQsTarget('ticket')" id="qstgt_ticket"
                 style="display:flex;align-items:center;gap:12px;padding:.8rem 1rem;border:1.5px solid var(--border);border-radius:10px;background:var(--mist);cursor:pointer">
              <input type="radio" name="qs_target" value="ticket" style="accent-color:var(--navy);width:16px;height:16px;flex-shrink:0">
              <div style="width:34px;height:34px;border-radius:8px;background:rgba(200,16,46,.1);display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="fas fa-ticket-alt" style="color:var(--crimson);font-size:.8rem"></i></div>
              <div>
                <div style="font-size:.85rem;font-weight:700;color:#0f1923">By Ticket / Event</div>
                <div style="font-size:.72rem;color:#64748b;margin-top:2px">Send to the creator who owns a specific ticket</div>
              </div>
            </div>

          </div>
        </div>


        <div class="f-group full" id="qsCreatorPanel" style="display:none">
          <label>Select Creator(s)</label>
          <select name="qs_creator_ids[]" multiple size="5"
                  style="height:auto;padding:6px;border:1.5px solid var(--border);border-radius:var(--r-sm);background:var(--mist);font-family:var(--font-b);font-size:.85rem;width:100%">
            <?php foreach ($all_creators as $ac): ?>
              <option value="<?= $ac['id'] ?>"><?= htmlspecialchars($ac['full_name']) ?> (@<?= htmlspecialchars($ac['username']) ?>)</option>
            <?php endforeach; ?>
          </select>
          <div class="f-hint" style="margin-top:4px">Hold Ctrl / Cmd to select multiple.</div>
        </div>

        <div class="f-group" id="qsTicketPanel" style="display:none">
          <label>Select Ticket</label>
          <select name="qs_ticket_id">
            <option value="">— Choose —</option>
            <?php foreach ($all_tickets as $at): ?>
              <option value="<?= $at['id'] ?>"><?= htmlspecialchars($at['title']) ?> (<?= htmlspecialchars($at['creator_name']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="f-section">③ Load Template (optional)</div>

        <div class="f-group full">
          <label>Load from Template</label>
          <select id="tplLoader" onchange="loadTemplate(this)">
            <option value="">— Pick a template to pre-fill —</option>
            <?php foreach ($templates as $tpl): ?>
              <option value="<?= $tpl['id'] ?>"
                      data-subject="<?= htmlspecialchars($tpl['subject']??'',ENT_QUOTES) ?>"
                      data-body="<?= htmlspecialchars($tpl['body']??'',ENT_QUOTES) ?>">
                <?= htmlspecialchars($tpl['name']) ?><?= $tpl['is_system']?' ⭐':'' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="f-hint">This pre-fills the subject and message below. You can still edit before sending.</div>
        </div>

        <div class="f-section">④ Message</div>

        <div class="f-group full">
          <label>Subject (Email only)</label>
          <input type="text" name="qs_subject" id="qsSubject" placeholder="e.g. 🎉 Your ticket link is ready — share it now!">
        </div>

        <div class="f-group full">
          <label>Message Body <span>*</span></label>
          <textarea name="qs_message" id="qsMessage" required style="min-height:160px" placeholder="Hi {{creator_name}},&#10;&#10;Here is your ticket link:&#10;{{ticket_url}}&#10;&#10;Share it to start selling!"></textarea>
        </div>

        <div class="f-group full">
          <label>Admin Note (optional)</label>
          <input type="text" name="qs_admin_note" placeholder="E.g. Sent after marketing campaign launch — replaces {{admin_note}}">
        </div>

        <div class="f-group full">
          <div class="var-legend">
            <h6><i class="fas fa-code" style="margin-right:5px"></i>Click to insert variable</h6>
            <div class="var-grid">
              <?php foreach ($VAR_LEGEND as $var => $desc): ?>
              <div class="var-item">
                <span class="var-tag" onclick="insertVarQs('<?= $var ?>')"><?= $var ?></span>
                <span class="var-desc"><?= $desc ?></span>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

      </div>

      <div style="display:flex;gap:.65rem;margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid var(--border);flex-wrap:wrap;align-items:center">
        <button type="submit" class="btn-solid success" id="qsSendBtn">
          <i class="fas fa-paper-plane"></i>Send Now
        </button>
        <div style="font-size:.76rem;color:var(--slate)"><i class="fas fa-info-circle" style="margin-right:4px"></i>Delivery is immediate. SMS requires gateway configuration.</div>
      </div>

    </form>
  </div>

  <!-- ══════════════════════════════════════════════════════════
       TAB: TEMPLATES
  ══════════════════════════════════════════════════════════ -->
  <div class="tab-pane <?= $active_tab==='templates'?'active':'' ?>" id="tab-templates">
    <div class="sh">
      <div class="sh-icon ic-a"><i class="fas fa-file-alt"></i></div>
      <div><h5>Message Templates</h5><p>Reusable templates for rules and quick sends. ⭐ = system-provided.</p></div>
    </div>

    <!-- Template Form -->
    <details id="tplFormWrap" style="margin-bottom:1.5rem">
      <summary style="cursor:pointer;font-size:.82rem;font-weight:700;color:var(--navy);padding:.6rem .75rem;background:rgba(5,46,100,.05);border:1px solid rgba(5,46,100,.12);border-radius:var(--r-sm);list-style:none;display:flex;align-items:center;gap:7px">
        <i class="fas fa-plus"></i>Create / Edit Custom Template
      </summary>
      <div style="padding:1.1rem;border:1px solid var(--border);border-top:none;border-radius:0 0 var(--r-sm) var(--r-sm)">
        <form method="POST" action="?page=creators_notifications">
          <input type="hidden" name="save_template" value="1">
          <input type="hidden" name="tpl_id" id="tplEditId" value="0">
          <div class="form-grid" style="row-gap:.9rem">
            <div class="f-group"><label>Template Name <span>*</span></label><input type="text" name="tpl_name" id="tplEditName" placeholder="e.g. Re-engagement Nudge"></div>
            <div class="f-group"><label>Category</label><input type="text" name="tpl_category" id="tplEditCat" placeholder="e.g. reminder / milestone / alert"></div>
            <div class="f-group full"><label>Subject Line</label><input type="text" name="tpl_subject" id="tplEditSubject" placeholder="Email subject with {{variables}}"></div>
            <div class="f-group full"><label>Body <span>*</span></label><textarea name="tpl_body" id="tplEditBody" style="min-height:130px" placeholder="Template body…"></textarea></div>
          </div>
          <div style="display:flex;gap:.6rem;margin-top:1rem">
            <button type="submit" class="btn-solid"><i class="fas fa-save"></i>Save Template</button>
            <button type="button" class="btn-outline" onclick="resetTplForm()"><i class="fas fa-undo"></i>Reset</button>
          </div>
        </form>
      </div>
    </details>

    <?php if (empty($templates)): ?>
      <div class="empty"><div class="empty-ic"><i class="fas fa-file-alt"></i></div><p>No templates yet.</p></div>
    <?php else: ?>
    <div class="tpl-grid">
      <?php foreach ($templates as $tpl):
        $cat_colors = ['milestone'=>'bg-ok','reminder'=>'bg-b','alert'=>'bg-err','general'=>'bg-sl'];
        $cat_cls = $cat_colors[$tpl['category']??''] ?? 'bg-sl';
      ?>
      <div class="tpl-card">
        <?php if ($tpl['is_system']): ?>
          <div class="tpl-sys"><span class="badge bg-w"><i class="fas fa-star" style="font-size:.45rem"></i>System</span></div>
        <?php endif; ?>
        <div class="tpl-cat"><span class="badge <?= $cat_cls ?>"><?= htmlspecialchars($tpl['category']??'general') ?></span></div>
        <div class="tpl-name"><?= htmlspecialchars($tpl['name']) ?></div>
        <?php if ($tpl['subject']): ?>
          <div style="font-size:.72rem;color:var(--slate);margin-bottom:.4rem"><i class="fas fa-at" style="opacity:.5;margin-right:4px"></i><?= htmlspecialchars($tpl['subject']) ?></div>
        <?php endif; ?>
        <div class="tpl-prev"><?= nl2br(htmlspecialchars($tpl['body'])) ?></div>
        <div class="tpl-actions">
          <button class="btn-outline" style="height:32px;font-size:.72rem;padding:0 .75rem"
                  onclick="editTemplate(<?= htmlspecialchars(json_encode($tpl),ENT_QUOTES) ?>)">
            <i class="fas fa-pencil"></i><?= $tpl['is_system']?'View':'Edit' ?>
          </button>
          <button class="btn-outline" style="height:32px;font-size:.72rem;padding:0 .75rem"
                  onclick="useTemplate(<?= htmlspecialchars(json_encode($tpl),ENT_QUOTES) ?>)">
            <i class="fas fa-bolt"></i>Use
          </button>
          <?php if (!$tpl['is_system']): ?>
          <form method="POST" style="display:inline" onsubmit="return confirm('Delete this template?')">
            <input type="hidden" name="delete_template" value="<?= $tpl['id'] ?>">
            <button type="submit" class="btn-outline" style="height:32px;font-size:.72rem;padding:0 .65rem;border-color:rgba(200,16,46,.25);color:var(--crimson)">
              <i class="fas fa-trash"></i>
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- ══════════════════════════════════════════════════════════
       TAB: ACTIVITY LOG
  ══════════════════════════════════════════════════════════ -->
  <div class="tab-pane <?= $active_tab==='log'?'active':'' ?>" id="tab-log">
    <div class="sh">
      <div class="sh-icon ic-g"><i class="fas fa-list-ul"></i></div>
      <div><h5>Activity Log</h5><p>Last 200 notification events across all rules and quick sends</p></div>
    </div>

    <!-- Filters -->
    <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-bottom:1.1rem;align-items:center">
      <input type="text" id="logSearch" placeholder="🔍 Search by creator, email, event…"
             style="height:38px;padding:0 12px;border:1.5px solid var(--border);border-radius:8px;font-family:var(--font-b);font-size:.82rem;background:var(--mist);outline:none;width:240px;transition:all .18s"
             oninput="filterLog()" onfocus="this.style.borderColor='var(--navy)'" onblur="this.style.borderColor=''">
      <select id="logChFilter" style="height:38px;padding:0 10px;border:1.5px solid var(--border);border-radius:8px;font-family:var(--font-b);font-size:.82rem;background:var(--mist);outline:none" onchange="filterLog()">
        <option value="">All channels</option>
        <option value="email">Email</option>
        <option value="sms">SMS</option>
      </select>
      <select id="logStFilter" style="height:38px;padding:0 10px;border:1.5px solid var(--border);border-radius:8px;font-family:var(--font-b);font-size:.82rem;background:var(--mist);outline:none" onchange="filterLog()">
        <option value="">All statuses</option>
        <option value="sent">Sent</option>
        <option value="failed">Failed</option>
        <option value="pending">Pending</option>
      </select>
      <div style="font-size:.75rem;color:var(--slate);margin-left:auto" id="logCount"><?= count($logs) ?> entries</div>
    </div>

    <?php if (empty($logs)): ?>
      <div class="empty"><div class="empty-ic"><i class="fas fa-list-ul"></i></div><p>No notification activity yet.</p></div>
    <?php else: ?>
    <div class="t-wrap" style="max-height:560px;overflow-y:auto">
      <table id="logTable">
        <thead><tr>
          <th>Time</th><th>Creator</th><th>Event</th><th>Channel</th><th>Recipient</th><th>Subject</th><th>Status</th>
        </tr></thead>
        <tbody id="logTbody">
          <?php foreach ($logs as $l):
            $st = strtolower($l['status']??'');
            $sc = ['sent'=>'bg-ok','failed'=>'bg-err','pending'=>'bg-w','skipped'=>'bg-sl'][$st] ?? 'bg-sl';
            $ci = $l['channel']==='sms'?'fa-sms':'fa-envelope';
          ?>
          <tr data-search="<?= strtolower(htmlspecialchars($l['creator_name'].'|'.$l['ticket_title'].'|'.$l['recipient'].'|'.$l['subject'], ENT_QUOTES)) ?>"
              data-channel="<?= $l['channel'] ?>" data-status="<?= $l['status'] ?>">
            <td style="white-space:nowrap;font-size:.74rem;color:var(--slate)">
              <?= date('d M y', strtotime($l['sent_at'])) ?><br><?= date('H:i', strtotime($l['sent_at'])) ?>
            </td>
            <td style="font-weight:600;font-size:.8rem"><?= htmlspecialchars($l['creator_name']??'—') ?></td>
            <td style="color:var(--slate);font-size:.78rem;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($l['ticket_title']??'—') ?></td>
            <td><span class="badge <?= $l['channel']==='sms'?'bg-w':'bg-b' ?>"><i class="fas <?= $ci ?>"></i><?= strtoupper($l['channel']) ?></span></td>
            <td style="font-size:.76rem;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($l['recipient']) ?></td>
            <td style="font-size:.76rem;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($l['subject']??'—') ?></td>
            <td>
              <span class="badge <?= $sc ?>"><?= ucfirst($l['status']) ?></span>
              <?php if ($l['error_info']): ?>
                <br><span style="font-size:.65rem;color:var(--crimson);display:block;margin-top:2px" title="<?= htmlspecialchars($l['error_info']) ?>"><i class="fas fa-exclamation-triangle"></i><?= htmlspecialchars(substr($l['error_info'],0,40)) ?>…</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  </div><!-- /tc-area -->

  <div style="border-top:1px solid var(--border);background:var(--mist);padding:.9rem 1.75rem;display:flex;align-items:center;justify-content:space-between;font-size:.73rem;color:var(--slate)">
    <span><i class="fas fa-robot" style="margin-right:5px;opacity:.4"></i>Keychele Notifications Engine</span>
    <span>Rules fire via cron or webhook · Last render <?= date('d M Y H:i') ?></span>
  </div>

</div><!-- /main-card -->
</div><!-- /wrap -->

<script>
/* ── TABS ── */
function switchTab(id) {
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  document.querySelectorAll('.tab-pane').forEach(p=>p.classList.remove('active'));
  const btn = document.querySelector(`.tab-btn[data-tab="${id}"]`);
  if (btn) btn.classList.add('active');
  const pane = document.getElementById('tab-'+id);
  if (pane) pane.classList.add('active');
}
document.querySelectorAll('.tab-btn').forEach(btn=>{
  btn.addEventListener('click',()=>switchTab(btn.dataset.tab));
});

/* ── CHECKBOX PILL STYLE ── */
function toggleChk(input, pillId) {
  document.getElementById(pillId)?.classList.toggle('checked', input.checked);
}

/* ── RADIO CARD STYLE ── */
function styleRadioCards(groupEl) {
  groupEl.querySelectorAll('.r-card').forEach(c=>{
    c.classList.toggle('checked', c.querySelector('input')?.checked);
  });
}
document.querySelectorAll('.radio-cards').forEach(g=>{
  g.querySelectorAll('input[type=radio]').forEach(r=>{
    r.addEventListener('change',()=>styleRadioCards(g));
  });
});

/* ── TRIGGER TYPE LOGIC ── */
const TRIGGER_HINTS = {
  nth_purchase:             ['N','Fire when this is the Nth completed purchase (e.g. 5 = 5th sale)'],
  no_purchase_after_days:   ['Days','Send if no purchase within X days of ticket creation'],
  ticket_created_no_purchase:['Days','Send if still no purchase X days after ticket was created'],
  pre_event_reminder:       ['Days','Send X days before the event date'],
  post_event_followup:      ['Days','Send X days after the event date'],
  low_stock_alert:          ['Remaining','Trigger when remaining tickets drop to X'],
  milestone_reached:        ['% Sold','Fire when % of capacity sold (requires capacity field on ticket)'],
};
function onTriggerChange(sel) {
  const v = sel.value, wrap = document.getElementById('trigValWrap');
  if (TRIGGER_HINTS[v]) {
    wrap.style.display = '';
    document.getElementById('trigValLabel').textContent = TRIGGER_HINTS[v][0];
    document.getElementById('trigValHint').textContent  = TRIGGER_HINTS[v][1];
    document.getElementById('trigValInput').placeholder = TRIGGER_HINTS[v][0];
  } else {
    wrap.style.display = 'none';
  }
}
// Init on load
const initTrig = document.getElementById('triggerType');
if (initTrig && initTrig.value) onTriggerChange(initTrig);

/* ── TARGET TYPE LOGIC (Rule Form) ── */
function styleTargetRow(containerId, selectedVal, prefix) {
  document.querySelectorAll('#'+containerId+' > div[id^="'+prefix+'"]').forEach(row => {
    const isSelected = row.id === prefix + selectedVal;
    row.style.borderColor  = isSelected ? 'var(--navy)' : 'var(--border)';
    row.style.background   = isSelected ? 'rgba(5,46,100,.05)' : 'var(--mist)';
    const radio = row.querySelector('input[type=radio]');
    if (radio) radio.checked = isSelected;
  });
}

function onTargetChange(val) {
  styleTargetRow('targetCards', val, 'tgt_');
  const panel = document.getElementById('targetIdsPanel');
  panel.style.display = val==='all_creators' ? 'none' : '';
  document.getElementById('targetIdsCreators').style.display = val==='specific_creator' ? '' : 'none';
  document.getElementById('targetIdsTickets').style.display  = val==='specific_ticket'  ? '' : 'none';
  const lbl = document.getElementById('targetIdsLabel');
  if (lbl) lbl.childNodes[0].nodeValue = val==='specific_ticket' ? 'Select Ticket(s) ' : 'Select Creator(s) ';
}

/* ── TARGET TYPE LOGIC (Quick Send) ── */
function onQsTarget(val) {
  styleTargetRow('qsTargetCards', val, 'qstgt_');
  document.getElementById('qsCreatorPanel').style.display = val==='specific'?'':'none';
  document.getElementById('qsTicketPanel').style.display  = val==='ticket'?'':'none';
}

/* ── INSERT VARIABLE (Rule form) ── */
function insertVar(v) {
  const ta = document.getElementById('messageTpl');
  if (!ta) return;
  const s = ta.selectionStart, e = ta.selectionEnd;
  ta.value = ta.value.slice(0,s) + v + ta.value.slice(e);
  ta.selectionStart = ta.selectionEnd = s + v.length;
  ta.focus();
}

/* ── INSERT VARIABLE (Quick Send) ── */
function insertVarQs(v) {
  const ta = document.getElementById('qsMessage');
  if (!ta) return;
  const s = ta.selectionStart, e = ta.selectionEnd;
  ta.value = ta.value.slice(0,s) + v + ta.value.slice(e);
  ta.selectionStart = ta.selectionEnd = s + v.length;
  ta.focus();
}

/* ── LOAD TEMPLATE INTO QUICK SEND ── */
function loadTemplate(sel) {
  if (!sel.value) return;
  const opt = sel.options[sel.selectedIndex];
  document.getElementById('qsSubject').value = opt.dataset.subject || '';
  document.getElementById('qsMessage').value = opt.dataset.body   || '';
}

/* ── TEMPLATE FORM: EDIT ── */
function editTemplate(tpl) {
  document.getElementById('tplEditId').value      = tpl.id;
  document.getElementById('tplEditName').value    = tpl.name;
  document.getElementById('tplEditCat').value     = tpl.category || '';
  document.getElementById('tplEditSubject').value = tpl.subject  || '';
  document.getElementById('tplEditBody').value    = tpl.body     || '';
  document.getElementById('tplFormWrap').setAttribute('open','');
  document.getElementById('tplFormWrap').scrollIntoView({behavior:'smooth',block:'start'});
}
function resetTplForm() {
  ['tplEditId','tplEditName','tplEditCat','tplEditSubject','tplEditBody'].forEach(id=>{
    document.getElementById(id).value = id==='tplEditId'?'0':'';
  });
}

/* ── USE TEMPLATE → SWITCH TO QUICK SEND ── */
function useTemplate(tpl) {
  switchTab('send-now');
  document.getElementById('qsSubject').value = tpl.subject || '';
  document.getElementById('qsMessage').value = tpl.body    || '';
  document.getElementById('qsMessage').scrollIntoView({behavior:'smooth',block:'start'});
}

/* ── LOG FILTER ── */
function filterLog() {
  const q   = document.getElementById('logSearch').value.toLowerCase();
  const ch  = document.getElementById('logChFilter').value;
  const st  = document.getElementById('logStFilter').value;
  const rows = document.querySelectorAll('#logTbody tr');
  let vis = 0;
  rows.forEach(r=>{
    const ok = (!q  || r.dataset.search.includes(q))
            && (!ch || r.dataset.channel===ch)
            && (!st || r.dataset.status===st);
    r.style.display = ok?'':'none';
    if (ok) vis++;
  });
  document.getElementById('logCount').textContent = vis+' entries';
}

/* ── FORM SPINNERS ── */
['ruleForm','quickSendForm'].forEach(id=>{
  const f = document.getElementById(id);
  if (!f) return;
  f.addEventListener('submit',function(){
    const btn = this.querySelector('button[type=submit]');
    if (!btn) return;
    btn.disabled = true;
    const orig = btn.innerHTML;
    btn.innerHTML = `<svg class="spinner" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg> Processing…`;
    setTimeout(()=>{ btn.disabled=false; btn.innerHTML=orig; }, 8000);
  });
});
</script>
</body>
</html>