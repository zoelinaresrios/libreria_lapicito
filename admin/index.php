<?php
// /libreria_lapicito/admin/index.php  (SIN ESTILOS)
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user'])) { header('Location: /libreria_lapicito/admin/login.php'); exit; }

include(__DIR__ . '/../includes/db.php'); // crea $conexion (mysqli)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// --------- helpers ----------
function q(mysqli $cn, string $sql): array {
  try { $r = $cn->query($sql); return $r ? $r->fetch_all(MYSQLI_ASSOC) : []; }
  catch (Throwable $e) { return [['__error__'=>$e->getMessage()]]; }
}
function table_exists(mysqli $cn, string $t): bool {
  $st=$cn->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $st->bind_param('s',$t); $st->execute();
  return (bool)$st->get_result()->fetch_row();
}
function cols(mysqli $cn, string $t): array {
  $t = $cn->real_escape_string($t);
  try {
    $r = $cn->query("SHOW COLUMNS FROM `$t`");
    $out=[]; while($row=$r->fetch_assoc()){ $out[strtolower($row['Field'])]=true; }
    return $out;
  } catch (Throwable $e) { return []; }
}
function pick(array $cols, array $cands): ?string {
  foreach ($cands as $c) { if (isset($cols[strtolower($c)])) return $c; }
  return null;
}

// --------- filtros ----------
$desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-30 days'));
$hasta = $_GET['hasta'] ?? date('Y-m-d');

$notes = []; // mensajes de “falta tal cosa”

// --------- detección de nombres reales ----------
$venta_ok = table_exists($conexion,'venta');
$venta_cols = $venta_ok ? cols($conexion,'venta') : [];
$vFecha = $venta_ok ? pick($venta_cols, [
  'fecha_creada','fecha_venta','fecha','creado_en','fecha_estado','creada_en'
]) : null;
$vTotal = $venta_ok ? pick($venta_cols, [
  'total','importe_total','monto_total','total_neto','total_bruto','monto','importe'
]) : null;

if (!$venta_ok) { $notes[] = 'No existe la tabla "venta".'; }
if ($venta_ok && !$vFecha) { $notes[] = 'En "venta" no encuentro la columna de fecha (probé: fecha_creada/fecha_venta/fecha/creado_en/fecha_estado/creada_en).'; }
if ($venta_ok && !$vTotal) { $notes[] = 'En "venta" no encuentro la columna de total (probé: total/importe_total/monto_total/total_neto/total_bruto/monto/importe).'; }

// movimiento
$mov_ok   = table_exists($conexion,'movimiento');
$mov_cols = $mov_ok ? cols($conexion,'movimiento') : [];
$mFecha   = $mov_ok ? pick($mov_cols, ['fecha_hora','fecha_movimiento','fecha','creado_en']) : null;
$mTipo    = $mov_ok ? pick($mov_cols, ['id_tipo_movimiento','tipo_movimiento']) : null;
if ($mov_ok && !$mFecha) { $notes[] = 'En "movimiento" no encuentro columna de fecha (probé: fecha_hora/fecha_movimiento/fecha/creado_en).'; }
if ($mov_ok && !$mTipo)  { $notes[] = 'En "movimiento" no encuentro columna de tipo (probé: id_tipo_movimiento/tipo_movimiento).'; }

// --------- KPIs ----------
$totProductos = (int)(q($conexion, "SELECT COUNT(*) c FROM producto")[0]['c'] ?? 0);
$totBajoStock = (int)(q($conexion, "SELECT COUNT(*) c FROM inventario WHERE stock_actual <= stock_minimo")[0]['c'] ?? 0);

$ventasHoy = 0.0;
if ($venta_ok && $vFecha && $vTotal) {
  $ventasHoy = (float)(q($conexion, "SELECT COALESCE(SUM(`$vTotal`),0) t FROM venta WHERE DATE(`$vFecha`) = CURDATE()")[0]['t'] ?? 0);
}

