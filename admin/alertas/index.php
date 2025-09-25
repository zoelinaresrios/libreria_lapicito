<?php
// /admin/alertas/index.php
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

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }
function flash_ok($m){ $_SESSION['flash_ok']=$m; }
function flash_err($m){ $_SESSION['flash_err']=$m; }

// ====== Acciones POST (individuales y masivas) ======
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (empty($_POST['csrf']) || $_POST['csrf']!==$csrf) { http_response_code(400); exit('CSRF inválido'); }

  $accion = $_POST['accion'] ?? '';

  // ---- Crear alerta manual ----
  if ($accion==='crear') {
    require_perm('alertas.crear');
    $idProd = (int)($_POST['id_producto'] ?? 0);
    $idSuc  = (int)($_POST['id_sucursal'] ?? 0);
    $tipo   = (int)($_POST['id_tipo_alerta'] ?? 0);
    if ($idProd>0 && $idSuc>0 && in_array($tipo,[1,2,3],true)) {
      // Obtener o crear inventario
      $st = $conexion->prepare("SELECT id_inventario FROM inventario WHERE id_sucursal=? AND id_producto=? LIMIT 1");
      $st->bind_param('ii',$idSuc,$idProd); $st->execute();
      $row = $st->get_result()->fetch_assoc(); $st->close();
      if ($row) { $idInv = (int)$row['id_inventario']; }
      else {
        $st = $conexion->prepare("INSERT INTO inventario (id_sucursal,id_producto,stock_actual,stock_minimo,ubicacion,actualizado_en) VALUES (?,?,0,0,'',NOW())");
        $st->bind_param('ii',$idSuc,$idProd); $st->execute();
        $idInv = $st->insert_id; $st->close();
      }
      $st = $conexion->prepare("INSERT INTO alerta (id_producto,id_tipo_alerta,id_inventario,atendida,fecha_creada) VALUES (?,?,?,?,NOW())");
      $zero=0;
      $st->bind_param('iiii',$idProd,$tipo,$idInv,$zero);
      $st->execute(); $st->close();
      flash_ok('Alerta creada correctamente.');
    } else {
      flash_err('Datos incompletos para crear la alerta.');
    }
    header('Location: '.$_SERVER['PHP_SELF'].'?'.http_build_query($_GET)); exit;
  }

  // ---- Cambiar estado individual (atender / reabrir) ----
  if ($accion==='atender' || $accion==='reabrir') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id>0) {
      require_perm('alertas.atender');
      $uid = (int)($_SESSION['user']['id_usuario'] ?? 0);
      if ($accion==='atender') {
        $st = $conexion->prepare("UPDATE alerta SET atendida=1, fecha_atendida=NOW(), atendida_por=? WHERE id_alerta=? AND atendida=0");
        $st->bind_param('ii',$uid,$id);
      } else { // reabrir
        $st = $conexion->prepare("UPDATE alerta SET atendida=0, fecha_atendida=NULL, atendida_por=NULL WHERE id_alerta=?");
        $st->bind_param('i',$id);
      }
      $st->execute(); $st->close();
      flash_ok($accion==='atender'?'Alerta marcada como atendida.':'Alerta reabierta.');
    }
    header('Location: '.$_SERVER['PHP_SELF'].'?'.http_build_query($_GET)); exit;
  }

  // ---- Eliminar individual ----
  if ($accion==='eliminar') {
    require_perm('alertas.eliminar');
    $id = (int)($_POST['id'] ?? 0);
    if ($id>0){
      $st=$conexion->prepare("DELETE FROM alerta WHERE id_alerta=?");
      $st->bind_param('i',$id); $st->execute(); $st->close();
      flash_ok('Alerta eliminada.');
    }
    header('Location: '.$_SERVER['PHP_SELF'].'?'.http_build_query($_GET)); exit;
  }

  // ---- Acciones masivas (ids[]) ----
  if ($accion==='bulk') {
    $op = $_POST['op'] ?? '';
    $ids = array_map('intval', $_POST['ids'] ?? []);
    if (!$ids){ flash_err('No seleccionaste alertas.'); header('Location: '.$_SERVER['REQUEST_URI']); exit; }

    $in = implode(',', array_fill(0,count($ids),'?'));
    $types = str_repeat('i', count($ids));
    if ($op==='atender') {
      require_perm('alertas.atender');
      $uid=(int)($_SESSION['user']['id_usuario'] ?? 0);
      $sql="UPDATE alerta SET atendida=1, fecha_atendida=NOW(), atendida_por=$uid WHERE id_alerta IN ($in)";
      $st=$conexion->prepare($sql); $st->bind_param($types, ...$ids); $st->execute(); $st->close();
      flash_ok('Alertas atendidas.');
    } elseif ($op==='reabrir') {
      require_perm('alertas.atender');
      $sql="UPDATE alerta SET atendida=0, fecha_atendida=NULL, atendida_por=NULL WHERE id_alerta IN ($in)";
      $st=$conexion->prepare($sql); $st->bind_param($types, ...$ids); $st->execute(); $st->close();
      flash_ok('Alertas reabiertas.');
    } elseif ($op==='eliminar') {
      require_perm('alertas.eliminar');
      $sql="DELETE FROM alerta WHERE id_alerta IN ($in)";
      $st=$conexion->prepare($sql); $st->bind_param($types, ...$ids); $st->execute(); $st->close();
      flash_ok('Alertas eliminadas.');
    } else {
      flash_err('Acción masiva no válida.');
    }
    header('Location: '.$_SERVER['PHP_SELF'].'?'.http_build_query($_GET)); exit;
  }
}

