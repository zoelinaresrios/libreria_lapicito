<?php
$host = 'localhost';
$user = 'root';
$password = '';
$database = 'libreria';

$conexion = new mysqli($host, $user, $password, $database);
if ($conexion->connect_error) {
   if ($conexion->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Conexión fallida']);
    exit;
}

}
?>
