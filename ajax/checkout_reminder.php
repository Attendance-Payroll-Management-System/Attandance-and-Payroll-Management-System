<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || !isset($_SESSION['employee_id'])) {
    echo json_encode(['status' => 'unauthorized']);
    exit;
}

$employee_id = $_SESSION['employee_id'];
set_mmt_timezone();

$action = $_GET['action'] ?? $_POST['action'] ?? 'check';

if ($action === 'dismiss') {
    $reminder_id = (int)($_POST['reminder_id'] ?? 0);
    if ($reminder_id > 0) {
        dismiss_checkout_reminder($conn, $reminder_id);
    }
    echo json_encode(['status' => 'ok', 'dismissed' => true]);
    exit;
}

$level = needs_checkout_reminder($conn, $employee_id);

if ($level === null) {
    echo json_encode([
        'status' => 'ok',
        'eligible' => false,
        'level' => null,
        'message' => null,
        'unread_count' => get_unread_checkout_reminder_count($conn, $employee_id)
    ]);
    exit;
}

if (should_send_new_reminder($conn, $employee_id, $level)) {
    log_checkout_reminder($conn, $employee_id, $level);
}

$reminder = get_latest_pending_reminder($conn, $employee_id);
$urgency = get_checkout_reminder_urgency($level);
$message = get_checkout_reminder_message($level);

echo json_encode([
    'status' => 'ok',
    'eligible' => true,
    'level' => $level,
    'urgency' => $urgency,
    'message' => $message,
    'reminder_id' => $reminder['id'] ?? null,
    'sent_at' => $reminder['sent_at'] ?? null,
    'unread_count' => get_unread_checkout_reminder_count($conn, $employee_id),
    'current_time' => mmt_time('h:i A')
]);
