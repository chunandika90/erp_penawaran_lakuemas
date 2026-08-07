<?php
require_once __DIR__ . '/../backoffice-shared/auth.php';
logout();
header('Location: login.php');
exit;
