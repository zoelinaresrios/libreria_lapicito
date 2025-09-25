<?php
// /admin/sucursales/index.php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';

$HAS_ACL = file_exists(__DIR__ . '/../includes/acl.php');
if ($HAS_ACL) { require_once __DIR__ . '/../includes/acl.php'; }
else {
  if (session_status()===PHP_SESSION_NONE) session_start();
  if (!function_exists('can')) { function can($k){ return true; } }
  if (!function_exists('require_perm')) { function require_perm($k){ return true; } }
}
require_perm('sucursales.ver');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (session_status()===PHP_SESSION_NONE) session_start();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Helper universal para bind_param (sin variádicos)
function _bind_params($stmt, $types, $params) {
  if (!$types) return;
  $a = [];
  $a[] = $types;
  // bind_param requiere referencias
  foreach ($params as $k => $v) { $a[] = &$params[$k]; }
  call_user_func_array([$stmt, 'bind_param'], $a);
}

// ====== KPIs globales ======
$kpi = [
  'suc_totales' => 0,
  'stock_total' => 0,
  'bajo_stock'  => 0,
  'ventas_mes'  => 0.0,
];

$r = $conexion->query("SELECT COUNT(*) c FROM sucursal")->fetch_assoc();
$kpi['suc_totales'] = (int)($r['c'] ?? 0);

$r = $conexion->query("SELECT COALESCE(SUM(stock_actual),0) s FROM inventario")->fetch_assoc();
$kpi['stock_total'] = (int)($r['s'] ?? 0);

$r = $conexion->query("SELECT COALESCE(SUM(CASE WHEN stock_actual < stock_minimo THEN 1 ELSE 0 END),0) c FROM inventario")->fetch_assoc();
$kpi['bajo_stock'] = (int)($r['c'] ?? 0);

