<?php
// send_daily_challenge_emails.php
// Run this script via cron (recommended: 8:00 AM and 21:30 PM EAT)

require_once __DIR__ . '/msanii/includes/connect.php';
require_once __DIR__ . '/vendor/autoload.php'; // PHPMailer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Force timezone
date_default_timezone_set('Africa/Nairobi');

// =============================================
// CONFIGURATION
// =============================================

$run_type = isset($argv[1]) ? $argv[1] : 'both'; // cron can pass: morning, evening, both

$from_email = 'notifications@keychele.co.ke';
$from_name  = 'Keychele Team';

$smtp_host = 'mail.keychele.co.ke';
$smtp_user = 'notifications@keychele.co.ke';
$smtp_pass = 'King20241064.';
$smtp_port = 587;

// =============================================
// HELPER FUNCTIONS
// =============================================

function sendEmail($to_email, $to_name, $subject, $html_body) {
    global $from_email, $from_name, $smtp_host, $smtp_user, $smtp_pass, $smtp_port;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $smtp_host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtp_user;
        $mail->Password   = $smtp_pass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $smtp_port;

        $mail->setFrom($from_email, $from_name);
        $mail->addAddress($to_email, $to_name);

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body    = $html_body;
        $mail->AltBody = strip_tags($html_body);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email failed: " . $mail->ErrorInfo);
        return false;
    }
}

function getCurrentDay($start_date, $duration) {
    $today = new DateTime();
    $start = new DateTime($start_date);
    if ($today < $start) return 0;
    $interval = $today->diff($start);
    $day = $interval->days + 1;
    return min($day, $duration);
}

// =============================================
// MAIN LOGIC
// =============================================

$current_time = date('H') * 100 + date('i'); // e.g. 830 for 8:30

$send_morning   = in_array($run_type, ['morning', 'both']) || ($current_time >= 700 && $current_time <= 1000);
$send_reminders = in_array($run_type, ['evening', 'both']) || ($current_time >= 2100 && $current_time <= 2300);

if (!$send_morning && !$send_reminders) {
    echo "No sending scheduled at this time.\n";
    exit;
}

echo "Running at " . date('Y-m-d H:i:s') . " → morning: " . ($send_morning ? 'yes' : 'no') . " | reminders: " . ($send_reminders ? 'yes' : 'no') . "\n";

// Find all active challenges
$stmt = $pdo->prepare("
    SELECT 
        c.id, c.title, c.duration, c.start_date,
        cr.full_name, cr.email
    FROM challenges c
    JOIN creators cr ON c.creator_id = cr.id
    WHERE c.status = 'active'
      AND cr.email IS NOT NULL
      AND cr.email != ''
");
$stmt->execute();
$challenges = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sent_count = 0;

foreach ($challenges as $ch) {
    $current_day = getCurrentDay($ch['start_date'], $ch['duration']);
    if ($current_day < 1 || $current_day > $ch['duration']) continue;

    $name = $ch['full_name'] ?: 'Champion';
    $email = $ch['email'];

    // 1. Morning motivational email
    if ($send_morning) {
        $subject = "Day $current_day – You've Got This! 💪";
        $body = "
        <h2 style='color:#6d28d9;'>Good morning, $name!</h2>
        <p>Today is <strong>Day $current_day</strong> of your challenge:</p>
        <h3 style='color:#333;'>“{$ch['title']}”</h3>
        <p>One more step toward becoming the best version of yourself.<br>
           You are stronger than yesterday – go make today proud!</p>
        <p style='margin:30px 0;'>
            <a href='https://keychele.co.ke/msanii/index.php?page=challenge_progress' 
               style='background:#6d28d9; color:white; padding:12px 24px; text-decoration:none; border-radius:8px;'>
                View Your Progress
            </a>
        </p>
        <p style='color:#555; font-size:0.95em;'>Keep shining,<br>Keychele Team</p>";

        if (sendEmail($email, $name, $subject, $body)) {
            $sent_count++;
            echo "Sent morning motivation to $email\n";
        }
    }

    // 2. Evening reminder – only if not yet marked
    if ($send_reminders) {
        // Check if today's day is already completed
        $check = $pdo->prepare("
            SELECT completed 
            FROM challenge_progress 
            WHERE challenge_id = ? AND day = ?
        ");
        $check->execute([$ch['id'], $current_day]);
        $row = $check->fetch(PDO::FETCH_ASSOC);

        if (!$row || !$row['completed']) {
            $subject = "Hey $name – Did you complete Day $current_day?";
            $body = "
            <h2 style='color:#6d28d9;'>Don't stop now, $name!</h2>
            <p>Today is <strong>Day $current_day</strong> of “{$ch['title']}”.</p>
            <p>Have you completed it yet?</p>
            <p style='font-size:1.1em; color:#333; margin:25px 0;'>
                <strong>Mark it done before midnight</strong> so you keep your streak alive!
            </p>
            <p style='margin:30px 0;'>
                <a href='https://keychele.co.ke/msanii/index.php?page=mark_progress' 
                   style='background:#10b981; color:white; padding:14px 28px; text-decoration:none; border-radius:8px; font-weight:bold;'>
                    Mark Day $current_day Done
                </a>
            </p>
            <p style='color:#555; font-size:0.95em;'>You are so close – finish strong!<br>Keychele Team</p>";

            if (sendEmail($email, $name, $subject, $body)) {
                $sent_count++;
                echo "Sent reminder to $email\n";
            }
        } else {
            echo "Day $current_day already marked for $email → skipping reminder\n";
        }
    }
}

echo "Finished. Sent $sent_count emails.\n";