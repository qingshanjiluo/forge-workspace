<?php
require_once '../config.php';

$stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'scroll_announcement'");
$stmt->execute();
$row = $stmt->fetch();
jsonResponse(['content' => $row ? $row['setting_value'] : '']);
?>