// ====== Filtros/params (incluye "ver" detalle) ======
$q        = trim($_GET['q'] ?? '');
$idSuc    = (int)($_GET['suc'] ?? 0);
$idCat    = (int)($_GET['cat'] ?? 0);
$idProv   = (int)($_GET['prov'] ?? 0);
$tipo     = (int)($_GET['tipo'] ?? 0); // 0 todos, 1 SB, 2 SS, 3 NV
$estado   = (int)($_GET['estado'] ?? 0); // 0 activas, 1 atendidas, 2 todas
$desde    = $_GET['desde'] ?? '';
$hasta    = $_GET['hasta'] ?? '';
$diasNV   = (int)($_GET['dias_nv'] ?? 30);
$viewId   = (int)($_GET['view'] ?? 0);

$page = max(1,(int)($_GET['page'] ?? 1));
$perPage=20; $offset=($page-1)*$perPage;

// Catálogos
$cats  = $conexion->query("SELECT id_categoria,nombre FROM categoria ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);
$provs = $conexion->query("SELECT id_proveedor,nombre FROM proveedor ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);
$sucs  = $conexion->query("SELECT id_sucursal,nombre FROM sucursal ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);
$tipos = $conexion->query("SELECT id_tipo_alerta,nombre_tipo FROM tipo_alerta ORDER BY id_tipo_alerta")->fetch_all(MYSQLI_ASSOC);

// Para crear: listado liviano de productos (los recientes/activos). Podés reemplazar por buscador ajax si querés.
$prods = $conexion->query("SELECT id_producto,nombre,codigo FROM producto WHERE activo=1 ORDER BY actualizado_en DESC LIMIT 250")->fetch_all(MYSQLI_ASSOC);

// WHERE dinámico
$w = []; $types=''; $params=[];
if ($estado===0) { $w[]="a.atendida=0"; }
elseif ($estado===1){ $w[]="a.atendida=1"; } // 2 = todas (sin filtro)

if ($tipo>0){ $w[]="a.id_tipo_alerta=?"; $types.='i'; $params[]=$tipo; }
if ($idSuc>0){ $w[]="i.id_sucursal=?";    $types.='i'; $params[]=$idSuc; }
if ($idProv>0){ $w[]="p.id_proveedor=?";  $types.='i'; $params[]=$idProv; }
if ($idCat>0){ $w[]="sc.id_categoria=?";  $types.='i'; $params[]=$idCat; }
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
  SELECT a.id_alerta, a.id_tipo_alerta, a.atendida, a.fecha_creada, a.fecha_atendida, a.atendida_por,
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

$st=$conexion->prepare($sqlList); if($typesList) $st->bind_param($typesList, ...$paramsList); $st->execute();
$rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

// Detalle (ver)
$detalle = null;
if ($viewId>0){
  $sql = "
    SELECT a.*, ta.nombre_tipo,
           p.nombre AS producto, p.codigo, pr.nombre AS proveedor,
           i.id_sucursal, i.stock_actual, i.stock_minimo, s.nombre AS sucursal,
           u.nombre AS atendido_por
    FROM alerta a
    JOIN tipo_alerta ta ON ta.id_tipo_alerta=a.id_tipo_alerta
    JOIN producto p ON p.id_producto=a.id_producto
    LEFT JOIN proveedor pr ON pr.id_proveedor=p.id_proveedor
    JOIN inventario i ON i.id_inventario=a.id_inventario
    JOIN sucursal s ON s.id_sucursal=i.id_sucursal
    LEFT JOIN usuario u ON u.id_usuario=a.atendida_por
    WHERE a.id_alerta=? LIMIT 1";
  $st=$conexion->prepare($sql); $st->bind_param('i',$viewId); $st->execute();
  $detalle=$st->get_result()->fetch_assoc(); $st->close();
}

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
/* Pills de tipo */
.badge.alerta{padding:.15rem .5rem;border-radius:8px;font-size:12px;font-weight:700}
.badge.sb{background:#fff2cc;border:1px solid #ffe08a}
.badge.ss{background:#ffd6d6;border:1px solid #ff9c9c}
.badge.nv{background:#e5f0ff;border:1px solid #b9d2ff}

/* Botones verdes redondeaditos y rojos para eliminar */
.btn-sm, .btn-add, .btn-filter, .btn-green, .btn-red, .btn-line{
  border-radius:12px; 
  line-height:1;
   padding:.5rem .75rem;
    border:0;
    text-decoration:none; cursor:pointer;
}
.btn-green{ background:#8a956e; color:#fff }
.btn-red{ background:#dd4b39; color:#fff }
.btn-filter{ background:#8a956e; color:#fff }
.btn-line{ background:#fff; border:1px solid var(--borde); }
.btn-green:hover{filter:brightness(.95)}
.btn-red:hover{filter:brightness(.80)}
.btn-line:hover{background:#fafafa}


.table-wrap{ overflow:auto }
.bulkbar{
  display:none; align-items:center; gap:.5rem; padding:.5rem; border:1px dashed var(--borde);
  border-radius:12px; margin:.5rem 0 .75rem 0; background:#f8fff4;
}

/* panel detalle */
.alerta-card{
  border:1px solid var(--borde); border-radius:16px; padding:12px; margin-bottom:12px; background:#fff
}
.alerta-grid{ display:grid; grid-template-columns: repeat(4,1fr); gap:8px }
.alerta-grid .cell{ background:#fafafa; padding:8px; border-radius:10px; font-size:13px }
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
      <li><a href="/admin/proveedores/">Proveedores</a></li>
      <li><a href="/admin/sucursales/">Sucursales</a></li>
      <li><a class="active" href="/admin/alertas/">Alertas</a></li>
      <li><a href="/admin/reportes/">Reportes y estadísticas</a></li>
      <li><a href="/admin/ventas/">Ventas</a></li>
      <li><a href="/admin/usuarios/">Usuarios</a></li>
      <li><a href="/admin/roles/">Roles y permisos</a></li>
      <li><a href="/admin/ajustes/">Ajustes</a></li>
      <li><a href="/admin/auditorias/">Auditorías</a></li>
      <li><a href="/admin/logout.php">Salir</a></li>
    </ul>
  </aside>

  <!-- Main -->
  <main class="col-12 col-md-9 col-lg-10 p-4">
    <h4 class="mb-3">Panel administrativo — Alertas</h4>

    <!-- Panel detalle (ver) -->
    <?php if($detalle): ?>
      <div class="alerta-card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
          <strong>#<?= (int)$detalle['id_alerta'] ?> — <?= h($detalle['nombre_tipo']) ?></strong>
          <a class="btn-line" href="<?= h($_SERVER['PHP_SELF'].'?'.http_build_query(array_diff_key($_GET,['view'=>true]))) ?>">Cerrar</a>
        </div>
        <div class="alerta-grid">
          <div class="cell"><b>Producto:</b> <?= h($detalle['producto']) ?> <small class="muted"><?= h($detalle['codigo']) ?></small></div>
          <div class="cell"><b>Sucursal:</b> <?= h($detalle['sucursal']) ?></div>
          <div class="cell"><b>Proveedor:</b> <?= h($detalle['proveedor'] ?? '—') ?></div>
          <div class="cell"><b>Estado:</b> <?= $detalle['atendida']?'Atendida':'Activa' ?></div>
          <div class="cell"><b>Creada:</b> <?= h($detalle['fecha_creada']) ?></div>
          <div class="cell"><b>Atendida:</b> <?= h($detalle['fecha_atendida'] ?? '—') ?></div>
          <div class="cell"><b>Atendida por:</b> <?= h($detalle['atendido_por'] ?? '—') ?></div>
          <div class="cell"><b>Stock:</b> <?= (int)$detalle['stock_actual'] ?> / min <?= (int)$detalle['stock_minimo'] ?></div>
        </div>
        <div style="margin-top:8px">
          <?php if(!$detalle['atendida'] && can('alertas.atender')): ?>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?=$csrf?>">
              <input type="hidden" name="accion" value="atender">
              <input type="hidden" name="id" value="<?=$detalle['id_alerta']?>">
              <button class="btn-green">Marcar atendida</button>
            </form>
          <?php elseif(can('alertas.atender')): ?>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?=$csrf?>">
              <input type="hidden" name="accion" value="reabrir">
              <input type="hidden" name="id" value="<?=$detalle['id_alerta']?>">
              <button class="btn-line">Reabrir</button>
            </form>
          <?php endif; ?>
          <?php if(can('alertas.eliminar')): ?>
            <form method="post" onsubmit="return confirm('¿Eliminar alerta?')" style="display:inline;margin-left:.5rem">
              <input type="hidden" name="csrf" value="<?=$csrf?>">
              <input type="hidden" name="accion" value="eliminar">
              <input type="hidden" name="id" value="<?=$detalle['id_alerta']?>">
              <button class="btn-red">Eliminar</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="prod-card">
      <div class="prod-head">
        <h5>Alertas</h5>
        <div class="flex">
          <form method="post" action="/admin/alertas/generar.php" style="display:inline">
            <input type="hidden" name="csrf" value="<?=$csrf?>">
            <input type="hidden" name="dias_sin_ventas" value="<?=$diasNV?>">
            <button class="btn-add btn-green btn-gen" type="submit">Generar ahora</button>
          </form>
          <form method="post" action="/admin/alertas/enviar_resumen.php" style="display:inline;margin-left:.5rem">
            <input type="hidden" name="csrf" value="<?=$csrf?>">
            <input type="hidden" name="suc" value="<?= (int)$idSuc ?>">
            <button class="btn-line" type="submit">Enviar listado Stock Bajo</button>
          </form>
          <form method="get" style="display:inline;margin-left:.5rem">
            <?php foreach($_GET as $k=>$v){ if($k==='dias_nv') continue; ?>
              <input type="hidden" name="<?=h($k)?>" value="<?=h($v)?>">
            <?php } ?>
            <input type="number" name="dias_nv" value="<?=$diasNV?>" min="1" style="width:120px" />
            <button class="btn-line" type="submit">Aplicar días sin ventas</button>
          </form>
        </div>
      </div>

      <!-- Toggler crear -->
      <?php if (can('alertas.crear')): ?>
      <details style="margin:.5rem 0 1rem">
        <summary class="btn-line" style="display:inline-block;padding:.5rem .75rem;border-radius:12px">➕ Nueva alerta manual</summary>
        <form method="post" style="margin-top:.75rem;display:grid;grid-template-columns:repeat(5,minmax(160px,1fr));gap:.5rem;align-items:end">
          <input type="hidden" name="csrf" value="<?=$csrf?>">
          <input type="hidden" name="accion" value="crear">
          <label>Producto
            <select name="id_producto" required>
              <option value="">— Seleccionar —</option>
              <?php foreach($prods as $p): ?>
                <option value="<?=$p['id_producto']?>"><?=h($p['nombre'])?> — <?=h($p['codigo'])?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Sucursal
            <select name="id_sucursal" required>
              <option value="">— Seleccionar —</option>
              <?php foreach($sucs as $s): ?>
                <option value="<?=$s['id_sucursal']?>"><?=h($s['nombre'])?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Tipo
            <select name="id_tipo_alerta" required>
              <option value="">— Seleccionar —</option>
              <?php foreach($tipos as $t): ?>
                <option value="<?=$t['id_tipo_alerta']?>"><?=h($t['nombre_tipo'])?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <button class="btn-green" type="submit">Crear</button>
        </form>
      </details>
      <?php endif; ?>

      <!-- chips -->
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

      <!-- filtros -->
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

      <!-- barra masiva -->
      <form id="bulkForm" method="post" class="bulkbar">
        <input type="hidden" name="csrf" value="<?=$csrf?>">
        <input type="hidden" name="accion" value="bulk">
        <input type="hidden" name="op" id="bulkOp" value="">
        <span id="bulkCount">0 seleccionadas</span>
        <?php if (can('alertas.atender')): ?>
          <button class="btn-green" onclick="document.getElementById('bulkOp').value='atender'">Atender</button>
          <button class="btn-green" onclick="document.getElementById('bulkOp').value='reabrir'">Reabrir</button>
        <?php endif; ?>
        <?php if (can('alertas.eliminar')): ?>
          <button class="btn-red" onclick="if(confirm('¿Eliminar seleccionadas?')){document.getElementById('bulkOp').value='eliminar'} else {return false;}">Eliminar</button>
        <?php endif; ?>
      </form>

      <div class="table-wrap">
        <table class="u-full-width">
         <thead>
  <tr>
    <th><input type="checkbox" id="chkAll"></th>
    <th>#</th>
    <th>Tipo</th>
    <th>Producto</th>
    <th>Sucursal</th>
    <th>Stock</th>
    <th>Proveedor</th>
    <th>Creada</th>
    <th>Ver</th>
    <th>Estado</th>
    <th>Eliminar</th>
  </tr>
</thead>
<tbody>
  <?php foreach($rows as $r):
    $bclass = $r['id_tipo_alerta']==1?'sb':($r['id_tipo_alerta']==2?'ss':'nv');
    $qs=$_GET; $qs['view']=$r['id_alerta']; $viewHref='?'.h(http_build_query($qs));
  ?>
    <tr>
      <td><input type="checkbox" class="chkRow" value="<?=$r['id_alerta']?>" form="bulkForm" name="ids[]"></td>
      <td><?= (int)$r['id_alerta'] ?></td>
      <td><span class="badge alerta <?=$bclass?>"><?= h($r['nombre_tipo']) ?></span></td>
      <td><?= h($r['producto']) ?> <small class="muted"><?= h($r['codigo']) ?></small></td>
      <td><?= h($r['sucursal']) ?></td>
      <td><?= (int)$r['stock_actual'] ?> / min <?= (int)$r['stock_minimo'] ?></td>
      <td><?= h($r['proveedor'] ?? '—') ?></td>
      <td><?= h($r['fecha_creada']) ?></td>

      <!-- Columna Ver -->
      <td>
        <a class="btn-line" href="<?=$viewHref?>">Ver</a>
      </td>

      <!-- Columna Estado -->
      <td>
        <?php if(!$r['atendida'] && can('alertas.atender')): ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?=$csrf?>">
            <input type="hidden" name="accion" value="atender">
            <input type="hidden" name="id" value="<?=$r['id_alerta']?>">
            <button class="btn-green" type="submit">Atender</button>
          </form>
        <?php elseif(can('alertas.atender')): ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?=$csrf?>">
            <input type="hidden" name="accion" value="reabrir">
            <input type="hidden" name="id" value="<?=$r['id_alerta']?>">
            <button class="btn-line" type="submit">Reabrir</button>
          </form>
        <?php else: ?>
          <span class="muted">—</span>
        <?php endif; ?>
      </td>

      <!-- Columna Eliminar -->
      <td>
        <?php if(can('alertas.eliminar')): ?>
          <form method="post" onsubmit="return confirm('¿Eliminar alerta #<?=$r['id_alerta']?>?')">
            <input type="hidden" name="csrf" value="<?=$csrf?>">
            <input type="hidden" name="accion" value="eliminar">
            <input type="hidden" name="id" value="<?=$r['id_alerta']?>">
            <button class="btn-red" type="submit">Eliminar</button>
          </form>
        <?php else: ?>
          <span class="muted">—</span>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
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

      <?php if(!empty($_SESSION['flash_err'])): ?>
        <div class="alert alert-danger"><?= h($_SESSION['flash_err']); unset($_SESSION['flash_err']); ?></div>
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
<script>
// Selección masiva
const chkAll = document.getElementById('chkAll');
const chks   = Array.from(document.querySelectorAll('.chkRow'));
const bulk   = document.getElementById('bulkForm');
const lblCnt = document.getElementById('bulkCount');
function refreshBulk(){
  const count = chks.filter(c=>c.checked).length;
  lblCnt.textContent = count+' seleccionadas';
  bulk.style.display = count>0 ? 'flex' : 'none';
}
if (chkAll){ chkAll.addEventListener('change',()=>{ chks.forEach(c=>c.checked=chkAll.checked); refreshBulk(); }); }
chks.forEach(c=>c.addEventListener('change',refreshBulk));
</script>
</body></html>
