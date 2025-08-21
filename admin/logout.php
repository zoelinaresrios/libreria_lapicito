<?php
require_once __DIR__ . '/../includes/auth.php';
session_destroy();
header('Location: /los-lapicitos/admin/login.php');
