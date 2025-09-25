<?php
// /admin/ventas/cierre.php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';
if (function_exists('is_logged') && !is_logged()) { header('Location: /admin/login.php'); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (session_status()===PHP_SESSION_NONE) session_start();

$fecha = $_GET['fecha'] ?? date('Y-m-d');
$suc   = (int)($_GET['suc'] ?? ($_SESSION['user']['id_sucursal'] ?? 1));

$conexion->query("CREATE TABLE IF NOT EXISTS venta_anulada (id_venta BIGINT(20) UNSIGNED PRIMARY KEY, fecha DATETIME NOT NULL, id_usuario INT(10) UNSIGNED NOT NULL, motivo VARCHAR(200) NULL)");

$sql = "
SELECT u.nombre,
       COUNT(v.id_venta) AS ventas,
       COALESCE(SUM(CASE WHEN va.id_venta IS NULL THEN v.total ELSE 0 END),0) AS total_neto
FROM venta v
JOIN usuario u ON u.id_usuario=v.id_usuario
LEFT JOIN venta_anulada va ON va.id_venta=v.id_venta
WHERE DATE(v.fecha_hora)=? AND v.id_sucursal=?
GROUP BY u.id_usuario, u.nombre
ORDER BY u.nombre";
$st=$conexion->prepare($sql); $st->bind_param('si',$fecha,$suc); $st->execute();
$rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

$granTotal = 0.0; foreach($rows as $r){ $granTotal += (float)$r['total_neto']; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Cierre diario</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/vendor/normalize.css?v=2">
  <link rel="stylesheet" href="/vendor/skeleton.css?v=3">
  <link rel="stylesheet" href="/css/style.css?v=13">
  <style>.wrap{padding:16px}</style>
</head>
<body>
  <div class="barra"></div>
  <div class="wrap">
    <h5>Cierre diario</h5>
    <form class="row" method="get" style="gap:8px;align-items:center">
      <input type="date" name="fecha" value="<?= h($fecha) ?>">
      <input type="number" name="suc" value="<?= (int)$suc ?>" placeholder="Sucursal">
      <button class="button-primary">Ver</button>
      <a class="button" href="/admin/ventas/">Punto de venta</a>
    </form>

    <div class="table-wrap" style="margin-top:10px">
      <table class="u-full-width">
        <thead><tr><th>Usuario</th><th>Ventas</th><th>Total neto</th></tr></thead>
        <tbody>
        <?php if(empty($rows)): ?>
          <tr><td colspan="3" class="muted">Sin datos.</td></tr>
        <?php else: foreach($rows as $r): ?>
          <tr>
            <td><?= h($r['nombre']) ?></td>
            <td><?= (int)$r['ventas'] ?></td>
            <td>$ <?= number_format((float)$r['total_neto'],2,',','.') ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
        <tfoot>
          <tr><th colspan="2" style="text-align:right">Total del día</th><th>$ <?= number_format($granTotal,2,',','.') ?></th></tr>
        </tfoot>
      </table>
    </div>
  </div>
</body>
</html>
