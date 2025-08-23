<?php
// /libreria_lapicito/admin/index.php — Skeleton + consultas adaptadas a tu esquema
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user'])) { header('Location: /libreria_lapicito/admin/login.php'); exit; }

include(__DIR__ . '/../includes/db.php'); // $conexion (mysqli)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// helpers
function q(mysqli $cn, string $sql, array &$notes, string $key){ try{$r=$cn->query($sql);return $r?$r->fetch_all(MYSQLI_ASSOC):[];}catch(Throwable $e){$notes[]="$key: ".$e->getMessage();return[];}}
function esc(mysqli $cn, string $s){ return $cn->real_escape_string($s); }

$page_title = 'Dashboard — Los Lapicitos';
$notes = [];

// filtros
$desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-30 days'));
$hasta = $_GET['hasta'] ?? date('Y-m-d');
$dSQL = esc($conexion,$desde); $hSQL = esc($conexion,$hasta);

/* TU ESQUEMA (resumen):
   venta(fecha_hora,total) · venta_detalle(id_venta,id_producto,cantidad)
   producto(id_producto,nombre,id_subcategoria) · subcategoria(id_subcategoria,id_categoria)
   categoria(id_categoria,nombre) · inventario(stock_actual,stock_minimo)
   movimiento(fecha_hora,id_tipo_movimiento) · movimiento_detalle(id_movimiento,id_producto,cantidad)
   alerta(fecha_creada,atendida) · pedido(fecha_creado,id_estado_pedido) · proveedor(nombre)
*/

// KPIs
$totProductos = (int) (q($conexion,"SELECT COUNT(*) c FROM producto",$notes,'kpi_productos')[0]['c'] ?? 0);
$totBajoStock = (int) (q($conexion,"SELECT COUNT(*) c FROM inventario WHERE stock_actual <= stock_minimo",$notes,'kpi_bajo_stock')[0]['c'] ?? 0);
$ventasHoy    = (float)(q($conexion,"SELECT COALESCE(SUM(total),0) t FROM venta WHERE DATE(fecha_hora)=CURDATE()",$notes,'kpi_ventas_hoy')[0]['t'] ?? 0);

