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
require_perm('alertas.ver');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (session_status()===PHP_SESSION_NONE) session_start();

// CSRF
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

// Marcar atendida
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['accion']) && $_POST['accion']==='atender') {
  if (empty($_POST['csrf']) || $_POST['csrf']!==$csrf) { http_response_code(400); exit('CSRF inválido'); }
  require_perm('alertas.atender');
  $id = (int)($_POST['id'] ?? 0);
  if ($id>0) {
    $uid = (int)($_SESSION['user']['id_usuario'] ?? 0);
    $stmt = $conexion->prepare("UPDATE alerta SET atendida=1, fecha_atendida=NOW(), atendida_por=? WHERE id_alerta=? AND atendida=0");
    $stmt->bind_param('ii', $uid, $id);
    $stmt->execute();
    $_SESSION['flash_ok']='Alerta marcada como atendida.';
  }
  header('Location: '.$_SERVER['REQUEST_URI']); exit;
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }

// Filtros
$q        = trim($_GET['q'] ?? '');
$idSuc    = (int)($_GET['suc'] ?? 0);
$idCat    = (int)($_GET['cat'] ?? 0);
$idProv   = (int)($_GET['prov'] ?? 0);
$tipo     = (int)($_GET['tipo'] ?? 0); // 0 todos, 1 SB, 2 SS, 3 NV
$estado   = (int)($_GET['estado'] ?? 0); // 0 activas, 1 atendidas, 2 todas
$desde    = $_GET['desde'] ?? '';
$hasta    = $_GET['hasta'] ?? '';
$diasNV   = (int)($_GET['dias_nv'] ?? 30); // para botón generar

$page = max(1,(int)($_GET['page'] ?? 1));
$perPage=20; $offset=($page-1)*$perPage;

