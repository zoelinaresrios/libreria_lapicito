<?php
$host = 'localhost';
$user = 'u156482620_Zava';        
$password = 'Zava4567';          
$database = 'u156482620_libreria';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conexion = new mysqli($host, $user, $password, $database);
$conexion->set_charset('utf8mb4');

if ($conexion->connect_error) {
    die("Error de conexión: " . $conexion->connect_error);
}
?>
