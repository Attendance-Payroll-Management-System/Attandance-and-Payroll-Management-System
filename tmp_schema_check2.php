<?php
$conn = new mysqli('localhost', 'root', '', 'payroll');
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

$tables = ['overtime_settings', 'overtime_logs', 'overtime'];
foreach ($tables as $t) {
    echo "=== $t ===\n";
    $r = @$conn->query("SHOW CREATE TABLE $t");
    if ($r) {
        $row = $r->fetch_assoc();
        echo $row['Create Table'] . "\n\n";
    } else {
        echo "TABLE DOES NOT EXIST\n\n";
    }
}

$conn->close();
unlink(__FILE__);
