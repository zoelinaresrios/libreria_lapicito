<?php
// admin/reportes/index.php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';

$HAS_ACL = file_exists(__DIR__ . '/../includes/acl.php');
if ($HAS_ACL) { require_once __DIR__ . '/../includes/acl.php'; }
else {
  if (session_status()===PHP_SESSION_NONE) session_start();
  if (!function_exists('can')) { function can($k){ return true; } }
  if (!function_exists('require_perm')) { function require_perm($k){ return true; } }
}
if (function_exists('is_logged') && !is_logged()) { header('Location: /admin/login.php'); exit; }

$permSimple = can('reportes.simple') || can('reportes.detallados');
$permDet    = can('reportes.detallados');
require_perm($permSimple ? 'reportes.simple' : 'reportes.none');

if (session_status()===PHP_SESSION_NONE) session_start();
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ---------- Filtros ----------
$hoy     = date('Y-m-d');
$desde   = $_GET['desde'] ?? date('Y-m-d', strtotime('-14 days')); // 2 semanas por defecto
$hasta   = $_GET['hasta'] ?? $hoy;
$idSuc   = (int)($_GET['suc'] ?? 0);
$idCat   = (int)($_GET['cat'] ?? 0);
$idProv  = (int)($_GET['prov'] ?? 0);
$gran    = $_GET['gran'] ?? 'dia'; // dia|semana

// Catálogos para selects
$sucs=[]; $r=$conexion->query("SELECT id_sucursal, nombre FROM sucursal ORDER BY nombre");
while($row=$r->fetch_assoc()) $sucs[]=$row;

$cats=[]; $r=$conexion->query("SELECT id_categoria, nombre FROM categoria ORDER BY nombre");
while($row=$r->fetch_assoc()) $cats[]=$row;

$provs=[]; $r=$conexion->query("SELECT id_proveedor, nombre FROM proveedor ORDER BY nombre");
while($row=$r->fetch_assoc()) $provs[]=$row;

// WHERE básico por fecha y sucursal
$w = ["v.fecha_hora BETWEEN CONCAT(?, ' 00:00:00') AND CONCAT(?, ' 23:59:59')"];
$tp = "ss";
$pa = [$desde, $hasta];
if ($idSuc>0){ $w[]="v.id_sucursal=?"; $tp.="i"; $pa[]=$idSuc; }
$where = 'WHERE '.implode(' AND ',$w);

// WHERE extra (categoría/proveedor) se aplica a bloques que lo soportan
$wExtra = []; $tpExtra=''; $paExtra=[];
if ($idCat>0){ $wExtra[]="sc.id_categoria=?"; $tpExtra.="i"; $paExtra[]=$idCat; }
if ($idProv>0){ $wExtra[]="p.id_proveedor=?"; $tpExtra.="i"; $paExtra[]=$idProv; }
$whereExtra = $wExtra ? (' AND '.implode(' AND ',$wExtra)) : '';

// Exportador CSV 
function csvOut($filename, $headers, $rows){
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="'.$filename.'"');
  $out = fopen('php://output', 'w');
  fputcsv($out, $headers);
  foreach($rows as $rr) fputcsv($out, $rr);
  fclose($out);
  exit;
}
$export = $_GET['export'] ?? '';
$etype  = $_GET['type']   ?? '';

// ---------- KPIs ----------
$sqlKPIs = "
  SELECT
    COALESCE(SUM(v.total),0)                                   AS ventas,
    COUNT(DISTINCT v.id_venta)                                 AS tickets,
    COALESCE(SUM(vd.cantidad),0)                               AS unidades,
    COALESCE(SUM((vd.precio_unitario - p.precio_compra)*vd.cantidad),0) AS margen
  FROM venta v
  LEFT JOIN venta_detalle vd ON vd.id_venta=v.id_venta
  LEFT JOIN producto p ON p.id_producto=vd.id_producto
  $where
";
$st=$conexion->prepare($sqlKPIs);
$st->bind_param($tp, ...$pa);
$st->execute();
$kpi=$st->get_result()->fetch_assoc() ?: ['ventas'=>0,'tickets'=>0,'unidades'=>0,'margen'=>0];
$st->close();

