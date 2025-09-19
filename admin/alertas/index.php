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

if (session_status()===PHP_SESSION_NONE) session_start();
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Filtros
$q        = trim($_GET['q'] ?? '');
$idTipo   = (int)($_GET['tipo'] ?? 0);
$estado   = trim($_GET['estado'] ?? ''); // '', pendiente, resuelta
$desde    = trim($_GET['desde'] ?? '');
$hasta    = trim($_GET['hasta'] ?? '');

$page     = max(1,(int)($_GET['page']??1));
$perPage  = 15; 
$offset   = ($page-1)*$perPage;

// catálogo de tipos
$tipos=[]; 
$r=$conexion->query("SELECT id_tipo_alerta, nombre FROM tipo_alerta ORDER BY nombre");
while($row=$r->fetch_assoc()) $tipos[]=$row;

// WHERE dinámico
$w=[]; $params=[]; $types='';
if ($q!==''){ $w[]="(a.descripcion LIKE ? OR ta.nombre LIKE ? OR u.usuario LIKE ? OR p.nombre LIKE ? OR CAST(a.id_pedido AS CHAR) LIKE ?)"; 
              $params[]="%$q%"; $params[]="%$q%"; $params[]="%$q%"; $params[]="%$q%"; $params[]="%$q%"; 
              $types.='sssss'; }
if ($idTipo>0){ $w[]="a.id_tipo_alerta=?"; $params[]=$idTipo; $types.='i'; }
if ($estado==='pendiente' || $estado==='resuelta'){ $w[]="a.estado=?"; $params[]=$estado; $types.='s'; }
if ($desde!==''){ $w[]="DATE(a.created_at) >= ?"; $params[]=$desde; $types.='s'; }
if ($hasta!==''){ $w[]="DATE(a.created_at) <= ?"; $params[]=$hasta; $types.='s'; }
$whereSql = $w?('WHERE '.implode(' AND ',$w)):'';

// Total
$sqlCount="
  SELECT COUNT(*) total
  FROM alerta a
  LEFT JOIN tipo_alerta ta ON ta.id_tipo_alerta=a.id_tipo_alerta
  LEFT JOIN usuario u ON u.id_usuario=a.id_usuario
  LEFT JOIN producto p ON p.id_producto=a.id_producto
  $whereSql
";
$st=$conexion->prepare($sqlCount);
if($types) $st->bind_param($types, ...$params);
$st->execute();
$total=(int)($st->get_result()->fetch_assoc()['total']??0);
$st->close();
$pages=max(1,(int)ceil($total/$perPage));

// Lista
$sqlList="
  SELECT
    a.id_alerta,
    a.descripcion,
    a.estado,
    a.id_pedido,
    a.created_at,
    ta.nombre AS tipo_nombre,
    COALESCE(u.usuario,'—') AS usuario,
    COALESCE(p.nombre,'—') AS producto
  FROM alerta a
  LEFT JOIN tipo_alerta ta ON ta.id_tipo_alerta=a.id_tipo_alerta
  LEFT JOIN usuario u ON u.id_usuario=a.id_usuario
  LEFT JOIN producto p ON p.id_producto=a.id_producto
  $whereSql
  ORDER BY a.created_at DESC, a.id_alerta DESC
  LIMIT ? OFFSET ?
";
$typesList=$types.'ii';
$paramsList=$params; $paramsList[]=$perPage; $paramsList[]=$offset;

