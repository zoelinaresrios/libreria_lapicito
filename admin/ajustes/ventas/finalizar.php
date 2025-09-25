<?php
// /admin/ventas/finalizar.php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';

$HAS_ACL = file_exists(__DIR__ . '/../includes/acl.php');
if ($HAS_ACL) { require_once __DIR__ . '/../includes/acl.php'; }
else {
  if (session_status()===PHP_SESSION_NONE) session_start();
  if (!function_exists('require_perm')) { function require_perm($k){ return true; } }
}
require_perm('ventas.rapidas');

if (session_status()===PHP_SESSION_NONE) session_start();
if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { die('CSRF'); }

$items = array_values($_SESSION['pos_cart'] ?? []);
if (empty($items)) { header('Location: /admin/ventas/'); exit; }

$id_usuario  = (int)($_SESSION['user']['id_usuario'] ?? 0);
$id_sucursal = (int)($_POST['id_sucursal'] ?? ($_SESSION['user']['id_sucursal'] ?? 1));
$obs = trim($_POST['observacion'] ?? '');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conexion->begin_transaction();

try {
  // Insert venta
  $total = 0.0;
  foreach($items as $it){ $total += $it['precio'] * $it['cant']; }

  $stmt = $conexion->prepare("INSERT INTO venta (id_sucursal,id_usuario,fecha_hora,total) VALUES (?,?,NOW(),?)");
  $stmt->bind_param('iid',$id_sucursal,$id_usuario,$total);
  $stmt->execute();
  $id_venta = $stmt->insert_id;
  $stmt->close();

  // Detalles + descuento de stock + inventario_mov (egreso)
  $insDet = $conexion->prepare("INSERT INTO venta_detalle (id_venta,id_producto,cantidad,precio_unitario) VALUES (?,?,?,?)");
  $updInv = $conexion->prepare("UPDATE inventario SET stock_actual=GREATEST(0,stock_actual-?) WHERE id_sucursal=? AND id_producto=?");
  $selInv = $conexion->prepare("SELECT id_inventario, stock_actual FROM inventario WHERE id_sucursal=? AND id_producto=? LIMIT 1");
  $insInvMov = $conexion->prepare("INSERT INTO inventario_mov (id_producto,tipo,cantidad,motivo,stock_prev,stock_nuevo,id_usuario,creado_en) VALUES (?,'egreso',?,?,?,?,?,NOW())");

  foreach($items as $it){
    $idp=(int)$it['id']; $cant=(int)$it['cant']; $precio=(float)$it['precio'];
    if ($cant<=0) continue;

    // detalle
    $insDet->bind_param('iiid',$id_venta,$idp,$cant,$precio);
    $insDet->execute();

    // stock anterior
    $selInv->bind_param('ii',$id_sucursal,$idp);
    $selInv->execute();
    $inv = $selInv->get_result()->fetch_assoc();
    $prev = (int)($inv['stock_actual'] ?? 0);

    // descuento stock
    if ($inv){
      $updInv->bind_param('iii',$cant,$id_sucursal,$idp);
      $updInv->execute();
      $nuevo = max(0,$prev-$cant);
    } else {
      // si no existe inventario para esa sucursal, no descuenta (o podrías crearlo)
      $nuevo = $prev;
    }

    // inventario_mov
    $motivo = 'Venta #'.$id_venta.($obs?(' - '.$obs):'');
    $insInvMov->bind_param('isiisi',$idp,$cant,$motivo,$prev,$nuevo,$id_usuario);
    $insInvMov->execute();
  }

  $insDet->close(); $updInv->close(); $selInv->close(); $insInvMov->close();

  // Limpio carrito
  $_SESSION['pos_cart'] = [];
  $conexion->commit();

  header('Location: /admin/ventas/ver.php?id='.$id_venta);
} catch (Throwable $e) {
  $conexion->rollback();
  http_response_code(500);
  echo "Error al finalizar venta: ".$e->getMessage();
}
