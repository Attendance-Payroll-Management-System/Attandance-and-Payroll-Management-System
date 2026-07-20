<?php
$conn = new mysqli('localhost', 'root', '', 'payroll');
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

echo "=== SHOW CREATE TABLE overtime_requests ===\n";
$r = $conn->query("SHOW CREATE TABLE overtime_requests");
$row = $r->fetch_assoc();
echo $row['Create Table'] . "\n\n";

echo "=== SHOW COLUMNS FROM overtime_requests ===\n";
$r = $conn->query("SHOW COLUMNS FROM overtime_requests");
while ($row = $r->fetch_assoc()) {
    echo implode("\t", array_values($row)) . "\n";
}
echo "\n";

echo "=== SHOW CREATE TABLE overtime_settings ===\n";
$r = $conn->query("SHOW CREATE TABLE overtime_settings");
$row = $r->fetch_assoc();
echo $row['Create Table'] . "\n\n";

echo "=== SHOW CREATE TABLE overtime_logs ===\n";
$r = $conn->query("SHOW CREATE TABLE overtime_logs");
$row = $r->fetch_assoc();
echo $row['Create Table'] . "\n\n";

echo "=== SHOW CREATE TABLE overtime ===\n";
$r = $conn->query("SHOW CREATE TABLE overtime");
$row = $r->fetch_assoc();
echo $row['Create Table'] . "\n\n";

$conn->close();
unlink(__FILE__);
