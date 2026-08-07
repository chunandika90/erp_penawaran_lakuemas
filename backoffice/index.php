<?php
require_once __DIR__ . '/../backoffice-shared/auth.php';
header('Location: ' . (current_user() ? 'dashboard.php' : 'login.php'));
exit;