// Ventas por mes (12m)
$ventasMensuales = q($conexion,"
  SELECT DATE_FORMAT(fecha_hora,'%Y-%m') ym, ROUND(SUM(total),2) total
  FROM venta
  WHERE fecha_hora >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
  GROUP BY ym ORDER BY ym
",$notes,'ventas_mensuales');
$chartMensualLabels = array_column($ventasMensuales,'ym');
$chartMensualData   = array_map('floatval', array_column($ventasMensuales,'total'));

// Top productos por unidades (rango)
$top = q($conexion,"
  SELECT p.nombre, SUM(vd.cantidad) unidades
  FROM venta_detalle vd
  JOIN venta v    ON v.id_venta    = vd.id_venta
  JOIN producto p ON p.id_producto = vd.id_producto
  WHERE DATE(v.fecha_hora) BETWEEN '$dSQL' AND '$hSQL'
  GROUP BY p.id_producto, p.nombre
  ORDER BY unidades DESC
  LIMIT 10
",$notes,'top_productos');
$chartTopLabels = array_column($top,'nombre');
$chartTopData   = array_map('intval', array_column($top,'unidades'));

// Ingresos por categoría (mov ENTRADA=1)
$ing = q($conexion,"
  SELECT c.nombre AS categoria, SUM(md.cantidad) AS cant
  FROM movimiento_detalle md
  JOIN movimiento m     ON m.id_movimiento = md.id_movimiento
  JOIN producto  p      ON p.id_producto   = md.id_producto
  JOIN subcategoria sc  ON sc.id_subcategoria = p.id_subcategoria
  JOIN categoria c      ON c.id_categoria  = sc.id_categoria
  WHERE m.id_tipo_movimiento = 1
    AND DATE(m.fecha_hora) BETWEEN '$dSQL' AND '$hSQL'
  GROUP BY c.id_categoria, c.nombre
  ORDER BY cant DESC
  LIMIT 8
",$notes,'ingresos_por_categoria');

// Alertas recientes
$alertas = q($conexion,"
  SELECT a.id_alerta, a.id_producto, a.atendida, a.fecha_creada, p.nombre AS producto
  FROM alerta a
  LEFT JOIN producto p ON p.id_producto = a.id_producto
  ORDER BY a.fecha_creada DESC
  LIMIT 8
",$notes,'alertas_recientes');

// Pedidos pendientes (id_estado_pedido = 1)
$pend = q($conexion,"
  SELECT p.id_pedido, pr.nombre AS proveedor, p.fecha_creado
  FROM pedido p
  LEFT JOIN proveedor pr ON pr.id_proveedor = p.id_proveedor
  WHERE p.id_estado_pedido = 1
  ORDER BY p.fecha_creado DESC
  LIMIT 8
",$notes,'pedidos_pendientes');

// ---- Header (Skeleton) ----
include(__DIR__ . '/../includes/header.php');
?>

<div class="row">
  <div class="three columns">
    <div class="card">
      <h6>Gestión</h6>
      <ul class="menu">
        <li><a href="/libreria_lapicito/admin/index.php">Dashboard</a></li>
        <li><a href="/libreria_lapicito/admin/usuarios/">Usuarios</a></li>
        <li><a href="/libreria_lapicito/admin/productos/">Productos</a></li>
        <li><a href="/libreria_lapicito/admin/inventario/">Inventario</a></li>
        <li><a href="/libreria_lapicito/admin/pedidos/">Pedidos</a></li>
        <li><a href="/libreria_lapicito/admin/alertas/">Alertas</a></li>
        <li><a href="/libreria_lapicito/admin/reportes/">Reportes</a></li>
        <li><a href="/libreria_lapicito/admin/ajustes/">Ajustes</a></li>
      </ul>
    </div>

    <?php if(!empty($notes)): ?>
      <div class="card">
        <h6>Notas</h6>
        <ul><?php foreach($notes as $n): ?><li><?= htmlspecialchars($n) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>
  </div>

  <div class="nine columns">
    <div class="card">
      <form class="row" method="get">
        <div class="four columns">
          <label>Desde</label>
          <input class="u-full-width" type="date" name="desde" value="<?= htmlspecialchars($desde) ?>">
        </div>
        <div class="four columns">
          <label>Hasta</label>
          <input class="u-full-width" type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>">
        </div>
        <div class="four columns" style="margin-top:25px">
          <button class="button-primary" type="submit">Aplicar</button>
          <a class="button button-outline" href="?">Limpiar</a>
        </div>
      </form>
    </div>

    <div class="row">
      <div class="four columns">
        <div class="card kpi">
          <div>
            <div class="muted">Productos</div>
            <div class="big"><?= number_format($totProductos) ?></div>
          </div>
        </div>
      </div>
      <div class="four columns">
        <div class="card kpi">
          <div>
            <div class="muted">Alertas stock</div>
            <div class="big"><?= number_format($totBajoStock) ?></div>
          </div>
        </div>
      </div>
      <div class="four columns">
        <div class="card kpi">
          <div>
            <div class="muted">Ventas hoy</div>
            <div class="big">$ <?= number_format($ventasHoy,2) ?></div>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <h6>Ventas (últimos 12 meses)</h6>
      <canvas id="chartMensual" height="120"></canvas>
      <?php if(empty($chartMensualLabels)): ?><p class="muted">Sin datos.</p><?php endif; ?>
    </div>

    <div class="row">
      <div class="six columns">
        <div class="card">
          <h6>Top productos (<?= htmlspecialchars($desde) ?> → <?= htmlspecialchars($hasta) ?>)</h6>
          <canvas id="chartTop" height="140"></canvas>
          <?php if(empty($chartTopLabels)): ?><p class="muted">Sin ventas en el rango.</p><?php endif; ?>
        </div>
      </div>
      <div class="six columns">
        <div class="card">
          <h6>Ingresos por categoría</h6>
          <canvas id="chartIng" height="140"></canvas>
          <?php if(empty($chartIngLabels)): ?><p class="muted">Sin movimientos de entrada.</p><?php endif; ?>
        </div>
      </div>
    </div>

    <div class="row">
      <div class="six columns">
        <div class="card table-wrap">
          <h6>Alertas recientes</h6>
          <table class="u-full-width">
            <thead><tr><th>ID</th><th>Producto</th><th>Fecha</th><th>Atendida</th></tr></thead>
            <tbody>
            <?php foreach($alertas as $a): ?>
              <tr>
                <td>#<?= (int)$a['id_alerta'] ?></td>
                <td><?= htmlspecialchars($a['producto'] ?? ('ID '.$a['id_producto'])) ?></td>
                <td><?= htmlspecialchars($a['fecha_creada'] ?? '') ?></td>
                <td><?= ((int)($a['atendida']??0)===1)?'<span class="badge ok">Sí</span>':'<span class="badge no">No</span>' ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if(empty($alertas)): ?><tr><td colspan="4" class="muted">Sin alertas.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="six columns">
        <div class="card table-wrap">
          <h6>Pedidos pendientes</h6>
          <table class="u-full-width">
            <thead><tr><th>#</th><th>Proveedor</th><th>Fecha</th></tr></thead>
            <tbody>
            <?php foreach($pend as $p): ?>
              <tr>
                <td>#<?= (int)$p['id_pedido'] ?></td>
                <td><?= htmlspecialchars($p['proveedor'] ?? '—') ?></td>
                <td><?= htmlspecialchars($p['fecha_creado'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if(empty($pend)): ?><tr><td colspan="3" class="muted">Sin pedidos pendientes.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div>
</div>

<?php
// JS para los gráficos (inyectado en footer)
$extra_js = '<script>
const ventasMensualesLabels = '.json_encode($chartMensualLabels).';
const ventasMensualesData   = '.json_encode($chartMensualData).';
const topLabels = '.json_encode($chartTopLabels).';
const topData   = '.json_encode($chartTopData).';
const ingLabels = '.json_encode($chartIngLabels).';
const ingData   = '.json_encode($chartIngData).';

if (ventasMensualesLabels.length) {
  new Chart(document.getElementById("chartMensual"), {
    type:"line",
    data:{ labels:ventasMensualesLabels, datasets:[{ label:"Ventas $", data:ventasMensualesData, tension:.25 }]},
    options:{ responsive:true, scales:{ y:{ beginAtZero:true } } }
  });
}
if (topLabels.length) {
  new Chart(document.getElementById("chartTop"), {
    type:"bar",
    data:{ labels:topLabels, datasets:[{ label:"Unidades", data:topData }]},
    options:{ indexAxis:"y", responsive:true, scales:{ x:{ beginAtZero:true } } }
  });
}
if (ingLabels.length) {
  new Chart(document.getElementById("chartIng"), {
    type:"bar",
    data:{ labels:ingLabels, datasets:[{ label:"Ingresos", data:ingData }]},
    options:{ responsive:true, scales:{ y:{ beginAtZero:true } } }
  });
}
</script>';
include(__DIR__ . '/../includes/footer.php');
