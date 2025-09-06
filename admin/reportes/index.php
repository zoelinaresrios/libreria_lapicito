<?php
// /libreria_lapicito/admin/reportes/index.php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';

$HAS_ACL = file_exists(__DIR__ . '/../includes/acl.php');
if ($HAS_ACL) { require_once __DIR__ . '/../includes/acl.php'; }
else { if (session_status()===PHP_SESSION_NONE) session_start();
  if (!function_exists('can')) { function can($k){ return true; } }
  if (!function_exists('require_perm')) { function require_perm($k){ return true; } }
}
require_perm('reportes.simple'); // o reportes.detallados según tu ACL

if (session_status()===PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ---------- Filtros ---------- */
$desde = trim($_GET['desde'] ?? date('Y-m-01'));
$hasta = trim($_GET['hasta'] ?? date('Y-m-d'));
$id_cat = (int)($_GET['cat'] ?? 0);

/* Catálogo categorías */
$cats=[]; $rc=$conexion->query("SELECT id_categoria, nombre FROM categoria ORDER BY nombre");
while($r=$rc->fetch_assoc()) $cats[]=$r;

/* WHERE común para ventas */
$where=[]; $params=[]; $types='';
if ($desde!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$desde)) { $where[]="v.fecha_hora >= CONCAT(?, ' 00:00:00')"; $params[]=$desde; $types.='s'; }
if ($hasta!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$hasta)) { $where[]="v.fecha_hora <= CONCAT(?, ' 23:59:59')"; $params[]=$hasta; $types.='s'; }
if ($id_cat>0) { $where[]="c.id_categoria=?"; $params[]=$id_cat; $types.='i'; }
$whereSql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

/* ---------- KPIs ---------- */
/* Cambiar vd.cantidad / vd.precio_unitario si tus nombres difieren */
$sqlKPIs = "
  SELECT
    COUNT(DISTINCT v.id_venta) AS tickets,
    COALESCE(SUM(vd.cantidad * vd.precio_unitario),0) AS total
  FROM venta v
  JOIN venta_detalle vd ON vd.id_venta = v.id_venta
  JOIN producto p ON p.id_producto = vd.id_producto
  LEFT JOIN subcategoria sc ON sc.id_subcategoria=p.id_subcategoria
  LEFT JOIN categoria c ON c.id_categoria=sc.id_categoria
  $whereSql
";
$st=$conexion->prepare($sqlKPIs);
if ($types) $st->bind_param($types, ...$params);
$st->execute(); $kpi=$st->get_result()->fetch_assoc() ?: ['tickets'=>0,'total'=>0]; $st->close();
$tickets=(int)$kpi['tickets']; $total=(float)$kpi['total'];
$prom = $tickets>0 ? ($total/$tickets) : 0.0;

/* ---------- Top productos ---------- */
$sqlTop = "
  SELECT
    p.id_producto, p.nombre,
    COALESCE(SUM(vd.cantidad),0) AS cant,
    COALESCE(SUM(vd.cantidad * vd.precio_unitario),0) AS importe
  FROM venta v
  JOIN venta_detalle vd ON vd.id_venta=v.id_venta
  JOIN producto p ON p.id_producto=vd.id_producto
  LEFT JOIN subcategoria sc ON sc.id_subcategoria=p.id_subcategoria
  LEFT JOIN categoria c ON c.id_categoria=sc.id_categoria
  $whereSql
  GROUP BY p.id_producto, p.nombre
  ORDER BY cant DESC, importe DESC
  LIMIT 10
";
$st=$conexion->prepare($sqlTop);
if ($types) $st->bind_param($types, ...$params);
$st->execute(); $top=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

/* ---------- Ventas por categoría ---------- */
$sqlCat = "
  SELECT
    c.id_categoria, c.nombre AS categoria,
    COALESCE(SUM(vd.cantidad),0) AS cant,
    COALESCE(SUM(vd.cantidad * vd.precio_unitario),0) AS importe
  FROM venta v
  JOIN venta_detalle vd ON vd.id_venta=v.id_venta
  JOIN producto p ON p.id_producto=vd.id_producto
  LEFT JOIN subcategoria sc ON sc.id_subcategoria=p.id_subcategoria
  LEFT JOIN categoria c ON c.id_categoria=sc.id_categoria
  $whereSql
  GROUP BY c.id_categoria, categoria
  ORDER BY importe DESC
";
$st=$conexion->prepare($sqlCat);
if ($types) $st->bind_param($types, ...$params);
$st->execute(); $porCat=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

/* ---------- Resumen stock ---------- */
$sqlStock = "
  SELECT
    SUM(CASE WHEN COALESCE(i.stock_actual,0)=0 THEN 1 ELSE 0 END) AS sin_stock,
    SUM(CASE WHEN COALESCE(i.stock_actual,0)<=COALESCE(i.stock_minimo,0) THEN 1 ELSE 0 END) AS bajo_min
  FROM producto p
  LEFT JOIN inventario i ON i.id_producto=p.id_producto
