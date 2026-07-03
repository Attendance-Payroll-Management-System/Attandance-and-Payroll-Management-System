<?php
session_start();
require_once '../config/auth.php';
require_admin_login();

$mailDir = __DIR__ . '/../storage/emails';
$file = $_GET['file'] ?? '';
$action = $_GET['action'] ?? '';

// Sanitize filename
$file = basename($file);

if ($action === 'pdf') {
    $pdfFile = $mailDir . '/' . str_replace('.html', '_slip.pdf', $file);
    if (file_exists($pdfFile)) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($pdfFile) . '"');
        header('Content-Length: ' . filesize($pdfFile));
        readfile($pdfFile);
        exit;
    }
    die('PDF not found');
}

if ($action === 'view' || $action === '') {
    $htmlFile = $mailDir . '/' . $file;
    if (file_exists($htmlFile)) {
        $content = file_get_contents($htmlFile);
        $content = preg_replace('/<!--.*?-->/s', '', $content);
        
        echo '<!DOCTYPE html><html><head>';
        echo '<meta charset="UTF-8"><title>Email Preview</title>';
        echo '<link rel="stylesheet" href="../assets/css/app.css">';
        echo '<style>body{font-family:Arial,sans-serif;background:#f4f4f6;padding:40px;display:flex;justify-content:center;}</style>';
        echo '</head><body>';
        echo '<div style="max-width:600px;width:100%;">';
        echo '<div style="text-align:center;margin-bottom:20px;">';
        echo '<a href="email_log.php" style="color:#8B5CF6;font-size:14px;text-decoration:none;">← Back to Email Log</a>';
        echo '</div>';
        echo '<div style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">';
        echo $content;
        echo '</div></div></body></html>';
        exit;
    }
    die('Email not found');
}
