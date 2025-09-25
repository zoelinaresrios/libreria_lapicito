<?php
// /admin/proveedores/reportes.php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';

$HAS_ACL = file_exists(__DIR__ . '/../includes/acl.php');
if ($HAS_ACL) { require_once __DIR__ . '/../includes/acl.php'; }
else {
  if (session_status()===PHP_SESSION_NONE) session_start();
  if (!function_exists('can')) { function can($k){ return true; } }
  if (!function_exists('require_perm')) { function require_perm($k){ return true; } }
}
require_perm('reportes.ver');

if (session_status()===PHP_SESSION_NONE) session_start();
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// filtros
$prov =(int)($_GET['prov']??0);
$desde=$_GET['desde']??date('Y-m-01');
$hasta=$_GET['hasta']??date('Y-m-d');
$export=($_GET['export']??'')==='csv';

$proveedores=$conexion->query("SELECT id_proveedor,nombre FROM proveedor ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);

$w  = ["DATE(p.fecha_creado) BETWEEN ? AND ?"];
$par= [$desde,$hasta]; $typ='ss';
if($prov>0){ $w[]="p.id_proveedor=?"; $par[]=$prov; $typ.='i'; }
$ws='WHERE '.implode(' AND ',$w);

// agregado
$sql="SELECT pr.id_proveedor, pr.nombre,
             COALESCE(SUM(pd.cantidad_solicitada*pd.precio_unitario),0) AS total,
             COUNT(DISTINCT p.id_pedido) AS pedidos
      FROM pedido p
      JOIN proveedor pr ON pr.id_proveedor=p.id_proveedor
      LEFT JOIN pedido_detalle pd ON pd.id_pedido=p.id_pedido
      $ws
      GROUP BY pr.id_proveedor, pr.nombre
      ORDER BY total DESC";
$st=$conexion->prepare($sql); $st->bind_param($typ,...$par); $st->execute();
$data=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

if($export){
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=reporte_compras_por_proveedor.csv');
  $out=fopen('php://output','w');
  fputcsv($out,['Proveedor','Pedidos','Total']);
  foreach($data as $r){ fputcsv($out,[$r['nombre'],$r['pedidos'],number_format((float)$r['total'],2,'.','')]); }
  fclose($out); exit;
}
?>
<!doctype html><html lang="es"><head>
<meta charset="utf-8"><title>Reporte — Compras por proveedor</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/vendor/normalize.css">
<link rel="stylesheet" href="/vendor/skeleton.css">
<link rel="stylesheet" href="/css/style.css?v=13">
</head>
<body>
<div class="barra"></div>
<div class="prod-shell">
  <aside class="prod-side">
    <ul class="prod-nav">
      <li><a href="/admin/proveedores/">← Proveedores</a></li>
    </ul>
  </aside>

  <main class="prod-main">
    <div class="inv-title">Reporte — Compras por proveedor</div>

    <div class="prod-card">
      <form class="prod-filters" method="get">
        <select name="prov">
          <option value="0">Todos los proveedores</option>
          <?php foreach($proveedores as $p): ?>
            <option value="<?=$p['id_proveedor']?>" <?=$prov===$p['id_proveedor']?'selected':''?>><?=h($p['nombre'])?></option>
          <?php endforeach; ?>
        </select>
        <input type="date" name="desde" value="<?=h($desde)?>">
        <input type="date" name="hasta" value="<?=h($hasta)?>">
        <button class="btn-filter" type="submit">Filtrar</button>
        <a class="btn-sm" href="?<?= http_build_query($_GET + ['export'=>'csv']) ?>">Exportar CSV</a>
      </form>

      <div class="table-wrap">
        <table class="u-full-width">
          <thead><tr><th>Proveedor</th><th>Pedidos</th><th>Total comprado</th></tr></thead>
          <tbody>
            <?php foreach($data as $r): ?>
              <tr>
                <td><?=h($r['nombre'])?></td>
                <td><?= (int)$r['pedidos'] ?></td>
                <td>$<?= number_format((float)$r['total'],2,',','.') ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if(empty($data)): ?><tr><td colspan="3" class="muted">Sin datos para el rango.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</div>
</body></html>
