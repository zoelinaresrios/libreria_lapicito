<?php
// /admin/ventas/buscar.php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
if ($q === '') { echo json_encode([]); exit; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Busco por nombre o código y traigo stock de la sucursal del usuario (si existe) sumado
$id_sucursal = (int)($_SESSION['user']['id_sucursal'] ?? 0);

$sql = "
SELECT 
  p.id_producto   AS id,
  p.nombre,
  p.codigo,
  p.precio_venta  AS precio,
  COALESCE(SUM(i.stock_actual),0) AS stock
FROM producto p
LEFT JOIN inventario i ON i.id_producto=p.id_producto
WHERE (p.nombre LIKE ? OR p.codigo LIKE ?)
GROUP BY p.id_producto, p.nombre, p.codigo, p.precio_venta
ORDER BY p.nombre
LIMIT 40";
$like = "%$q%";
$st = $conexion->prepare($sql);
$st->bind_param('ss',$like,$like);
$st->execute();
$res = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

foreach($res as &$r){ $r['precio']=(float)$r['precio']; $r['stock']=(int)$r['stock']; }
echo json_encode($res);