// ---------- Serie temporal (día o semana) ----------
if ($gran==='semana'){
  $selFecha = "YEARWEEK(v.fecha_hora, 3) AS grp, DATE_FORMAT(MIN(v.fecha_hora),'%Y-%m-%d') AS etiqueta";
  $groupBy  = "YEARWEEK(v.fecha_hora, 3)";
}else{
  $selFecha = "DATE(v.fecha_hora) AS grp, DATE(v.fecha_hora) AS etiqueta";
  $groupBy  = "DATE(v.fecha_hora)";
}
$sqlSerie = "
  SELECT $selFecha,
         COALESCE(SUM(v.total),0) AS ventas,
         COUNT(DISTINCT v.id_venta) AS tickets,
         COALESCE(SUM(vd.cantidad),0) AS unidades
  FROM venta v
  LEFT JOIN venta_detalle vd ON vd.id_venta=v.id_venta
  $where
  GROUP BY $groupBy
  ORDER BY etiqueta ASC
";
$st=$conexion->prepare($sqlSerie);
$st->bind_param($tp, ...$pa);
$st->execute();
$serie=$st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// Export serie
if ($export==='csv' && $etype==='serie'){
  $rows=[]; foreach($serie as $s){ $rows[]=[ $s['etiqueta'], $s['ventas'], $s['tickets'], $s['unidades'] ]; }
  csvOut("serie_{$gran}_{$desde}_{$hasta}.csv", ['periodo','ventas','tickets','unidades'],$rows);
}

// ---------- Ventas por categoría ----------
$sqlCat = "
  SELECT c.nombre AS categoria,
         COALESCE(SUM(vd.cantidad),0) AS unidades,
         COALESCE(SUM(vd.precio_unitario*vd.cantidad),0) AS importe
  FROM venta v
  JOIN venta_detalle vd ON vd.id_venta=v.id_venta
  JOIN producto p ON p.id_producto=vd.id_producto
  LEFT JOIN subcategoria sc ON sc.id_subcategoria=p.id_subcategoria
  LEFT JOIN categoria c ON c.id_categoria=sc.id_categoria
  $where
  $whereExtra
  GROUP BY c.id_categoria, c.nombre
  ORDER BY importe DESC, categoria ASC
";
$st=$conexion->prepare($sqlCat);
$tpCat = $tp.$tpExtra; $paCat=array_merge($pa,$paExtra);
$st->bind_param($tpCat, ...$paCat);
$st->execute();
$porCat=$st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
if ($export==='csv' && $etype==='cat'){
  $rows=[]; foreach($porCat as $r){ $rows[]=[ $r['categoria'], $r['unidades'], $r['importe'] ]; }
  csvOut("ventas_categoria_{$desde}_{$hasta}.csv", ['categoria','unidades','importe'],$rows);
}

// ---------- Ventas por proveedor ----------
$sqlProv = "
  SELECT pr.nombre AS proveedor,
         COALESCE(SUM(vd.cantidad),0) AS unidades,
         COALESCE(SUM(vd.precio_unitario*vd.cantidad),0) AS importe,
         COALESCE(SUM((vd.precio_unitario - p.precio_compra)*vd.cantidad),0) AS margen
  FROM venta v
  JOIN venta_detalle vd ON vd.id_venta=v.id_venta
  JOIN producto p ON p.id_producto=vd.id_producto
  LEFT JOIN proveedor pr ON pr.id_proveedor=p.id_proveedor
  $where
  $whereExtra
  GROUP BY pr.id_proveedor, pr.nombre
  ORDER BY importe DESC, proveedor ASC
";
$st=$conexion->prepare($sqlProv);
$tpProv=$tp.$tpExtra; $paProv=array_merge($pa,$paExtra);
$st->bind_param($tpProv, ...$paProv);
$st->execute();
$porProv=$st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
if ($export==='csv' && $etype==='prov'){
  $rows=[]; foreach($porProv as $r){ $rows[]=[ $r['proveedor'], $r['unidades'], $r['importe'], $r['margen'] ]; }
  csvOut("ventas_proveedor_{$desde}_{$hasta}.csv", ['proveedor','unidades','importe','margen'],$rows);
}

// ---------- Ventas por sucursal ----------
$sqlSuc = "
  SELECT s.nombre AS sucursal,
         COALESCE(SUM(vd.cantidad),0) AS unidades,
         COALESCE(SUM(vd.precio_unitario*vd.cantidad),0) AS importe,
         COUNT(DISTINCT v.id_venta) AS tickets
  FROM venta v
  LEFT JOIN sucursal s ON s.id_sucursal=v.id_sucursal
  LEFT JOIN venta_detalle vd ON vd.id_venta=v.id_venta
  $where
  GROUP BY s.id_sucursal, s.nombre
  ORDER BY importe DESC, sucursal ASC
