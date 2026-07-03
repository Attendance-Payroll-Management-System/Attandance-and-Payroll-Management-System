<?php
/**
 * Email helper - saves emails locally when SMTP is not configured.
 * When SMTP credentials are set, sends via PHPMailer.
 */

require_once __DIR__ . '/../vendor/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/../vendor/PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;

function smtp_mail($to, $subject, $htmlBody, $config, $attachments = []) {
    // If SMTP is properly configured, send via PHPMailer
    if (!empty($config['username']) && !empty($config['password'])) {
        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host       = $config['host'];
        $mailer->Port       = $config['port'];
        $mailer->Username   = $config['username'];
        $mailer->Password   = $config['password'];
        $mailer->SMTPAuth   = true;
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->CharSet    = 'UTF-8';
        $mailer->setFrom($config['from'] ?? 'noreply@aura-hr.com', $config['fromName'] ?? 'AURA HR');
        $mailer->addAddress($to);
        $mailer->isHTML(true);
        $mailer->Subject = $subject;
        $mailer->Body    = $htmlBody;

        foreach ($attachments as $filename => $content) {
            if (is_int($filename)) {
                $mailer->addAttachment($content);
            } else {
                $mailer->addStringAttachment($content, $filename, PHPMailer::ENCODING_BASE64, 'application/pdf');
            }
        }

        $mailer->send();
        return true;
    }

    // Fallback: save email locally for testing
    $mailDir = __DIR__ . '/../storage/emails';
    if (!is_dir($mailDir)) {
        mkdir($mailDir, 0755, true);
    }

    $timestamp = date('Y-m-d_H-i-s');
    $safeEmail = preg_replace('/[^a-zA-Z0-9]/', '_', $to);
    $filename = "{$timestamp}_{$safeEmail}.html";
    $filepath = $mailDir . '/' . $filename;

    // Save PDF attachment if present
    foreach ($attachments as $fname => $content) {
        if (is_string($fname) && !is_int($fname)) {
            $pdfFile = $mailDir . '/' . $timestamp . '_' . $safeEmail . '_slip.pdf';
            file_put_contents($pdfFile, $content);
        }
    }

    // Save email HTML
    $fullHtml = "<!-- TO: {$to} -->\n<!-- SUBJECT: {$subject} -->\n<!-- DATE: " . date('Y-m-d H:i:s') . " -->\n{$htmlBody}";
    file_put_contents($filepath, $fullHtml);

    return true;
}
