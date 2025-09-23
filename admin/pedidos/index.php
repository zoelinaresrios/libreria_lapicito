<?php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';

$HAS_ACL = file_exists(__DIR__ . '/../includes/acl.php');
if ($HAS_ACL) { require_once __DIR__ . '/../includes/acl.php'; }
else {
  if (session_status()===PHP_SESSION_NONE) session_start();
  if (!function_exists('can')) { function can($k){ return true; } }
  if (!function_exists('require_perm')) { function require_perm($k){ return true; } }
}
require_perm('pedidos.ver');

if (session_status()===PHP_SESSION_NONE) session_start();
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// KPIs
$kpi=['borrador'=>0,'pendientes'=>0,'recibidos_mes'=>0,'monto_mes'=>0.0];
$kpi['borrador']   =(int)$conexion->query("SELECT COUNT(*) c FROM pedido WHERE id_estado_pedido=1")->fetch_assoc()['c'];
$kpi['pendientes'] =(int)$conexion->query("SELECT COUNT(*) c FROM pedido WHERE id_estado_pedido IN (2,3)")->fetch_assoc()['c'];
$kpi['recibidos_mes']=(int)$conexion->query("SELECT COUNT(*) c FROM pedido WHERE id_estado_pedido=4 AND DATE_FORMAT(fecha_estado,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')")->fetch_assoc()['c'];
$r=$conexion->query("SELECT SUM(pd.cantidad_solicitada*pd.precio_unitario) total
                     FROM pedido p JOIN pedido_detalle pd ON pd.id_pedido=p.id_pedido
                     WHERE DATE_FORMAT(p.fecha_creado,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')")->fetch_assoc();
$kpi['monto_mes']=(float)($r['total']??0);

// helper mes español
function mesY($ts=null){ $ts=$ts??time(); $m=['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre']; return ucfirst($m[(int)date('n',$ts)-1]).' '.date('Y',$ts); }

// Filtros
$q=trim($_GET['q']??'');
$idProv=(int)($_GET['prov']??0);
$idSuc =(int)($_GET['suc']??0);
$estado=(int)($_GET['estado']??0);
$desde =$_GET['desde']??'';
$hasta =$_GET['hasta']??'';
$page=max(1,(int)($_GET['page']??1));
$perPage=15; $offset=($page-1)*$perPage;

$proveedores=$conexion->query("SELECT id_proveedor,nombre FROM proveedor ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);
$sucursales =$conexion->query("SELECT id_sucursal,nombre FROM sucursal ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);

$w=[]; $params=[]; $types='';
if($q!==''){ $w[]="(pr.nombre LIKE ? OR p.id_pedido LIKE ?)"; $params[]="%$q%"; $params[]="%$q%"; $types.='ss'; }
if($idProv>0){ $w[]="p.id_proveedor=?"; $params[]=$idProv; $types.='i'; }
if($idSuc>0){  $w[]="p.id_sucursal=?";  $params[]=$idSuc;  $types.='i'; }
if($estado>0){ $w[]="p.id_estado_pedido=?"; $params[]=$estado; $types.='i'; }
if($desde!==''){ $w[]="DATE(p.fecha_creado)>=?"; $params[]=$desde; $types.='s'; }
if($hasta!==''){ $w[]="DATE(p.fecha_creado)<=?"; $params[]=$hasta; $types.='s'; }
$whereSql=$w?('WHERE '.implode(' AND ',$w)):'';

// Conteo
$sqlCount="SELECT COUNT(*) total FROM pedido p JOIN proveedor pr ON pr.id_proveedor=p.id_proveedor $whereSql";
$st=$conexion->prepare($sqlCount); if($types) $st->bind_param($types, ...$params); $st->execute();
$total=(int)($st->get_result()->fetch_assoc()['total']??0); $st->close();
$pages=max(1,(int)ceil($total/$perPage));

// Listado
$sqlList="SELECT p.id_pedido, p.fecha_creado, p.id_estado_pedido, pr.nombre AS proveedor, s.nombre AS sucursal,
                 COUNT(DISTINCT pd.id_pedido_detalle) AS renglones,
                 COALESCE(SUM(pd.cantidad_solicitada*pd.precio_unitario),0) AS monto_total
          FROM pedido p
          JOIN proveedor pr ON pr.id_proveedor=p.id_proveedor
          JOIN sucursal s ON s.id_sucursal=p.id_sucursal
          LEFT JOIN pedido_detalle pd ON pd.id_pedido=p.id_pedido
          $whereSql
          GROUP BY p.id_pedido, p.fecha_creado, p.id_estado_pedido, proveedor, sucursal
          ORDER BY p.fecha_creado DESC
          LIMIT ? OFFSET ?";
$typesList=$types.'ii'; $paramsList=$params; $paramsList[]=$perPage; $paramsList[]=$offset;
$st=$conexion->prepare($sqlList); $st->bind_param($typesList, ...$paramsList); $st->execute();
$rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

$estados=[1=>'Borrador',2=>'Aprobado',3=>'Enviado',4=>'Recibido',5=>'Cancelado'];
?>
<!doctype html><html lang="es"><head>
<meta charset="utf-8"><title>Pedidos — Los Lapicitos</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/vendor/normalize.css">
<link rel="stylesheet" href="/vendor/skeleton.css">
<link rel="stylesheet" href="/css/style.css?v=13">
<link rel="stylesheet" href="/css/pedidos.css?v=2">
<link rel="stylesheet" href="/css/toast.css?v=1">
</head>
<body>
<div class="barra"></div>
<div class="prod-shell">
  <aside class="prod-side">
    <ul class="prod-nav">
      <li><a href="/admin/index.php">Inicio</a></li>
      <li><a href="/admin/productos/">Productos</a></li>
      <li><a href="/admin/categorias/">Categorías</a></li>
      <li><a href="/admin/subcategorias/">Subcategorías</a></li>
      <li><a href="/admin/inventario/">Inventario</a></li>
      <li><a class="active" href="/admin/pedidos/">Pedidos</a></li>
      <li><a href="/admin/alertas/">Alertas</a></li>
      <li><a href="/admin/reportes/">Reportes</a></li>
      <li><a href="/admin/ventas/">Ventas</a></li>
      <li><a href="/admin/usuarios/">Usuarios</a></li>
    </ul>
  </aside>

  <main class="prod-main">
    <div class="inv-title">Panel administrativo — Gestión de Pedidos</div>

   <?php
// Helper para mostrar "Septiembre 2025" en español.
if (!function_exists('mesY')) {
  function mesY($ts=null){
    $ts = $ts ?? time();
    $meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    return ucfirst($meses[(int)date('n',$ts)-1]).' '.date('Y',$ts);
  }
}

?>
<div class="row">
  <div class="three columns">
    <div class="prod-card">
      <div class="prod-head"><h5>Borradores</h5></div>
      <div style="font-size:26px;font-weight:700"><?= (int)$kpi['borrador'] ?></div>
      <div class="muted">Pedidos en estado borrador</div>
    </div>
  </div>

  <div class="three columns">
    <div class="prod-card">
      <div class="prod-head"><h5>Pendientes de recibir</h5></div>
      <div style="font-size:26px;font-weight:700"><?= (int)$kpi['pendientes'] ?></div>
      <div class="muted">Aprobado o Enviado</div>
    </div>
  </div>

  <div class="three columns">
    <div class="prod-card">
      <div class="prod-head"><h5>Recibidos este mes</h5></div>
      <div style="font-size:26px;font-weight:700"><?= (int)$kpi['recibidos_mes'] ?></div>
      <div class="muted"><?= mesY() ?></div>
    </div>
  </div>

  <div class="three columns">
    <div class="prod-card">
      <div class="prod-head"><h5>Monto del mes</h5></div>
      <div style="font-size:26px;font-weight:700">$ <?= number_format($kpi['monto_mes'],2,',','.') ?></div>
      <div class="muted">Total de pedidos creados</div>
    </div>
  </div>
</div>



    <!-- Tabla -->
    <div class="prod-card">
      <div class="prod-head">
        <h5>Pedidos</h5>
        <div>
          <?php if (can('pedidos.crear')): ?>
            <a class="btn-add" href="/admin/pedidos/crear.php">+ Nuevo Pedido</a>
          <?php endif; ?>
          <a class="btn-sm" href="?<?= http_build_query($_GET + ['export'=>'csv']) ?>">Exportar</a>
        </div>
      </div>

      <div class="prod-toolbar">
        <?php $chips=[0=>'Todos',1=>'Borrador',2=>'Aprobado',3=>'Enviado',4=>'Recibido',5=>'Cancelado'];
        foreach($chips as $idE=>$lbl):
          $qs=$_GET; $qs['estado']=$idE; unset($qs['page']); $href='?'.http_build_query($qs); ?>
          <a class="chip <?= ($estado===$idE?'on':'') ?>" href="<?= h($href) ?>"><?= h($lbl) ?></a>
        <?php endforeach; ?>
      </div>

      <form class="prod-filters" method="get">
        <input class="input-search" type="text" name="q" value="<?=h($q)?>" placeholder="Buscar pedido / proveedor…">
        <select name="prov">
          <option value="0">Todos los proveedores</option>
          <?php foreach($proveedores as $p): ?>
            <option value="<?=$p['id_proveedor']?>" <?=$idProv===$p['id_proveedor']?'selected':''?>><?=h($p['nombre'])?></option>
          <?php endforeach; ?>
        </select>
        <select name="suc">
          <option value="0">Todas las sucursales</option>
          <?php foreach($sucursales as $s): ?>
            <option value="<?=$s['id_sucursal']?>" <?=$idSuc===$s['id_sucursal']?'selected':''?>><?=h($s['nombre'])?></option>
          <?php endforeach; ?>
        </select>
        <input type="date" name="desde" value="<?=h($desde)?>">
        <input type="date" name="hasta" value="<?=h($hasta)?>">
        <button class="btn-filter" type="submit">Filtrar</button>
      </form>

      <div class="table-wrap">
        <table class="u-full-width">
          <thead>
            <tr>
              <th>ID</th><th>Proveedor</th><th>Sucursal</th><th>Fecha</th>
              <th>Estado</th><th>Renglones</th><th>Monto total</th><th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($rows as $r): ?>
              <?php $cls=[1=>'badge borrador',2=>'badge aprobado',3=>'badge enviado',4=>'badge recibido',5=>'badge cancelado'][$r['id_estado_pedido']] ?? 'badge'; ?>
              <tr>
                <td>#<?= (int)$r['id_pedido'] ?></td>
                <td><?= h($r['proveedor']) ?></td>
                <td><?= h($r['sucursal']) ?></td>
                <td><?= h($r['fecha_creado']) ?></td>
                <td><span class="<?= $cls ?>"><?= h($estados[$r['id_estado_pedido']] ?? '—') ?></span></td>
                <td><?= (int)$r['renglones'] ?></td>
                <td>$<?= number_format((float)$r['monto_total'],2,',','.') ?></td>
                <td><a class="btn-sm" href="/admin/pedidos/ver.php?id=<?= (int)$r['id_pedido'] ?>">Ver</a></td>
              </tr>
            <?php endforeach; ?>
            <?php if(empty($rows)): ?><tr><td colspan="8" class="muted">Sin resultados.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if($pages>1): ?>
        <div class="prod-pager">
          <?php for($p=1;$p<=$pages;$p++): $qs=$_GET; $qs['page']=$p; $href='?'.http_build_query($qs); ?>
            <a class="<?= $p===$page?'on':'' ?>" href="<?= h($href) ?>"><?= $p ?></a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>

<?php
$FLASH_OK  = $_SESSION['flash_ok']  ?? '';
$FLASH_ERR = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);
?>
<script>window.__FLASH__={ok:<?=json_encode($FLASH_OK,JSON_UNESCAPED_UNICODE)?>,err:<?=json_encode($FLASH_ERR,JSON_UNESCAPED_UNICODE)?>};</script>
<script src="/js/toast.js?v=1"></script>
</body></html>
