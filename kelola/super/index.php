<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

requireSuperAdmin();

header('Location: ' . BASE_URL . '/kelola/index.php');
exit;