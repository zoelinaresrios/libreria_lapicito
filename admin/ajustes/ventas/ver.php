<?php
// /admin/ventas/ver.php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';

if (function_exists('is_logged') && !is_logged()) { header('Location: /admin/login.php'); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (session_status()===PHP_SESSION_NONE) session_start();

$id = (int)($_GET['id'] ?? 0);
if ($id<=0){ header('Location: /admin/ventas/historial.php'); exit; }

$sale = $conexion->query("SELECT v.*, u.nombre AS usuario FROM venta v JOIN usuario u ON u.id_usuario=v.id_usuario WHERE v.id_venta=$id")->fetch_assoc();
if (!$sale){ header('Location: /admin/ventas/historial.php'); exit; }

$det = $conexion->query("
  SELECT d.id_producto, p.nombre, d.cantidad, d.precio_unitario
  FROM venta_detalle d
  JOIN producto p ON p.id_producto=d.id_producto
  WHERE d.id_venta=$id
")->fetch_all(MYSQLI_ASSOC);

// anulada?
$conexion->query("CREATE TABLE IF NOT EXISTS venta_anulada (id_venta BIGINT(20) UNSIGNED PRIMARY KEY, fecha DATETIME NOT NULL, id_usuario INT(10) UNSIGNED NOT NULL, motivo VARCHAR(200) NULL)");
$an = $conexion->query("SELECT 1 FROM venta_anulada WHERE id_venta=$id")->fetch_row();
$anulada = (bool)$an;

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Venta #<?= (int)$id ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/vendor/normalize.css?v=2">
  <link rel="stylesheet" href="/vendor/skeleton.css?v=3">
  <link rel="stylesheet" href="/css/style.css?v=13">
  <style>.wrap{padding:16px}.badge-anulada{background:#e74c3c;color:#fff;padding:2px 8px;border-radius:10px}</style>
</head>
<body>
  <div class="barra"></div>
  <div class="wrap">
    <h5>Venta #<?= (int)$id ?> <?= $anulada?'<span class="badge-anulada">Anulada</span>':'' ?></h5>
    <p><strong>Fecha:</strong> <?= h($sale['fecha_hora']) ?> · <strong>Usuario:</strong> <?= h($sale['usuario']) ?> · <strong>Sucursal:</strong> <?= (int)$sale['id_sucursal'] ?></p>

    <div class="table-wrap">
      <table class="u-full-width">
        <thead><tr><th>Producto</th><th style="width:120px">Cantidad</th><th style="width:140px">Precio</th><th style="width:140px">Subtotal</th></tr></thead>
        <tbody>
          <?php foreach($det as $d): ?>
          <tr>
            <td><?= h($d['nombre']) ?></td>
            <td><?= (int)$d['cantidad'] ?></td>
            <td>$ <?= number_format((float)$d['precio_unitario'],2,',','.') ?></td>
            <td>$ <?= number_format((float)$d['precio_unitario']*(int)$d['cantidad'],2,',','.') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <p style="text-align:right;font-size:18px"><strong>Total:</strong> $ <?= number_format((float)$sale['total'],2,',','.') ?></p>

    <a class="button" href="/admin/ventas/historial.php">Volver</a>
  </div>
</body>
</html>
