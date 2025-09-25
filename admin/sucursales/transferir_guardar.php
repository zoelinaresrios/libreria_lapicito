<?php
// /admin/sucursales/transferir_guardar.php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';
$HAS_ACL = file_exists(__DIR__ . '/../includes/acl.php');
if ($HAS_ACL) { require_once __DIR__ . '/../includes/acl.php'; }
else {
  if (session_status()===PHP_SESSION_NONE) session_start();
  if (!function_exists('can')) { function can($k){ return true; } }
  if (!function_exists('require_perm')) { function require_perm($k){ return true; } }
}
require_perm('movimientos.crear');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (session_status()===PHP_SESSION_NONE) session_start();
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// CSRF
if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
  http_response_code(400); exit('CSRF');
}

// Datos
$id_origen  = (int)($_POST['id_sucursal_origen'] ?? 0);
$id_destino = (int)($_POST['id_sucursal_destino'] ?? 0);
$obs        = trim($_POST['observacion'] ?? '');
$prods      = $_POST['prod'] ?? [];

if ($id_origen<=0 || $id_destino<=0 || $id_origen===$id_destino) {
  $_SESSION['flash_err'] = 'Seleccioná origen y destino distintos.'; 
  header('Location: /admin/sucursales/transferir.php'); exit;
}
if (empty($prods) || !is_array($prods)) {
  $_SESSION['flash_err'] = 'Agregá al menos un producto.'; 
  header('Location: /admin/sucursales/transferir.php'); exit;
}

// Detectar id_tipo_movimiento para "Transferencia"
$st = $conexion->prepare("SELECT id_tipo_movimiento FROM tipo_movimiento WHERE LOWER(nombre_tipo)=LOWER('Transferencia') LIMIT 1");
$st->execute();
$trow = $st->get_result()->fetch_assoc(); $st->close();
$id_tipo_mov = (int)($trow['id_tipo_movimiento'] ?? 2); // fallback a 2 si lo manejaste así

// Usuario (si lo tenés en sesión)
$uid = 0;
if (!empty($_SESSION['user']['id_usuario'])) $uid = (int)$_SESSION['user']['id_usuario'];

$conexion->begin_transaction();
try {
  // Crear movimiento cabecera
  $st = $conexion->prepare("
    INSERT INTO movimiento (id_tipo_movimiento, id_usuario, id_sucursal_origen, id_sucursal_destino, fecha_hora, observacion)
    VALUES (?, ?, ?, ?, NOW(), ?)
  ");
  $st->bind_param('iiiss', $id_tipo_mov, $uid, $id_origen, $id_destino, $obs);
  $st->execute();
  $id_mov = $conexion->insert_id;
  $st->close();

  // Preparados
  $selInv = $conexion->prepare("SELECT id_inventario, stock_actual FROM inventario WHERE id_sucursal=? AND id_producto=? LIMIT 1");
  $insInv = $conexion->prepare("INSERT INTO inventario (id_sucursal,id_producto,stock_actual,stock_minimo,ubicacion,actualizado_en) VALUES (?,?,?,?,?,NOW())");
  $updInv = $conexion->prepare("UPDATE inventario SET stock_actual=?, actualizado_en=NOW() WHERE id_inventario=?");

  $insDet = $conexion->prepare("INSERT INTO movimiento_detalle (id_movimiento,id_producto,cantidad,precio_unitario) VALUES (?,?,?,0)");

  foreach($prods as $p){
    $idp = (int)($p['id_producto'] ?? 0);
    $cant= max(1,(int)($p['cantidad'] ?? 0));
    if ($idp<=0 || $cant<=0) { throw new Exception('Producto o cantidad inválida'); }

    // ORIGEN: validar y debitar
    $selInv->bind_param('ii',$id_origen,$idp);
    $selInv->execute(); $res = $selInv->get_result()->fetch_assoc();
    if (!$res || (int)$res['stock_actual'] < $cant) {
      throw new Exception("Stock insuficiente en origen para producto #$idp");
    }
    $nuevo = (int)$res['stock_actual'] - $cant;
    $updInv->bind_param('ii',$nuevo,$res['id_inventario']);
    $updInv->execute();

    // DESTINO: crear si no existe y acreditar
    $selInv->bind_param('ii',$id_destino,$idp);
    $selInv->execute(); $res2 = $selInv->get_result()->fetch_assoc();
    if (!$res2) {
      $min = 0; $ubi = '';
      $insInv->bind_param('iiiss',$id_destino,$idp,$cant,$min,$ubi);
      $insInv->execute();
    } else {
      $nuevo2 = (int)$res2['stock_actual'] + $cant;
      $updInv->bind_param('ii',$nuevo2,$res2['id_inventario']);
      $updInv->execute();
    }

    // Detalle de movimiento
    $insDet->bind_param('iii',$id_mov,$idp,$cant);
    $insDet->execute();
  }

  $conexion->commit();
  $_SESSION['flash_ok'] = 'Transferencia registrada (#'.$id_mov.').';
  header('Location: /admin/sucursales/'); exit;

} catch (Throwable $e) {
  $conexion->rollback();
  $_SESSION['flash_err'] = 'No se pudo completar la transferencia: '.$e->getMessage();
  header('Location: /admin/sucursales/transferir.php'); exit;
}
