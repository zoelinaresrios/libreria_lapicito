<?php
//Funcion para cerrar la sesion
require_once __DIR__ . '/../includes/auth.php';
session_destroy();
header('Location: /libreria_lapicito/admin/login.php');