$r = $conexion->query("
  SELECT COALESCE(SUM(vd.cantidad * vd.precio_unitario),0) total
    FROM venta v
    JOIN venta_detalle vd ON vd.id_venta = v.id_venta
   WHERE DATE_FORMAT(v.fecha_hora,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')
")->fetch_assoc();
$kpi['ventas_mes'] = (float)($r['total'] ?? 0.0);

// ====== Filtros y paginación ======
$q      = trim($_GET['q'] ?? '');
$page   = max(1,(int)($_GET['page'] ?? 1));
$perPage= 12;
$offset = ($page-1)*$perPage;

$where = ''; $types=''; $params=[];
if ($q !== '') {
  $where = "WHERE (s.nombre LIKE ? OR s.email LIKE ? OR s.direccion LIKE ? OR s.telefono LIKE ?)";
  $like = "%$q%";
  $types = 'ssss';
  $params = [$like,$like,$like,$like];
}

// Conteo
$sqlCount = "SELECT COUNT(*) total FROM sucursal s $where";
$st = $conexion->prepare($sqlCount);
_bind_params($st, $types, $params);
$st->execute();
$total = (int)($st->get_result()->fetch_assoc()['total'] ?? 0);
$st->close();
$pages = max(1, (int)ceil($total/$perPage));

// Listado + mini reportes por sucursal
$sql = "
SELECT 
  s.id_sucursal, s.nombre, s.direccion, s.email, s.telefono, s.creado_en,
  COALESCE(SUM(i.stock_actual),0) AS stock_total,
  COALESCE(SUM(CASE WHEN i.stock_actual < i.stock_minimo THEN 1 ELSE 0 END),0) AS bajo_stock_items,
  (
    SELECT COALESCE(SUM(vd.cantidad*vd.precio_unitario),0)
      FROM venta v 
      JOIN venta_detalle vd ON vd.id_venta=v.id_venta
     WHERE v.id_sucursal=s.id_sucursal
       AND DATE_FORMAT(v.fecha_hora,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')
  ) AS ventas_mes
FROM sucursal s
LEFT JOIN inventario i ON i.id_sucursal = s.id_sucursal
$where
GROUP BY s.id_sucursal, s.nombre, s.direccion, s.email, s.telefono, s.creado_en
ORDER BY s.nombre
LIMIT ? OFFSET ?
";
$typesList = $types . 'ii';
$paramsList = $params;
$paramsList[] = $perPage;
$paramsList[] = $offset;

$st = $conexion->prepare($sql);
_bind_params($st, $typesList, $paramsList);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// Helper mes y año
function mesY($ts=null){
  $ts = $ts ?? time();
  $m=['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
  return ucfirst($m[(int)date('n',$ts)-1]).' '.date('Y',$ts);
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Sucursales — Los Lapicitos</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/vendor/normalize.css">
  <link rel="stylesheet" href="/vendor/skeleton.css">
  <link rel="stylesheet" href="/css/style.css?v=13">
  <link rel="stylesheet" href="/css/sucursales.css?v=2">
</head>
<body>
<div class="barra"></div>

<div class="prod-shell">
  <aside class="prod-side">
    <ul class="prod-nav">
      <li><a href="/admin/index.php">inicio</a></li>
      <li><a href="/admin/productos/">Productos</a></li>
      <li><a href="/admin/categorias/">categorias</a></li>
      <li><a href="/admin/subcategorias/">subcategorias</a></li>
      <li><a href="/admin/inventario/">Inventario</a></li>
      <li><a href="/admin/pedidos/">Pedidos</a></li>
      <li><a href="/admin/proveedores/">Proveedores</a></li>
      <li><a class="active" href="/admin/sucursales/">sucursales</a></li>
      <li><a href="/admin/alertas/">Alertas</a></li>
      <li><a href="/admin/reportes/">Reportes y estadisticas</a></li>
      <li><a href="/admin/ventas/">Ventas</a></li>
      <li><a href="/admin/usuarios/">Usuarios</a></li>
      <li><a href="/admin/roles/">Roles y permisos</a></li>
      <li><a href="/admin/ajustes/">Ajustes</a></li>
      <li><a href="/admin/logout.php">Salir</a></li>
    </ul>
  </aside>

  <main class="prod-main">
    <div class="inv-title">Panel administrativo — Gestión de Sucursales</div>

    <div class="row">
      <div class="three columns">
        <div class="prod-card">
          <div class="prod-head"><h5>Total de sucursales</h5></div>
          <div class="kpi-val"><?= (int)$kpi['suc_totales'] ?></div>
          <div class="muted">Activas en el sistema</div>
        </div>
      </div>
      <div class="three columns">
        <div class="prod-card">
          <div class="prod-head"><h5>Stock total</h5></div>
          <div class="kpi-val"><?= number_format($kpi['stock_total'],0,',','.') ?></div>
          <div class="muted">Unidades (todas)</div>
        </div>
      </div>
      <div class="three columns">
        <div class="prod-card">
          <div class="prod-head"><h5>Items bajo stock</h5></div>
          <div class="kpi-val"><?= (int)$kpi['bajo_stock'] ?></div>
          <div class="muted">Stock &lt; mínimo</div>
        </div>
      </div>
      <div class="three columns">
        <div class="prod-card">
          <div class="prod-head"><h5>Ventas del mes</h5></div>
          <div class="kpi-val">$ <?= number_format($kpi['ventas_mes'],2,',','.') ?></div>
          <div class="muted"><?= mesY() ?></div>
        </div>
      </div>
    </div>

    <div class="prod-card">
      <div class="prod-head">
        <h5>Sucursales</h5>
        <div>
          <?php if (can('sucursales.crear')): ?>
            <a class="btn-add" href="/admin/sucursales/ver.php?new=1">+ Nueva Sucursal</a>
          <?php endif; ?>
          <?php if (can('movimientos.crear')): ?>
            <a class="btn-sm" href="/admin/sucursales/transferir.php">Transferir productos</a>
          <?php endif; ?>
        </div>
      </div>

      <form class="prod-filters" method="get">
        <input class="input-search" type="text" name="q" value="<?=h($q)?>" placeholder="Buscar por nombre, dirección, email o teléfono…">
        <button class="btn-filter" type="submit">Filtrar</button>
      </form>

      <div class="table-wrap">
        <table class="u-full-width">
          <thead>
            <tr>
              <th>ID</th>
              <th>Nombre</th>
              <th>Dirección</th>
              <th>Contacto</th>
              <th>Stock</th>
              <th>Bajo stock</th>
              <th>Ventas (<?=h(mesY())?>)</th>
              <th class="th-act">Ver</th>
              <th class="th-act">Editar</th>
              <th class="th-act">Transferir</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td>#<?= (int)$r['id_sucursal'] ?></td>
              <td><?= h($r['nombre']) ?></td>
              <td><?= h($r['direccion']) ?></td>
              <td><?= h($r['telefono']).'<br>'.h($r['email']) ?></td>
              <td><?= number_format((int)$r['stock_total'],0,',','.') ?></td>
              <td class="<?= ((int)$r['bajo_stock_items']>0?'text-warn':'') ?>">
                <?= (int)$r['bajo_stock_items'] ?>
              </td>
              <td>$ <?= number_format((float)$r['ventas_mes'],2,',','.') ?></td>

              <!-- Una columna por acción -->
              <td class="td-act">
                <a class="btn-sm" href="/admin/sucursales/ver.php?id=<?= (int)$r['id_sucursal'] ?>">Ver</a>
              </td>
              <td class="td-act">
                <?php if (can('sucursales.editar')): ?>
                  <a class="btn-sm" href="/admin/sucursales/ver.php?id=<?= (int)$r['id_sucursal'] ?>&edit=1">Editar</a>
                <?php else: ?><span class="muted">—</span><?php endif; ?>
              </td>
              <td class="td-act">
                <?php if (can('movimientos.crear')): ?>
                  <a class="btn-sm" href="/admin/sucursales/transferir.php?origen=<?= (int)$r['id_sucursal'] ?>">Mover</a>
                <?php else: ?><span class="muted">—</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if(empty($rows)): ?>
            <tr><td colspan="10" class="muted">Sin resultados.</td></tr>
          <?php endif; ?>
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
</body>
</html>
