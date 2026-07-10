<?php
require_once '../../config/auth.php';
require_admin_login();
require_once '../../config/db.php';

header('Content-Type: application/json');

$department_id = intval($_GET['department_id'] ?? 0);

if ($department_id <= 0) {
    echo json_encode(['found' => false]);
    exit;
}

$stmt = $conn->prepare("
    SELECT
        e.name AS manager_name,
        e.department_id,
        d.department_name,
        p.position_name AS manager_position
    FROM employee e
    LEFT JOIN positions p ON e.position_id = p.id
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE e.status = 'active'
    AND e.department_id = ?
    AND e.id = (
        SELECT MIN(e2.id) FROM employee e2
        WHERE e2.status = 'active' AND e2.department_id = ?
    )
");
$stmt->bind_param('ii', $department_id, $department_id);
$stmt->execute();
$manager = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($manager) {
    echo json_encode([
        'found' => true,
        'manager_name' => $manager['manager_name'],
        'department_name' => $manager['department_name'],
        'manager_position' => $manager['manager_position'] ?? '-'
    ]);
} else {
    echo json_encode(['found' => false]);
}