";
$st=$conexion->prepare($sqlSuc);
$st->bind_param($tp, ...$pa);
$st->execute();
$porSuc=$st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
if ($export==='csv' && $etype==='suc'){
  $rows=[]; foreach($porSuc as $r){ $rows[]=[ $r['sucursal'], $r['tickets'], $r['unidades'], $r['importe'] ]; }
  csvOut("ventas_sucursal_{$desde}_{$hasta}.csv", ['sucursal','tickets','unidades','importe'],$rows);
}

// ---------- Top productos ----------
$sqlTop = "
  SELECT p.id_producto, p.nombre,
         COALESCE(SUM(vd.cantidad),0) AS unidades,
         COALESCE(SUM(vd.cantidad*vd.precio_unitario),0) AS importe
  FROM venta v
  JOIN venta_detalle vd ON vd.id_venta=v.id_venta
  JOIN producto p ON p.id_producto=vd.id_producto
  LEFT JOIN subcategoria sc ON sc.id_subcategoria=p.id_subcategoria
  $where
  $whereExtra
  GROUP BY p.id_producto, p.nombre
  ORDER BY unidades DESC, importe DESC
  LIMIT 15
";
$st=$conexion->prepare($sqlTop);
$tpTop=$tp.$tpExtra; $paTop=array_merge($pa,$paExtra);
$st->bind_param($tpTop, ...$paTop);
$st->execute();
$topProd=$st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
if ($export==='csv' && $etype==='top'){
  $rows=[]; foreach($topProd as $r){ $rows[]=[ $r['id_producto'], $r['nombre'], $r['unidades'], $r['importe'] ]; }
  csvOut("top_productos_{$desde}_{$hasta}.csv", ['id','producto','unidades','importe'],$rows);
}

// ---------- Productos sin ventas en el período ----------
$sqlNoSale = "
  SELECT p.id_producto, p.nombre, COALESCE(i.stock_actual,0) AS stock_actual
  FROM producto p
  LEFT JOIN subcategoria sc ON sc.id_subcategoria=p.id_subcategoria
  LEFT JOIN inventario i ON i.id_producto=p.id_producto ".($idSuc>0?" AND i.id_sucursal=$idSuc ":"")."
  WHERE p.activo=1
    ".($idCat>0 ? " AND sc.id_categoria=".$idCat : "")."
    ".($idProv>0 ? " AND p.id_proveedor=".$idProv : "")."
    AND NOT EXISTS (
      SELECT 1 FROM venta v
      JOIN venta_detalle vd2 ON vd2.id_venta=v.id_venta
      WHERE vd2.id_producto=p.id_producto
        AND v.fecha_hora BETWEEN CONCAT(?, ' 00:00:00') AND CONCAT(?, ' 23:59:59')
        ".($idSuc>0 ? " AND v.id_sucursal=?" : "")."
    )
  ORDER BY p.nombre ASC
  LIMIT 50
";
$tpNS = "ss".($idSuc>0?"i":"");
$paNS = [$desde,$hasta]; if($idSuc>0) $paNS[]=$idSuc;
$st=$conexion->prepare($sqlNoSale);
$st->bind_param($tpNS, ...$paNS);
$st->execute();
$noVenden=$st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
if ($export==='csv' && $etype==='nosale'){
  $rows=[]; foreach($noVenden as $r){ $rows[]=[ $r['id_producto'], $r['nombre'], $r['stock_actual'] ]; }
  csvOut("productos_sin_ventas_{$desde}_{$hasta}.csv", ['id','producto','stock_actual'],$rows);
}

// ---------- Rotación (rápidos y lentos) ----------
$sqlRot = "
  SELECT p.id_producto, p.nombre,
         COALESCE(SUM(vd.cantidad),0) AS vendidas,
         COALESCE(SUM(vd.cantidad*vd.precio_unitario),0) AS importe,
         COALESCE(MAX(i.stock_actual),0) AS stock_actual
  FROM producto p
  LEFT JOIN venta_detalle vd ON vd.id_producto=p.id_producto
  LEFT JOIN venta v ON v.id_venta=vd.id_venta
  LEFT JOIN inventario i ON i.id_producto=p.id_producto ".($idSuc>0?" AND i.id_sucursal=$idSuc ":"")."
  LEFT JOIN subcategoria sc ON sc.id_subcategoria=p.id_subcategoria
  $where
  $whereExtra
  GROUP BY p.id_producto, p.nombre
  HAVING vendidas IS NOT NULL
  ORDER BY vendidas DESC
  LIMIT 50
