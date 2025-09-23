<?php
// /admin/productos/toggle.php
declare(strict_types=1);

include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';

$HAS_ACL = file_exists(__DIR__ . '/../includes/acl.php');
if ($HAS_ACL) { require_once __DIR__ . '/../includes/acl.php'; }
else {
  if (session_status()===PHP_SESSION_NONE) session_start();
  if (!function_exists('can')) { function can($k){ return true; } }
  if (!function_exists('require_perm')) { function require_perm($k){ return true; } }
}

if (function_exists('is_logged') && !is_logged()) {
  header('Location: /admin/login.php'); exit;
}
require_perm('productos.activar'); // ajustá si tu ACL usa otro nombre

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (session_status()===PHP_SESSION_NONE) session_start();

function flash($msg,$type='ok'){ $_SESSION['flash']=['msg'=>$msg,'type'=>$type]; }

$id   = (int)($_POST['id'] ?? 0);
$to   = isset($_POST['to']) ? (int)$_POST['to'] : -1; // 1 = activar, 0 = desactivar
$csrf = (string)($_POST['csrf'] ?? '');
$motivo = trim((string)($_POST['motivo'] ?? ''));      // opcional

if ($id<=0 || ($to!==0 && $to!==1)) {
  flash('Parámetros inválidos.','no');
  header('Location: /admin/productos/'); exit;
}
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
  flash('Token inválido. Reintentá.','no');
  header('Location: /admin/productos/'); exit;
}

// Traer nombre para el mensaje
$stmt = $conexion->prepare("SELECT nombre FROM producto WHERE id_producto=? LIMIT 1");
$stmt->bind_param('i',$id);
$stmt->execute();
$prod = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$prod) {
  flash('Producto no encontrado.','no');
  header('Location: /admin/productos/'); exit;
}

// Usuario que hace la acción (si está en sesión)
$userId = (int)($_SESSION['user']['id_usuario'] ?? 0);

// IMPORTANTE: tu columna `actualizado_en` ya tiene ON UPDATE CURRENT_TIMESTAMP()
// así que no es necesario setearla a mano.
if ($to === 1) {
  // ACTIVAR: activo=1 y limpiar datos de baja
  $sql = "UPDATE producto
             SET activo=1,
                 fecha_baja=NULL,
                 motivo_baja=NULL,
                 baja_por=NULL
           WHERE id_producto=?";
  $stmt = $conexion->prepare($sql);
  $stmt->bind_param('i',$id);
} else {
  // DESACTIVAR: activo=0 y registrar baja
  // motivo_baja es opcional (si no lo mandás, queda NULL)
  $sql = "UPDATE producto
             SET activo=0,
                 fecha_baja=NOW(),
                 motivo_baja=?,
                 baja_por=?
           WHERE id_producto=?";
  $stmt = $conexion->prepare($sql);
  // si motivo es cadena vacía, guardamos NULL
  $motivoParam = ($motivo === '') ? NULL : $motivo;
  $stmt->bind_param('sii', $motivoParam, $userId, $id);
}

$stmt->execute();
$stmt->close();

flash('Producto «'.$prod['nombre'].'» '.($to===1?'activado':'desactivado').'.');
header('Location: /admin/productos/');
exit;
