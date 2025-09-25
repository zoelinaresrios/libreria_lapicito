<?php
// /admin/proveedores/index.php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';

$HAS_ACL = file_exists(__DIR__ . '/../includes/acl.php');
if ($HAS_ACL) { require_once __DIR__ . '/../includes/acl.php'; }
else {
  if (session_status()===PHP_SESSION_NONE) session_start();
  if (!function_exists('can')) { function can($k){ return true; } }
  if (!function_exists('require_perm')) { function require_perm($k){ return true; } }
}
require_perm('proveedores.ver');

if (session_status()===PHP_SESSION_NONE) session_start();
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// CSRF
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
$csrf=$_SESSION['csrf'];

// filtros
$q    = trim($_GET['q'] ?? '');
$page = max(1,(int)($_GET['page']??1));
$perPage=15; $offset=($page-1)*$perPage;

$w=[]; $types=''; $params=[];
if($q!==''){
  $w[]="(p.nombre LIKE ? OR p.email LIKE ? OR p.telefono LIKE ? OR p.contacto_referencia LIKE ?)";
  $types.='ssss';
  array_push($params,"%$q%","%$q%","%$q%","%$q%");
}
$where=$w?('WHERE '.implode(' AND ',$w)):'';

// conteo
$sqlCount="SELECT COUNT(*) c FROM proveedor p $where";
$st=$conexion->prepare($sqlCount);
if($types) $st->bind_param($types,...$params);
$st->execute();
$total=(int)$st->get_result()->fetch_assoc()['c'];
$st->close();
$pages=max(1,(int)ceil($total/$perPage));

// listado
$sql="SELECT p.id_proveedor, p.nombre, p.email, p.telefono, p.direccion, p.contacto_referencia,
             COALESCE(SUM(pd.cantidad_solicitada*pd.precio_unitario),0) AS total_compras,
             COUNT(DISTINCT pe.id_pedido) AS pedidos
      FROM proveedor p
      LEFT JOIN pedido pe ON pe.id_proveedor=p.id_proveedor
      LEFT JOIN pedido_detalle pd ON pd.id_pedido=pe.id_pedido
      $where
      GROUP BY p.id_proveedor
      ORDER BY p.nombre ASC
      LIMIT ? OFFSET ?";
$typesList=$types.'ii'; $paramsList=$params; $paramsList[]=$perPage; $paramsList[]=$offset;

$st=$conexion->prepare($sql);
$st->bind_param($typesList,...$paramsList);
$st->execute();
$rows=$st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
?>
<!doctype html><html lang="es"><head>
<meta charset="utf-8"><title>Proveedores — Los Lapicitos</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/vendor/normalize.css">
<link rel="stylesheet" href="/vendor/skeleton.css">
<link rel="stylesheet" href="/css/style.css?v=13">
<link rel="stylesheet" href="/css/toast.css?v=1">
<link rel="stylesheet" href="/css/proveedores.css?v=2">
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
      <li><a class="active" href="/admin/proveedores/">Proveedores</a></li>
      <li><a href="/admin/sucursales/">sucursales</a></li>
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
    <div class="inv-title">Proveedores</div>

    <div class="prod-card">
      <div class="prod-head">
        <h5>Listado</h5>
        <div>
          <?php if (can('proveedores.crear')): ?>
            <a class="btn-olive btn-sm" href="/admin/proveedores/crear.php">+ Nuevo Proveedor</a>
          <?php endif; ?>
          <a class="btn-olive btn-sm" href="/admin/proveedores/reportes.php">Reporte compras</a>
        </div>
      </div>

      <form class="prod-filters" method="get">
        <input class="input-search" type="text" name="q"
               value="<?= h($q) ?>"
               placeholder="Buscar por nombre, email, teléfono, contacto…">
        <button class="btn-filter" type="submit">Buscar</button>
      </form>

      <div class="table-wrap">
        <table class="u-full-width">
          <thead>
            <tr>
              <th>Nombre</th>
              <th>Contacto</th>
              <th>Email</th>
              <th>Teléfono</th>
              <th>Dirección</th>
              <th>Pedidos</th>
              <th>Total comprado</th>
              <th class="col-accion">Ver</th>
              <th class="col-accion">Editar</th>
              <th class="col-accion">Eliminar</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?=h($r['nombre'])?></td>
              <td><?=h($r['contacto_referencia'])?></td>
              <td><?=h($r['email'])?></td>
              <td><?=h($r['telefono'])?></td>
              <td><?=h($r['direccion'])?></td>
              <td><?= (int)$r['pedidos'] ?></td>
              <td>$<?= number_format((float)$r['total_compras'],2,',','.') ?></td>

              <td class="col-accion">
                <a class="btn-olive btn-sm" href="/admin/proveedores/ver.php?id=<?=$r['id_proveedor']?>">Ver</a>
              </td>
              <td class="col-accion">
                <?php if (can('proveedores.editar')): ?>
                  <a class="btn-olive btn-sm" href="/admin/proveedores/editar.php?id=<?=$r['id_proveedor']?>">Editar</a>
                <?php endif; ?>
              </td>
              <td class="col-accion">
                <?php if (can('proveedores.borrar')): ?>
                  <form method="post" action="/admin/proveedores/eliminar.php"
                        onsubmit="return confirm('¿Eliminar proveedor?');">
                    <input type="hidden" name="id" value="<?= (int)$r['id_proveedor'] ?>">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <button class="btn-olive btn-sm" type="submit">Eliminar</button>
                  </form>
                <?php endif; ?>
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
        <?php for($p=1;$p<=$pages;$p++):
          $qs=$_GET; $qs['page']=$p; $href='?'.http_build_query($qs); ?>
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