";
$st=$conexion->prepare($sqlStock);
$st->execute(); $stk=$st->get_result()->fetch_assoc() ?: ['sin_stock'=>0,'bajo_min'=>0]; $st->close();

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Reportes — Los Lapicitos</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/8.0.1/normalize.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/skeleton/2.0.4/skeleton.min.css">
  <link rel="stylesheet" href="/libreria_lapicito/css/style.css">
  <style>
    .kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin:12px 0}
    .kpi{background:#fff;border:1px solid #eee;border-radius:12px;padding:14px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
    .kpi h6{margin:0 0 6px 0;font-weight:600;color:#666}
    .kpi .v{font-size:20px;font-weight:700}
  </style>
</head>
<body>
  <div class="barra"></div>
 <div class="prod-shell">
    <aside class="prod-side">
      <ul class="prod-nav">
        <li><a href="/libreria_lapicito/admin/index.php">inicio</a></li>
       
        <?php if (can('productos.ver')): ?>
        <li><a href="/libreria_lapicito/admin/productos/">Productos</a></li>
        <?php endif; ?>
        <li><a href="/libreria_lapicito/admin/categorias/">categorias</a></li>
        <?php if (can('inventario.ver')): ?>
           <li><a href="/libreria_lapicito/admin/subcategorias/">subcategorias</a></li>
        <li><a href="/libreria_lapicito/admin/inventario/">Inventario</a></li>
        <?php endif; ?>
        <?php if (can('pedidos.aprobar')): ?>
        <li><a href="/libreria_lapicito/admin/pedidos/">Pedidos</a></li>
        <?php endif; ?>
        <?php if (can('alertas.ver')): ?>
        <li><a class="active" href="/libreria_lapicito/admin/alertas/">Alertas</a></li>
        <?php endif; ?>
        <?php if (can('reportes.detallados') || can('reportes.simple')): ?>
        <li><a href="/libreria_lapicito/admin/reportes/">Reportes</a></li>
        <?php endif; ?>
         <?php if (can('ventas.rapidas')): ?>
        <li><a href="/libreria_lapicito/admin/ventas/">Ventas</a></li>
        <?php endif; ?>
        <?php if (can('usuarios.gestionar') || can('usuarios.crear_empleado')): ?>
        <li><a href="/libreria_lapicito/admin/usuarios/">Usuarios</a></li>
        <?php endif; ?>
        <?php if (can('usuarios.gestionar')): ?>
        <li><a href="/libreria_lapicito/admin/roles/">Roles y permisos</a></li>
        <?php endif; ?>
        <li><a href="/libreria_lapicito/admin/ajustes/">Ajustes</a></li>
        <li><a href="/libreria_lapicito/admin/logout.php">Salir</a></li>
      </ul>
    </aside>
    <main class="prod-main">
      <div class="inv-title">Panel administrativo — Reportes</div>

      <div class="prod-card">
        <!-- Filtros -->
        <form class="prod-filters" method="get">
          <input type="date" name="desde" value="<?= h($desde) ?>">
          <input type="date" name="hasta" value="<?= h($hasta) ?>">
          <select name="cat">
            <option value="0">Todas las categorías</option>
            <?php foreach($cats as $c): ?>
              <option value="<?= (int)$c['id_categoria'] ?>" <?= $id_cat===(int)$c['id_categoria']?'selected':'' ?>>
                <?= h($c['nombre']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button class="btn-filter" type="submit">Aplicar</button>
        </form>

        <!-- KPIs -->
        <div class="kpi-grid">
          <div class="kpi"><h6>Total vendido</h6><div class="v">$ <?= number_format($total,2,',','.') ?></div></div>
          <div class="kpi"><h6>Tickets</h6><div class="v"><?= number_format($tickets,0,',','.') ?></div></div>
          <div class="kpi"><h6>Ticket promedio</h6><div class="v">$ <?= number_format($prom,2,',','.') ?></div></div>
          <div class="kpi"><h6>Sin stock</h6><div class="v"><span class="badge no"><?= (int)$stk['sin_stock'] ?></span></div></div>
          <div class="kpi"><h6>Bajo mínimo</h6><div class="v"><span class="badge warn"><?= (int)$stk['bajo_min'] ?></span></div></div>
        </div>

        <!-- Top productos -->
        <h5 style="margin-top:20px">Top productos (por cantidad)</h5>
        <div class="table-wrap">
          <table class="u-full-width">
            <thead>
              <tr>
                <th style="width:80px">ID</th>
                <th>Producto</th>
                <th style="width:140px">Cantidad</th>
                <th style="width:160px">Importe</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($top as $r): ?>
                <tr>
                  <td>#<?= (int)$r['id_producto'] ?></td>
                  <td><?= h($r['nombre']) ?></td>
                  <td><?= (int)$r['cant'] ?></td>
                  <td>$ <?= number_format((float)$r['importe'],2,',','.') ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if(empty($top)): ?>
                <tr><td colspan="4" class="muted">Sin ventas en el período.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Ventas  -->
        <h5 style="margin-top:20px">Ventas por categoría</h5>
        <div class="table-wrap">
          <table class="u-full-width">
            <thead>
              <tr>
                <th>Categoría</th>
                <th style="width:140px">Cantidad</th>
                <th style="width:160px">Importe</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($porCat as $r): ?>
                <tr>
                  <td><?= h($r['categoria'] ?? '—') ?></td>
                  <td><?= (int)$r['cant'] ?></td>
                  <td>$ <?= number_format((float)$r['importe'],2,',','.') ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if(empty($porCat)): ?>
                <tr><td colspan="3" class="muted">Sin datos para el período.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

      </div>
    </main>
  </div>
</body>
</html>
