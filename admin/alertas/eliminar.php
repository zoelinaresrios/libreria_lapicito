<?php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';

$HAS_ACL = file_exists(__DIR__ . '/../includes/acl.php');
if ($HAS_ACL) { require_once __DIR__ . '/../includes/acl.php'; }
else {
  if (session_status()===PHP_SESSION_NONE) session_start();
  if (!function_exists('can')) { function can($k){ return true; } }
  if (!function_exists('require_perm')) { function require_perm($k){ return true; } }
}
require_perm('alertas.eliminar');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (session_status()===PHP_SESSION_NONE) session_start();

if (empty($_POST['csrf']) || empty($_SESSION['csrf']) || $_POST['csrf']!==$_SESSION['csrf']) {
  http_response_code(400); exit('CSRF inválido');
}
$id = (int)($_POST['id'] ?? 0);
if ($id<=0){ http_response_code(400); exit('ID inválido'); }

$st = $conexion->prepare("DELETE FROM alerta WHERE id_alerta=?");
$st->bind_param('i',$id);
$st->execute();

$_SESSION['flash_ok'] = 'Alerta #'.$id.' eliminada.';
$back = $_SERVER['HTTP_REFERER'] ?? '/admin/alertas/';
header('Location: '.$back);