// --------- ventas últimos 12 meses ----------
$ventasMensuales = [];
if ($venta_ok && $vFecha && $vTotal) {
  $ventasMensuales = q($conexion, "
    SELECT DATE_FORMAT(`$vFecha`,'%Y-%m') ym, ROUND(SUM(`$vTotal`),2) total
    FROM venta
    WHERE `$vFecha` >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY ym
    ORDER BY ym
  ");
}

// --------- top productos (rango) ----------
$desdeSQL = $conexion->real_escape_string($desde);
$hastaSQL = $conexion->real_escape_string($hasta);
$top = [];
if ($venta_ok && $vFecha) {
  $top = q($conexion, "
    SELECT p.nombre, SUM(vd.cantidad) unidades
    FROM venta_detalle vd
    JOIN venta v   ON v.id_venta = vd.id_venta
    JOIN producto p ON p.id_producto = vd.id_producto
    WHERE DATE(v.`$vFecha`) BETWEEN '$desdeSQL' AND '$hastaSQL'
    GROUP BY p.id_producto, p.nombre
    ORDER BY unidades DESC
    LIMIT 10
  ");
}

// --------- ingresos por categoría (movimiento ENTRADA=1) ----------
$ing = [];
if ($mov_ok && $mFecha && $mTipo) {
  $ing = q($conexion, "
    SELECT c.nombre categoria, SUM(md.cantidad) cant
    FROM movimiento_detalle md
    JOIN movimiento m ON m.id_movimiento = md.id_movimiento
    JOIN producto  p  ON p.id_producto   = md.id_producto
    JOIN categoria c  ON c.id_categoria  = p.id_categoria
    WHERE m.`$mTipo` = 1
      AND DATE(m.`$mFecha`) BETWEEN '$desdeSQL' AND '$hastaSQL'
    GROUP BY c.id_categoria, c.nombre
    ORDER BY cant DESC
    LIMIT 8
  ");
}

// --------- alertas recientes ----------
$alertas = q($conexion, "
  SELECT a.id_alerta, a.id_producto, a.atendida, a.fecha_creada, p.nombre AS producto
  FROM alerta a
  LEFT JOIN producto p ON p.id_producto = a.id_producto
  ORDER BY a.fecha_creada DESC
  LIMIT 8
");

// --------- pedidos pendientes (estado 1 = Pendiente) ----------
$pend = q($conexion, "
  SELECT p.id_pedido, pr.nombre AS proveedor, p.fecha_creada
  FROM pedido p
  LEFT JOIN proveedor pr ON pr.id_proveedor = p.id_proveedor
  WHERE p.id_estado_pedido = 1
  ORDER BY p.fecha_creada DESC
  LIMIT 8
");
?>
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><title>Admin - Dashboard (sin estilos)</title></head>
<body>

<h1>Dashboard — Los Lapicitos (sin estilos)</h1>

<?php if(!empty($notes)): ?>
  <h3>Notas</h3>
  <ul>
    <?php foreach($notes as $n): ?><li><?= htmlspecialchars($n) ?></li><?php endforeach; ?>
  </ul>
<?php endif; ?>

<h2>Filtros</h2>
<form method="get">
  Desde: <input type="date" name="desde" value="<?= htmlspecialchars($desde) ?>">
  Hasta: <input type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>">
  <button type="submit">Aplicar</button>
  <a href="?">Limpiar</a>
</form>

<h2>KPIs</h2>
<ul>
  <li>Total de productos: <strong><?= (int)$totProductos ?></strong></li>
  <li>Alertas de bajo stock: <strong><?= (int)$totBajoStock ?></strong></li>
  <li>Ventas hoy: <strong>$ <?= number_format((float)$ventasHoy,2) ?></strong></li>
</ul>

<h2>Ventas por mes (últimos 12 meses)</h2>
<table border="1" cellpadding="4" cellspacing="0">
  <tr><th>Mes</th><th>Total $</th></tr>
  <?php foreach($ventasMensuales as $r): ?>
    <tr><td><?= htmlspecialchars($r['ym']) ?></td><td><?= number_format((float)$r['total'],2) ?></td></tr>
  <?php endforeach; ?>
  <?php if(empty($ventasMensuales)): ?><tr><td colspan="2">Sin datos.</td></tr><?php endif; ?>
</table>

<h2>Top productos (<?= htmlspecialchars($desde) ?> → <?= htmlspecialchars($hasta) ?>)</h2>
<table border="1" cellpadding="4" cellspacing="0">
  <tr><th>Producto</th><th>Unidades</th></tr>
  <?php foreach($top as $r): ?>
    <tr><td><?= htmlspecialchars($r['nombre']) ?></td><td><?= (int)$r['unidades'] ?></td></tr>
  <?php endforeach; ?>
  <?php if(empty($top)): ?><tr><td colspan="2">Sin ventas en el rango.</td></tr><?php endif; ?>
</table>

<h2>Ingresos por categoría (movimientos de entrada)</h2>
<table border="1" cellpadding="4" cellspacing="0">
  <tr><th>Categoría</th><th>Cantidad</th></tr>
  <?php foreach($ing as $r): ?>
    <tr><td><?= htmlspecialchars($r['categoria']) ?></td><td><?= (int)$r['cant'] ?></td></tr>
  <?php endforeach; ?>
  <?php if(empty($ing)): ?><tr><td colspan="2">Sin movimientos de entrada en el rango.</td></tr><?php endif; ?>
</table>

<h2>Alertas recientes</h2>
<table border="1" cellpadding="4" cellspacing="0">
  <tr><th>ID</th><th>Producto</th><th>Fecha</th><th>Atendida</th></tr>
  <?php foreach($alertas as $a): ?>
    <tr>
      <td>#<?= (int)$a['id_alerta'] ?></td>
      <td><?= htmlspecialchars($a['producto'] ?? ('ID '.$a['id_producto'])) ?></td>
      <td><?= htmlspecialchars($a['fecha_creada'] ?? '') ?></td>
      <td><?= ((int)($a['atendida']??0)===1)?'Sí':'No' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if(empty($alertas)): ?><tr><td colspan="4">Sin alertas.</td></tr><?php endif; ?>
</table>

<h2>Pedidos pendientes</h2>
<table border="1" cellpadding="4" cellspacing="0">
  <tr><th>#</th><th>Proveedor</th><th>Fecha</th></tr>
  <?php foreach($pend as $p): ?>
    <tr>
      <td>#<?= (int)$p['id_pedido'] ?></td>
      <td><?= htmlspecialchars($p['proveedor'] ?? '—') ?></td>
      <td><?= htmlspecialchars($p['fecha_creada'] ?? '') ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if(empty($pend)): ?><tr><td colspan="3">Sin pedidos pendientes.</td></tr><?php endif; ?>
</table>

</body>
</html>
