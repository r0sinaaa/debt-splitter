<?php
// Sending email through SMTP with PHPMailer (included in includes/PHPMailer)

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

function emailConfigured(): bool {
    return SMTP_HOST !== '';
}

// Returns [status, error]: status is 'sent', 'logged' (email isn't set up, so it
// was written to storage/outbox.log) or 'failed'
function sendEmail(string $to, string $to_name, string $subject, string $body, ?array $reply_to = null): array {
    if (!emailConfigured()) {
        writeToOutbox("EMAIL to $to_name <$to>\nSubject: $subject\n\n$body");
        return ['logged', null];
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;
        if (SMTP_SECURE === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif (SMTP_SECURE === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPAutoTLS = false;
        }
        $mail->SMTPAuth = SMTP_USER !== '';
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->Timeout = 15;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;

        $mail->setFrom(MAIL_FROM !== '' ? MAIL_FROM : SMTP_USER, MAIL_FROM_NAME);
        $mail->addAddress($to, $to_name);
        if ($reply_to && !empty($reply_to['email'])) {
            $mail->addReplyTo($reply_to['email'], $reply_to['name']);   // replies go to the group's owner
        }
        $mail->Subject = $subject;
        $mail->Body = $body;   // plain text

        $mail->send();
        return ['sent', null];
    } catch (MailException $e) {
        return ['failed', $mail->ErrorInfo ?: $e->getMessage()];
    } catch (\Throwable $e) {
        return ['failed', $e->getMessage()];   // anything else, so one bad email doesn't stop the rest
    }
}

// While email isn't set up, messages are saved here so you can see what would be sent
function writeToOutbox(string $message): void {
    $dir = __DIR__ . '/../storage';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    file_put_contents($dir . '/outbox.log', '[' . date('Y-m-d H:i') . "] $message\n" . str_repeat('-', 60) . "\n", FILE_APPEND | LOCK_EX);
}
