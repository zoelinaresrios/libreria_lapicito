<?php
// /libreria_lapicito/admin/index.php — SIN ESTILOS, ajustado a tu base real
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user'])) { header('Location: /libreria_lapicito/admin/login.php'); exit; }

include(__DIR__ . '/../includes/db.php'); // $conexion (mysqli)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ---------- helpers ----------
function q(mysqli $cn, string $sql, array &$notes, string $key): array {
  try { $r = $cn->query($sql); return $r ? $r->fetch_all(MYSQLI_ASSOC) : []; }
  catch (Throwable $e) { $notes[] = "Error en $key: ".$e->getMessage(); return []; }
}
function esc(mysqli $cn, string $s): string { return $cn->real_escape_string($s); }

// ---------- filtros ----------
$desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-30 days'));
$hasta = $_GET['hasta'] ?? date('Y-m-d');

$notes = [];

/* ------------- Puntos CLAVE de TU ESQUEMA -------------
   venta:         id_venta, fecha_hora, id_sucursal, id_usuario, total, observacion
   venta_detalle: id_venta_detalle, id_venta, id_producto, cantidad, precio_unitario, total
   producto:      id_producto, nombre, id_subcategoria, ...
   subcategoria:  id_subcategoria, id_categoria, nombre
   categoria:     id_categoria, nombre
   inventario:    id_inventario, id_sucursal, id_producto, stock_actual, stock_minimo
   movimiento:    id_movimiento, fecha_hora, id_tipo_movimiento, id_sucursal_origen, id_sucursal_destino, ...
   movimiento_detalle: id_movimiento_detalle, id_movimiento, id_producto, cantidad, ...
   alerta:        id_alerta, id_producto, fecha_creada, atendida, ...
   pedido:        id_pedido, id_proveedor, id_estado_pedido, fecha_creado, ...
   proveedor:     id_proveedor, nombre, ...
--------------------------------------------------------- */

// ---------- KPIs ----------
$totProductos = (int) (q($conexion, "SELECT COUNT(*) c FROM producto", $notes, 'kpi_productos')[0]['c'] ?? 0);

$totBajoStock = (int) (q($conexion, "
  SELECT COUNT(*) c
  FROM inventario
  WHERE stock_actual <= stock_minimo
", $notes, 'kpi_bajo_stock')[0]['c'] ?? 0);

// ventas hoy (venta.fecha_hora, venta.total)
$ventasHoy = (float) (q($conexion, "
  SELECT COALESCE(SUM(total),0) t
  FROM venta
  WHERE DATE(fecha_hora) = CURDATE()
", $notes, 'kpi_ventas_hoy')[0]['t'] ?? 0);

// ---------- ventas últimos 12 meses (venta.fecha_hora / venta.total) ----------
$ventasMensuales = q($conexion, "
  SELECT DATE_FORMAT(fecha_hora,'%Y-%m') ym, ROUND(SUM(total),2) total
  FROM venta
  WHERE fecha_hora >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
  GROUP BY ym
  ORDER BY ym
", $notes, 'ventas_mensuales');

// ---------- top productos por unidades (rango) ----------
$desdeSQL = esc($conexion,$desde);
$hastaSQL = esc($conexion,$hasta);
$top = q($conexion, "
  SELECT p.nombre, SUM(vd.cantidad) unidades
  FROM venta_detalle vd
  JOIN venta v   ON v.id_venta    = vd.id_venta
  JOIN producto p ON p.id_producto = vd.id_producto
  WHERE DATE(v.fecha_hora) BETWEEN '$desdeSQL' AND '$hastaSQL'
  GROUP BY p.id_producto, p.nombre
  ORDER BY unidades DESC
  LIMIT 10
", $notes, 'top_productos');

// ---------- ingresos por categoría (ENTRADA = 1) ----------
$ing = q($conexion, "
  SELECT c.nombre AS categoria, SUM(md.cantidad) AS cant
  FROM movimiento_detalle md
  JOIN movimiento m     ON m.id_movimiento = md.id_movimiento
  JOIN producto  p      ON p.id_producto   = md.id_producto
  JOIN subcategoria sc  ON sc.id_subcategoria = p.id_subcategoria
  JOIN categoria c      ON c.id_categoria  = sc.id_categoria
  WHERE m.id_tipo_movimiento = 1
    AND DATE(m.fecha_hora) BETWEEN '$desdeSQL' AND '$hastaSQL'
  GROUP BY c.id_categoria, c.nombre
  ORDER BY cant DESC
  LIMIT 8
", $notes, 'ingresos_por_categoria');

// ---------- alertas recientes ----------
$alertas = q($conexion, "
  SELECT a.id_alerta, a.id_producto, a.atendida, a.fecha_creada, p.nombre AS producto
  FROM alerta a
  LEFT JOIN producto p ON p.id_producto = a.id_producto
  ORDER BY a.fecha_creada DESC
  LIMIT 8
", $notes, 'alertas_recientes');

// ---------- pedidos pendientes (estado 1 = Pendiente) ----------
$pend = q($conexion, "
  SELECT p.id_pedido, pr.nombre AS proveedor, p.fecha_creado
  FROM pedido p
  LEFT JOIN proveedor pr ON pr.id_proveedor = p.id_proveedor
  WHERE p.id_estado_pedido = 1
  ORDER BY p.fecha_creado DESC
  LIMIT 8
", $notes, 'pedidos_pendientes');
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
      <td><?= htmlspecialchars($p['fecha_creado'] ?? '') ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if(empty($pend)): ?><tr><td colspan="3">Sin pedidos pendientes.</td></tr><?php endif; ?>
</table>

</body>
</html>
