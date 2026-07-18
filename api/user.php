<?php
require_once '../config.php';
requireLogin();

$user = getCurrentUser();
jsonResponse(['name' => $user['username'], 'role' => $user['role']]);