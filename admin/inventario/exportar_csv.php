<?php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';

if ($HAS_ACL = file_exists(__DIR__ . '/../includes/acl.php')) require_once __DIR__ . '/../includes/acl.php';
else { if (session_status()===PHP_SESSION_NONE) session_start();
  if (!function_exists('require_perm')) { function require_perm($k){ return true; } }
}
require_perm('inventario.ver');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Asegurar filas inventario para todos
$conexion->query("INSERT IGNORE INTO inventario (id_producto,stock_actual,stock_minimo)
                  SELECT p.id_producto,0,0 FROM producto p
                  LEFT JOIN inventario i ON i.id_producto=p.id_producto
                  WHERE i.id_producto IS NULL");

$sql="
  SELECT p.id_producto, p.nombre,
         COALESCE(i.stock_actual,0) AS stock_actual,
         COALESCE(i.stock_minimo,0) AS stock_minimo
  FROM producto p
  LEFT JOIN inventario i ON i.id_producto=p.id_producto
  ORDER BY p.id_producto
";
$res=$conexion->query($sql);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="inventario_'.date('Y-m-d_His').'.csv"');

$out=fopen('php://output','w');
fputcsv($out, ['id_producto','nombre','stock_actual','stock_minimo']);
while($row=$res->fetch_assoc()){
  fputcsv($out, [(int)$row['id_producto'], $row['nombre'], (int)$row['stock_actual'], (int)$row['stock_minimo']]);
}
fclose($out);
exit;