// Catálogos
$cats  = $conexion->query("SELECT id_categoria,nombre FROM categoria ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);
$provs = $conexion->query("SELECT id_proveedor,nombre FROM proveedor ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);
$sucs  = $conexion->query("SELECT id_sucursal,nombre FROM sucursal ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);

$w = []; $types=''; $params=[];
if ($estado===0) { $w[]="a.atendida=0"; }
elseif ($estado===1){ $w[]="a.atendida=1"; } // 2 = todas (no filtro)

if ($tipo>0){ $w[]="a.id_tipo_alerta=?"; $types.='i'; $params[]=$tipo; }
if ($idSuc>0){ $w[]="i.id_sucursal=?";    $types.='i'; $params[]=$idSuc; }
if ($idProv>0){ $w[]="p.id_proveedor=?";  $types.='i'; $params[]=$idProv; }
if ($idCat>0){ $w[]="sc.id_categoria=?";  $types.='i'; $params[]=$idCat; } // via subcategoria
if ($q!==''){ $w[]="(p.nombre LIKE ? OR p.codigo LIKE ?)"; $types.='ss'; $params[]="%$q%"; $params[]="%$q%"; }
if ($desde!==''){ $w[]="DATE(a.fecha_creada) >= ?"; $types.='s'; $params[]=$desde; }
if ($hasta!==''){ $w[]="DATE(a.fecha_creada) <= ?"; $types.='s'; $params[]=$hasta; }

$where = $w ? ('WHERE '.implode(' AND ', $w)) : '';

// Conteo
$sqlCount = "
  SELECT COUNT(*) c
    FROM alerta a
    JOIN inventario i ON i.id_inventario=a.id_inventario
    JOIN producto p   ON p.id_producto=a.id_producto
    LEFT JOIN subcategoria sc ON sc.id_subcategoria=p.id_subcategoria
    $where";
$st=$conexion->prepare($sqlCount); if($types) $st->bind_param($types,...$params); $st->execute();
$total=(int)($st->get_result()->fetch_assoc()['c'] ?? 0); $st->close();
$pages=max(1,(int)ceil($total/$perPage));

// Listado
$sqlList = "
  SELECT a.id_alerta, a.id_tipo_alerta, a.atendida, a.fecha_creada, a.fecha_atendida,
         p.id_producto, p.nombre AS producto, p.codigo,
         i.id_inventario, i.id_sucursal, i.stock_actual, i.stock_minimo,
         s.nombre AS sucursal,
         pr.nombre AS proveedor,
         ta.nombre_tipo
    FROM alerta a
    JOIN inventario i ON i.id_inventario=a.id_inventario
    JOIN sucursal s   ON s.id_sucursal=i.id_sucursal
    JOIN producto p   ON p.id_producto=a.id_producto
    LEFT JOIN proveedor pr ON pr.id_proveedor=p.id_proveedor
    JOIN tipo_alerta ta ON ta.id_tipo_alerta=a.id_tipo_alerta
    LEFT JOIN subcategoria sc ON sc.id_subcategoria=p.id_subcategoria
    $where
   ORDER BY a.atendida ASC, a.fecha_creada DESC
   LIMIT ? OFFSET ?";
$typesList = $types.'ii'; $paramsList=$params; $paramsList[]=$perPage; $paramsList[]=$offset;

$st=$conexion->prepare($sqlList); $st->bind_param($typesList, ...$paramsList); $st->execute();
$rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

$chipsTipo=[0=>'Todas',1=>'Stock bajo',2=>'Sin stock',3=>'Sin ventas'];
$chipsEstado=[0=>'Activas',1=>'Atendidas',2=>'Todas'];
?>
<!doctype html><html lang="es"><head>
<meta charset="utf-8"><title>Alertas — Los Lapicitos</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/vendor/normalize.css">
<link rel="stylesheet" href="/vendor/skeleton.css">
<link rel="stylesheet" href="/css/style.css?v=13">
<link rel="stylesheet" href="/css/toast.css?v=1">
<style>
.badge.alerta{padding:.15rem .5rem;border-radius:8px;font-size:12px;font-weight:700}
.badge.sb{background:#fff2cc;border:1px solid #ffe08a}
.badge.ss{background:#ffd6d6;border:1px solid #ff9c9c}
.badge.nv{background:#e5f0ff;border:1px solid #b9d2ff}
.row .btn-gen{margin-left:.5rem}
</style>
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
      <li><a href="/admin/pedidos/">Pedidos</a></li>
      <li><a class="active" href="/admin/alertas/">Alertas</a></li>
      <li><a href="/admin/reportes/">Reportes</a></li>
      <li><a href="/admin/ventas/">Ventas</a></li>
      <li><a href="/admin/usuarios/">Usuarios</a></li>
    </ul>
  </aside>

  <main class="prod-main">
    <div class="inv-title">Panel administrativo — Alertas</div>

    <div class="prod-card">
      <div class="prod-head">
        <h5>Alertas activas</h5>
        <div class="flex">
          <form method="post" action="/admin/alertas/generar.php" style="display:inline">
            <input type="hidden" name="csrf" value="<?=$csrf?>">
            <input type="hidden" name="dias_sin_ventas" value="<?=$diasNV?>">
            <button class="btn-add btn-gen" type="submit">Generar ahora</button>
          </form>
          <form method="post" action="/admin/alertas/enviar_resumen.php" style="display:inline;margin-left:.5rem">
  <input type="hidden" name="csrf" value="<?=$csrf?>">
  <input type="hidden" name="suc" value="<?= (int)$idSuc ?>">
  <button class="btn-sm" type="submit">Enviar listado Stock Bajo</button>
</form>

          <form method="get" style="display:inline">
            <input type="number" name="dias_nv" value="<?=$diasNV?>" min="1" style="width:120px" />
            <button class="btn-sm" type="submit">Aplicar días sin ventas</button>
          </form>
        </div>
      </div>

      <div class="prod-toolbar">
        <?php foreach($chipsEstado as $id=>$lbl):
          $qs=$_GET; $qs['estado']=$id; unset($qs['page']); ?>
          <a class="chip <?= ($estado===$id?'on':'') ?>" href="?<?=h(http_build_query($qs))?>"><?=$lbl?></a>
        <?php endforeach; ?>
        <span style="width:12px;display:inline-block"></span>
        <?php foreach($chipsTipo as $id=>$lbl):
          $qs=$_GET; $qs['tipo']=$id; unset($qs['page']); ?>
          <a class="chip <?= ($tipo===$id?'on':'') ?>" href="?<?=h(http_build_query($qs))?>"><?=$lbl?></a>
        <?php endforeach; ?>
      </div>

      <form class="prod-filters" method="get">
        <input class="input-search" type="text" name="q" value="<?=h($q)?>" placeholder="Buscar producto / código…">
        <select name="prov">
          <option value="0">Todos los proveedores</option>
          <?php foreach($provs as $p): ?>
            <option value="<?=$p['id_proveedor']?>" <?=$idProv===$p['id_proveedor']?'selected':''?>><?=h($p['nombre'])?></option>
          <?php endforeach; ?>
        </select>
        <select name="cat">
          <option value="0">Todas las categorías</option>
          <?php foreach($cats as $c): ?>
            <option value="<?=$c['id_categoria']?>" <?=$idCat===$c['id_categoria']?'selected':''?>><?=h($c['nombre'])?></option>
          <?php endforeach; ?>
        </select>
        <select name="suc">
          <option value="0">Todas las sucursales</option>
          <?php foreach($sucs as $s): ?>
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
              <th>#</th><th>Tipo</th><th>Producto</th><th>Sucursal</th>
              <th>Stock</th><th>Proveedor</th><th>Creada</th><th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($rows as $r): 
              $bclass = $r['id_tipo_alerta']==1?'sb':($r['id_tipo_alerta']==2?'ss':'nv'); ?>
              <tr>
                <td><?= (int)$r['id_alerta'] ?></td>
                <td><span class="badge alerta <?=$bclass?>"><?= h($r['nombre_tipo']) ?></span></td>
                <td><?= h($r['producto']) ?> <small class="muted"><?= h($r['codigo']) ?></small></td>
                <td><?= h($r['sucursal']) ?></td>
                <td><?= (int)$r['stock_actual'] ?> / min <?= (int)$r['stock_minimo'] ?></td>
                <td><?= h($r['proveedor'] ?? '—') ?></td>
                <td><?= h($r['fecha_creada']) ?></td>
                <td>
                  <?php if(!$r['atendida'] && can('alertas.atender')): ?>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?=$csrf?>">
                    <input type="hidden" name="accion" value="atender">
                    <input type="hidden" name="id" value="<?=$r['id_alerta']?>">
                    <button class="btn-sm" type="submit">Marcar atendida</button>
                  </form>
                  <?php else: ?>
                    <span class="muted">Atendida <?= h($r['fecha_atendida'] ?? '') ?></span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
              <tr><td colspan="8" class="muted">Sin alertas con estos filtros.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if($pages>1): ?>
      <div class="prod-pager">
        <?php for($p=1;$p<=$pages;$p++): $qs=$_GET; $qs['page']=$p; ?>
          <a class="<?= $p===$page?'on':'' ?>" href="?<?=h(http_build_query($qs))?>"><?=$p?></a>
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
