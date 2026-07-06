<?php
$envFile = __DIR__ . '/.env.php';
if (!file_exists($envFile)) {
    die('Missing config/.env.php — copy config/.env.example.php to config/.env.php and configure your credentials.');
}
$env = require $envFile;

$servername = $env['db_host'] ?? 'localhost';
$username   = $env['db_user'] ?? 'root';
$password   = $env['db_pass'] ?? '';
$dbname     = $env['db_name'] ?? 'payroll';

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
