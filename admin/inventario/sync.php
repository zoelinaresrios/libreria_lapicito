<?php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';

if ($HAS_ACL = file_exists(__DIR__ . '/../includes/acl.php')) require_once __DIR__ . '/../includes/acl.php';
else { if (session_status()===PHP_SESSION_NONE) session_start();
  if (!function_exists('require_perm')) { function require_perm($k){ return true; } }
}
require_perm('inventario.ajustar');

$conexion->query("INSERT IGNORE INTO inventario (id_producto,stock_actual,stock_minimo)
                  SELECT p.id_producto,0,0 FROM producto p
                  LEFT JOIN inventario i ON i.id_producto=p.id_producto
                  WHERE i.id_producto IS NULL");

if (session_status()===PHP_SESSION_NONE) session_start();
$_SESSION['flash_ok']='Inventario sincronizado: filas creadas para productos sin registro.';
header('Location: /admin/inventario/');
exit;