";
$st=$conexion->prepare($sqlRot);
$tpRot=$tp.$tpExtra; $paRot=array_merge($pa,$paExtra);
$st->bind_param($tpRot, ...$paRot);
$st->execute();
$rotacion=$st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
if ($export==='csv' && $etype==='rot'){
  $rows=[]; foreach($rotacion as $r){ $rows[]=[ $r['id_producto'], $r['nombre'], $r['vendidas'], $r['stock_actual'], $r['importe'] ]; }
  csvOut("rotacion_{$desde}_{$hasta}.csv", ['id','producto','vendidas','stock_actual','importe'],$rows);
}

// ---------- HTML ----------
?>
<!doctype html><html lang="es"><head>
<meta charset="utf-8">
<title>Reportes — Los Lapicitos</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/vendor/normalize.css?v=2">
<link rel="stylesheet" href="/vendor/skeleton.css?v=3">
<link rel="stylesheet" href="/css/style.css?v=13">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
  .kpis{display:grid;grid-template-columns:repeat(4,minmax(160px,1fr));gap:12px}
  .kpi{background:#fff;border:1px solid var(--borde);border-radius:var(--radio);padding:14px;box-shadow:var(--sombra)}
  .kpi .val{font-size:26px;font-weight:700}
  .tabs{display:flex;gap:8px;flex-wrap:wrap;margin:.5rem 0}
  .tabs a{padding:8px 10px;border:1px solid var(--borde);border-radius:10px;text-decoration:none}
  .tabs a.active{background:var(--btn);color:#fff}
  .table-wrap{overflow:auto}
  .muted{color:#777}
  .export{font-size:12px;margin-left:8px}
  .filters select,.filters input{margin-right:6px}
  .tag{font-size:12px;padding:2px 8px;border:1px solid var(--borde);border-radius:999px}
</style>
</head>
<body>
 <div class="barra"></div>

  <div class="prod-shell">
    <aside class="prod-side">
      <ul class="prod-nav">
        <li><a  href="/admin/index.php">inicio</a></li>
       
        <li><a href="/admin/productos/">Productos</a></li>
        <li><a href="/admin/categorias/">categorias</a></li>
       <li><a  href="/admin/subcategorias/">subcategorias</a></li>
        <li><a href="/admin/inventario/">Inventario</a></li>
        <li><a href="/admin/pedidos/">Pedidos</a></li>
        <li><a href="/admin/proveedores/">Proveedores</a></li>
          <li><a href="/admin/sucursales/">sucursales</a></li>
        <li><a href="/admin/alertas/">Alertas</a></li>
        <li><a class="active" href="/admin/reportes/">Reportes y estadisticas</a></li>
        <li><a href="/admin/ventas/">Ventas</a></li>
        <li><a href="/admin/usuarios/">Usuarios</a></li>
        <li><a href="/admin/roles/">Roles y permisos</a></li>
        <li><a href="/admin/ajustes/">Ajustes</a></li>
         <li><a href="/admin/ajustes/">Audutorias</a></li>
        <li><a href="/admin/logout.php">Salir</a></li>
      </ul>
    </aside>

  <main class="prod-main">
    <div class="inv-title">Reportes y estadísticas</div>

    <form class="filters" method="get" style="margin-bottom:12px">
      <label>Desde <input type="date" name="desde" value="<?= h($desde) ?>"></label>
      <label>Hasta <input type="date" name="hasta" value="<?= h($hasta) ?>"></label>
      <select name="suc">
        <option value="0">Todas las sucursales</option>
        <?php foreach($sucs as $s): ?>
          <option value="<?= (int)$s['id_sucursal'] ?>" <?= $idSuc===(int)$s['id_sucursal']?'selected':'' ?>><?= h($s['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="cat">
        <option value="0">Todas las categorías</option>
        <?php foreach($cats as $c): ?>
          <option value="<?= (int)$c['id_categoria'] ?>" <?= $idCat===(int)$c['id_categoria']?'selected':'' ?>><?= h($c['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="prov">
        <option value="0">Todos los proveedores</option>
        <?php foreach($provs as $p): ?>
          <option value="<?= (int)$p['id_proveedor'] ?>" <?= $idProv===(int)$p['id_proveedor']?'selected':'' ?>><?= h($p['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="gran">
        <option value="dia" <?= $gran==='dia'?'selected':'' ?>>Por día</option>
        <option value="semana" <?= $gran==='semana'?'selected':'' ?>>Por semana</option>
      </select>
      <button class="btn-filter" type="submit">Aplicar</button>
      <span class="tag"><?= h($desde) ?> → <?= h($hasta) ?></span>
    </form>

    <div class="kpis">
      <div class="kpi"><div class="muted">Ventas</div><div class="val">$<?= number_format($kpi['ventas'],2,',','.') ?></div></div>
      <div class="kpi"><div class="muted">Tickets</div><div class="val"><?= (int)$kpi['tickets'] ?></div></div>
      <div class="kpi"><div class="muted">Unidades</div><div class="val"><?= (int)$kpi['unidades'] ?></div></div>
      <div class="kpi"><div class="muted">Margen (aprox.)</div><div class="val">$<?= number_format($kpi['margen'],2,',','.') ?></div></div>
    </div>

    <div class="prod-card" style="margin-top:14px">
      <div class="prod-head">
        <h5>Evolución <?= $gran==='semana'?'semanal':'diaria' ?></h5>
        <div>
          <a class="export" href="?<?= h(http_build_query(array_merge($_GET,['export'=>'csv','type'=>'serie']))) ?>">⬇ CSV</a>
        </div>
      </div>
      <canvas id="chartSerie" height="120"></canvas>
      <div class="table-wrap">
        <table class="u-full-width">
          <thead><tr><th>Período</th><th>Ventas</th><th>Tickets</th><th>Unidades</th></tr></thead>
          <tbody>
            <?php foreach($serie as $s): ?>
              <tr>
                <td><?= h($s['etiqueta']) ?></td>
                <td>$<?= number_format($s['ventas'],2,',','.') ?></td>
                <td><?= (int)$s['tickets'] ?></td>
                <td><?= (int)$s['unidades'] ?></td>
              </tr>
            <?php endforeach; if(empty($serie)): ?>
              <tr><td colspan="4" class="muted">Sin datos en el período.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="prod-card">
      <div class="prod-head">
        <h5>Ventas por categoría</h5>
        <a class="export" href="?<?= h(http_build_query(array_merge($_GET,['export'=>'csv','type'=>'cat']))) ?>">⬇ CSV</a>
      </div>
      <div class="table-wrap">
        <table class="u-full-width">
          <thead><tr><th>Categoría</th><th>Unidades</th><th>Importe</th></tr></thead>
          <tbody>
          <?php foreach($porCat as $r): ?>
            <tr>
              <td><?= h($r['categoria'] ?? '—') ?></td>
              <td><?= (int)$r['unidades'] ?></td>
              <td>$<?= number_format($r['importe'],2,',','.') ?></td>
            </tr>
          <?php endforeach; if(empty($porCat)): ?>
            <tr><td colspan="3" class="muted">Sin datos.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="prod-card">
      <div class="prod-head">
        <h5>Ventas por proveedor</h5>
        <a class="export" href="?<?= h(http_build_query(array_merge($_GET,['export'=>'csv','type'=>'prov']))) ?>">⬇ CSV</a>
      </div>
      <div class="table-wrap">
        <table class="u-full-width">
          <thead><tr><th>Proveedor</th><th>Unidades</th><th>Importe</th><th>Margen</th></tr></thead>
          <tbody>
          <?php foreach($porProv as $r): ?>
            <tr>
              <td><?= h($r['proveedor'] ?? '—') ?></td>
              <td><?= (int)$r['unidades'] ?></td>
              <td>$<?= number_format($r['importe'],2,',','.') ?></td>
              <td>$<?= number_format($r['margen'],2,',','.') ?></td>
            </tr>
          <?php endforeach; if(empty($porProv)): ?>
            <tr><td colspan="4" class="muted">Sin datos.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="prod-card">
      <div class="prod-head">
        <h5>Ventas por sucursal</h5>
        <a class="export" href="?<?= h(http_build_query(array_merge($_GET,['export'=>'csv','type'=>'suc']))) ?>">⬇ CSV</a>
      </div>
      <div class="table-wrap">
        <table class="u-full-width">
          <thead><tr><th>Sucursal</th><th>Tickets</th><th>Unidades</th><th>Importe</th></tr></thead>
          <tbody>
          <?php foreach($porSuc as $r): ?>
            <tr>
              <td><?= h($r['sucursal'] ?? '—') ?></td>
              <td><?= (int)$r['tickets'] ?></td>
              <td><?= (int)$r['unidades'] ?></td>
              <td>$<?= number_format($r['importe'],2,',','.') ?></td>
            </tr>
          <?php endforeach; if(empty($porSuc)): ?>
            <tr><td colspan="4" class="muted">Sin datos.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="prod-card">
      <div class="prod-head">
        <h5>Top productos vendidos</h5>
        <a class="export" href="?<?= h(http_build_query(array_merge($_GET,['export'=>'csv','type'=>'top']))) ?>">⬇ CSV</a>
      </div>
      <div class="table-wrap">
        <table class="u-full-width">
          <thead><tr><th>ID</th><th>Producto</th><th>Unidades</th><th>Importe</th></tr></thead>
          <tbody>
          <?php foreach($topProd as $r): ?>
            <tr>
              <td>#<?= (int)$r['id_producto'] ?></td>
              <td><?= h($r['nombre']) ?></td>
              <td><?= (int)$r['unidades'] ?></td>
              <td>$<?= number_format($r['importe'],2,',','.') ?></td>
            </tr>
          <?php endforeach; if(empty($topProd)): ?>
            <tr><td colspan="4" class="muted">Sin datos.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="prod-card">
      <div class="prod-head">
        <h5>Productos sin ventas (período)</h5>
        <a class="export" href="?<?= h(http_build_query(array_merge($_GET,['export'=>'csv','type'=>'nosale']))) ?>">⬇ CSV</a>
      </div>
      <div class="table-wrap">
        <table class="u-full-width">
          <thead><tr><th>ID</th><th>Producto</th><th>Stock actual</th></tr></thead>
          <tbody>
          <?php foreach($noVenden as $r): ?>
            <tr>
              <td>#<?= (int)$r['id_producto'] ?></td>
              <td><?= h($r['nombre']) ?></td>
              <td><?= (int)$r['stock_actual'] ?></td>
            </tr>
          <?php endforeach; if(empty($noVenden)): ?>
            <tr><td colspan="3" class="muted">Todos tuvieron ventas o no hay datos.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="prod-card">
      <div class="prod-head">
        <h5>Rotación de productos (rápidos ↔ lentos)</h5>
        <a class="export" href="?<?= h(http_build_query(array_merge($_GET,['export'=>'csv','type'=>'rot']))) ?>">⬇ CSV</a>
      </div>
      <div class="table-wrap">
        <table class="u-full-width">
          <thead><tr><th>ID</th><th>Producto</th><th>Vendidas</th><th>Stock actual</th><th>Importe</th></tr></thead>
          <tbody>
          <?php foreach($rotacion as $r): ?>
            <tr>
              <td>#<?= (int)$r['id_producto'] ?></td>
              <td><?= h($r['nombre']) ?></td>
              <td><?= (int)$r['vendidas'] ?></td>
              <td><?= (int)$r['stock_actual'] ?></td>
              <td>$<?= number_format($r['importe'],2,',','.') ?></td>
            </tr>
          <?php endforeach; if(empty($rotacion)): ?>
            <tr><td colspan="5" class="muted">Sin datos.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </main>
</div>

<script>
const serie = <?= json_encode($serie, JSON_UNESCAPED_UNICODE) ?>;
const labels  = serie.map(s => s.etiqueta);
const ventas  = serie.map(s => parseFloat(s.ventas||0));
const tickets = serie.map(s => parseInt(s.tickets||0));
const unidades= serie.map(s => parseInt(s.unidades||0));
const ctx = document.getElementById('chartSerie').getContext('2d');
new Chart(ctx,{
  type:'line',
  data:{
    labels:labels,
    datasets:[
      {label:'Ventas', data:ventas, yAxisID:'y1'},
      {label:'Tickets', data:tickets, yAxisID:'y2'},
      {label:'Unidades', data:unidades, yAxisID:'y2'}
    ]
  },
  options:{
    responsive:true,
    interaction:{mode:'index', intersect:false},
    scales:{
      y1:{type:'linear', position:'left'},
      y2:{type:'linear', position:'right', grid:{drawOnChartArea:false}}
    }
  }
});
</script>
</body></html>