$st=$conexion->prepare($sqlList);
$st->bind_param($typesList, ...$paramsList);
$st->execute();
$rows=$st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
?>
<!doctype html><html lang="es"><head>
<meta charset="utf-8"><title>Alertas — Los Lapicitos</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/8.0.1/normalize.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/skeleton/2.0.4/skeleton.min.css">
<link rel="stylesheet" href="/libreria_lapicito/css/style.css">
<style>
/* Badges de estado, siguiendo tu paleta */
.badge { display:inline-block; padding:.25rem .55rem; border-radius:999px; font-size:.8rem; line-height:1; }
.badge-pendiente{ background:#fff4e5; color:#8a5200; border:1px solid #f4c07a; }
.badge-resuelta{  background:#e9f7ef; color:#1e7b43; border:1px solid #b9e6ca; }
.col-actions a { margin-right:.25rem; }
</style>
</head><body>
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
    <div class="inv-title">Panel administrativo — Alertas</div>

    <?php if(!empty($_SESSION['flash_ok'])): ?>
      <div class="alert-ok"><?= h($_SESSION['flash_ok']); unset($_SESSION['flash_ok']); ?></div>
    <?php endif; ?>
    <?php if(!empty($_SESSION['flash_err'])): ?>
      <div class="alert-error"><?= h($_SESSION['flash_err']); unset($_SESSION['flash_err']); ?></div>
    <?php endif; ?>

    <div class="prod-card">
      <div class="prod-head">
        <h5>Alertas</h5>
        <?php if (can('alertas.crear_manual')): ?>
          <a class="btn-add" href="/libreria_lapicito/admin/alertas/crear.php">+ Crear alerta</a>
        <?php endif; ?>
      </div>

      <form class="prod-filters" method="get">
        <input class="input-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por texto, usuario, producto o #pedido…">
        <select name="tipo">
          <option value="0">Todos los tipos</option>
          <?php foreach($tipos as $t): ?>
            <option value="<?= (int)$t['id_tipo_alerta'] ?>" <?= $idTipo===(int)$t['id_tipo_alerta']?'selected':'' ?>>
              <?= h($t['nombre']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <select name="estado">
          <option value="">Todos los estados</option>
          <option value="pendiente" <?= $estado==='pendiente'?'selected':'' ?>>Pendiente</option>
          <option value="resuelta"  <?= $estado==='resuelta'?'selected':''  ?>>Resuelta</option>
        </select>
        <input type="date" name="desde" value="<?= h($desde) ?>" title="Desde">
        <input type="date" name="hasta" value="<?= h($hasta) ?>" title="Hasta">
        <button class="btn-filter" type="submit">Filtrar</button>
      </form>

      <div class="table-wrap">
        <table class="u-full-width">
          <thead>
            <tr>
              <th style="width:80px">ID</th>
              <th style="width:180px">Tipo</th>
              <th>Descripción</th>
              <th style="width:120px">Estado</th>
              <th style="width:150px">Usuario</th>
              <th style="width:160px">Producto</th>
              <th style="width:120px">Pedido</th>
              <th style="width:150px">Creada</th>
              <th style="width:170px">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($rows as $r): ?>
              <tr>
                <td>#<?= (int)$r['id_alerta'] ?></td>
                <td><?= h($r['tipo_nombre'] ?? '—') ?></td>
                <td><?= h($r['descripcion'] ?? '—') ?></td>
                <td>
                  <?php if(($r['estado']??'')==='resuelta'): ?>
                    <span class="badge badge-resuelta">Resuelta</span>
                  <?php else: ?>
                    <span class="badge badge-pendiente">Pendiente</span>
                  <?php endif; ?>
                </td>
                <td><?= h($r['usuario'] ?? '—') ?></td>
                <td><?= h($r['producto'] ?? '—') ?></td>
                <td><?= $r['id_pedido']?('#'.(int)$r['id_pedido']):'—' ?></td>
                <td><?= h(date('d/m/Y H:i', strtotime($r['created_at']))) ?></td>
                <td class="col-actions">
                  <a class="btn-sm" href="/libreria_lapicito/admin/alertas/ver.php?id=<?= (int)$r['id_alerta'] ?>">Ver</a>
                  <?php if (can('alertas.resolver')): ?>
                    <?php if(($r['estado']??'')!=='resuelta'): ?>
                      <a class="btn-sm" href="/libreria_lapicito/admin/alertas/resolver.php?id=<?= (int)$r['id_alerta'] ?>">Resolver</a>
                    <?php endif; ?>
                  <?php endif; ?>
                  <?php if (can('alertas.eliminar')): ?>
                    <a class="btn-sm" href="/libreria_lapicito/admin/alertas/eliminar.php?id=<?= (int)$r['id_alerta'] ?>">eliminar</a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if(empty($rows)): ?>
              <tr><td colspan="9" class="muted">Sin resultados.</td></tr>
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
</body></html>
