<?php
// /admin/proveedores/eliminar.php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';

$HAS_ACL = file_exists(__DIR__ . '/../includes/acl.php');
if ($HAS_ACL) { require_once __DIR__ . '/../includes/acl.php'; }
else {
  if (session_status()===PHP_SESSION_NONE) session_start();
  if (!function_exists('can')) { function can($k){ return true; } }
  if (!function_exists('require_perm')) { function require_perm($k){ return true; } }
}
require_perm('proveedores.borrar');

if (session_status()===PHP_SESSION_NONE) session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$id=(int)($_POST['id']??0);
$csrf=$_POST['csrf']??'';
if(!$id || empty($_SESSION['csrf']) || $csrf!==$_SESSION['csrf']){
  $_SESSION['flash_err']='Solicitud inválida.'; header('Location: /admin/proveedores/'); exit;
}

// Regla de seguridad: no borrar si tiene pedidos o productos asociados
$u1=$conexion->prepare("SELECT COUNT(*) c FROM pedido WHERE id_proveedor=?");
$u1->bind_param('i',$id); $u1->execute(); $c1=(int)$u1->get_result()->fetch_assoc()['c']; $u1->close();

$u2=$conexion->prepare("SELECT COUNT(*) c FROM producto WHERE id_proveedor=?");
$u2->bind_param('i',$id); $u2->execute(); $c2=(int)$u2->get_result()->fetch_assoc()['c']; $u2->close();

if($c1>0 || $c2>0){
  $_SESSION['flash_err']='No se puede eliminar: tiene registros vinculados (pedidos/productos).';
  header('Location: /admin/proveedores/'); exit;
}

$st=$conexion->prepare("DELETE FROM proveedor WHERE id_proveedor=?");
$st->bind_param('i',$id); $st->execute(); $st->close();

$_SESSION['flash_ok']='Proveedor eliminado.';
header('Location: /admin/proveedores/');
