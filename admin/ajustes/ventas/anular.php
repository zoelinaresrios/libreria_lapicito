<?php
// /admin/ventas/anular.php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';

$HAS_ACL = file_exists(__DIR__ . '/../includes/acl.php');
if ($HAS_ACL) { require_once __DIR__ . '/../includes/acl.php'; }
else {
  if (session_status()===PHP_SESSION_NONE) session_start();
  if (!function_exists('require_perm')) { function require_perm($k){ return true; } }
}
require_perm('ventas.anular');

if (session_status()===PHP_SESSION_NONE) session_start();
if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { die('CSRF'); }

$id_venta = (int)($_POST['id'] ?? 0);
$id_usuario = (int)($_SESSION['user']['id_usuario'] ?? 0);

if ($id_venta<=0){ header('Location: /admin/ventas/historial.php'); exit; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conexion->begin_transaction();
try {
  // aseguro tabla auxiliar
  $conexion->query("CREATE TABLE IF NOT EXISTS venta_anulada (
    id_venta BIGINT(20) UNSIGNED PRIMARY KEY,
    fecha DATETIME NOT NULL,
    id_usuario INT(10) UNSIGNED NOT NULL,
    motivo VARCHAR(200) NULL
  ) ENGINE=InnoDB");

  // chequeo si ya está
  $ya = $conexion->query("SELECT 1 FROM venta_anulada WHERE id_venta=$id_venta")->fetch_row();
  if ($ya){ throw new Exception('La venta ya está anulada.'); }

  // traigo cabecera y detalles
  $v = $conexion->query("SELECT * FROM venta WHERE id_venta=$id_venta")->fetch_assoc();
  if (!$v){ throw new Exception('Venta inexistente'); }
  $det = $conexion->query("SELECT id_producto, cantidad, precio_unitario FROM venta_detalle WHERE id_venta=$id_venta")->fetch_all(MYSQLI_ASSOC);

  $id_sucursal = (int)$v['id_sucursal'];

  // reponer stock + inventario_mov ingreso
  $updInv = $conexion->prepare("UPDATE inventario SET stock_actual=stock_actual+? WHERE id_sucursal=? AND id_producto=?");
  $selInv = $conexion->prepare("SELECT stock_actual FROM inventario WHERE id_sucursal=? AND id_producto=? LIMIT 1");
  $insMov = $conexion->prepare("INSERT INTO inventario_mov (id_producto,tipo,cantidad,motivo,stock_prev,stock_nuevo,id_usuario,creado_en) VALUES (?,'ingreso',?,?,?,?,?,NOW())");

  foreach($det as $d){
    $idp=(int)$d['id_producto']; $cant=(int)$d['cantidad'];

    $selInv->bind_param('ii',$id_sucursal,$idp);
    $selInv->execute();
    $prev = (int)($selInv->get_result()->fetch_assoc()['stock_actual'] ?? 0);

    $updInv->bind_param('iii',$cant,$id_sucursal,$idp);
    $updInv->execute();

    $nuevo = $prev + $cant;
    $motivo = 'Anulación venta #'.$id_venta;
    $insMov->bind_param('isiisi',$idp,$cant,$motivo,$prev,$nuevo,$id_usuario);
    $insMov->execute();
  }
  $updInv->close(); $selInv->close(); $insMov->close();

  // marco anulación
  $stmt = $conexion->prepare("INSERT INTO venta_anulada (id_venta,fecha,id_usuario,motivo) VALUES (?,NOW(),?,?)");
  $motivo = $_POST['motivo'] ?? '';
  $stmt->bind_param('iis',$id_venta,$id_usuario,$motivo);
  $stmt->execute(); $stmt->close();

  $conexion->commit();
  header('Location: /admin/ventas/ver.php?id='.$id_venta);
} catch(Throwable $e){
  $conexion->rollback();
  http_response_code(500);
  echo "No se pudo anular: ".$e->getMessage();
}
