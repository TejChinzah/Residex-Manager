<?php
require_once '../includes/config.php';
requireLogin('admin');

$receipt = $_GET['receipt'] ?? '';
if (!$receipt) {
    header('Location: payments.php');
    exit();
}
header('Location: ../user/receipt.php?receipt=' . urlencode($receipt));
exit();
