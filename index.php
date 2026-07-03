<?php
session_start();

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: admin/dashboard.php');
} elseif (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: employee/dashboard.php');
} else {
    header('Location: employee/login.php');
}
exit;
