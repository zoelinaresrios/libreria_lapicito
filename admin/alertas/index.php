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

// filtros
$q        = trim($_GET['q'] ?? '');
$idTipo   = (int)($_GET['tipo'] ?? 0);
$estado   = trim($_GET['estado'] ?? '');
$desde    = trim($_GET['desde'] ?? '');
$hasta    = trim($_GET['hasta'] ?? '');

$page     = max(1,(int)($_GET['page']??1));
$perPage  = 15; 
$offset   = ($page-1)*$perPage;

// tipos
$tipos=[]; 
$r=$conexion->query("SELECT id_tipo_alerta, nombre FROM tipo_alerta ORDER BY nombre");
while($row=$r->fetch_assoc()) $tipos[]=$row;

// where dinámico
$w=[]; $params=[]; $types='';
if ($q!==''){
  $w[]="(a.descripcion LIKE ? OR ta.nombre LIKE ? OR u.usuario LIKE ? OR p.nombre LIKE ? OR CAST(a.id_pedido AS CHAR) LIKE ?)";
  $params=array_merge($params,["%$q%","%$q%","%$q%","%$q%","%$q%"]);
  $types.='sssss';
}
if ($idTipo>0){ $w[]="a.id_tipo_alerta=?"; $params[]=$idTipo; $types.='i'; }
if ($estado==='pendiente' || $estado==='resuelta'){ $w[]="a.estado=?"; $params[]=$estado; $types.='s'; }
if ($desde!==''){ $w[]="DATE(a.created_at) >= ?"; $params[]=$desde; $types.='s'; }
if ($hasta!==''){ $w[]="DATE(a.created_at) <= ?"; $params[]=$hasta; $types.='s'; }
$whereSql = $w?('WHERE '.implode(' AND ',$w)):'';

// total
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

// lista
$sqlList="
  SELECT
    a.id_alerta, a.descripcion, a.estado, a.id_pedido, a.created_at,
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
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Alertas — Los Lapicitos</title>
<meta name="viewport" content="width=device-width,initial-scale=1">

<!-- Bootstrap -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="/css/admin.css" rel="stylesheet">
</head>
<body>

<div class="container-fluid">
  <div class="row">
    <!-- Sidebar -->
    <aside class="col-12 col-md-3 col-lg-2 p-3 bg-light sidebar">
      <ul class="nav flex-column">
        <li class="nav-item"><a class="nav-link" href="//admin/index.php">Inicio</a></li>
        <?php if (can('productos.ver')): ?>
        <li class="nav-item"><a class="nav-link" href="/admin/productos/">Productos</a></li>
        <?php endif; ?>
        <li class="nav-item"><a class="nav-link" href="/admin/categorias/">Categorías</a></li>
        <?php if (can('inventario.ver')): ?>
        <li class="nav-item"><a class="nav-link" href="/admin/subcategorias/">Subcategorías</a></li>
        <li class="nav-item"><a class="nav-link" href="/admin/inventario/">Inventario</a></li>
        <?php endif; ?>
        <?php if (can('pedidos.aprobar')): ?>
        <li class="nav-item"><a class="nav-link" href="/admin/pedidos/">Pedidos</a></li>
        <?php endif; ?>
        <?php if (can('alertas.ver')): ?>
        <li class="nav-item"><a class="nav-link active" href="/admin/alertas/">Alertas</a></li>
        <?php endif; ?>
        <?php if (can('reportes.detallados') || can('reportes.simple')): ?>
        <li class="nav-item"><a class="nav-link" href="/admin/reportes/">Reportes</a></li>
        <?php endif; ?>
        <?php if (can('ventas.rapidas')): ?>
        <li class="nav-item"><a class="nav-link" href="/admin/ventas/">Ventas</a></li>
        <?php endif; ?>
        <?php if (can('usuarios.gestionar') || can('usuarios.crear_empleado')): ?>
        <li class="nav-item"><a class="nav-link" href="/admin/usuarios/">Usuarios</a></li>
        <?php endif; ?>
        <?php if (can('usuarios.gestionar')): ?>
        <li class="nav-item"><a class="nav-link" href="/admin/roles/">Roles y permisos</a></li>
        <?php endif; ?>
        <li class="nav-item"><a class="nav-link" href="/admin/ajustes/">Ajustes</a></li>
        <li class="nav-item"><a class="nav-link text-danger" href="/admin/logout.php">Salir</a></li>
      </ul>
    </aside>

    <!-- Main -->
    <main class="col-12 col-md-9 col-lg-10 p-4">
      <h4 class="mb-3">Panel administrativo — Alertas</h4>

      <?php if(!empty($_SESSION['flash_ok'])): ?>
        <div class="alert alert-success"><?= h($_SESSION['flash_ok']); unset($_SESSION['flash_ok']); ?></div>
      <?php endif; ?>
      <?php if(!empty($_SESSION['flash_err'])): ?>
        <div class="alert alert-danger"><?= h($_SESSION['flash_err']); unset($_SESSION['flash_err']); ?></div>
      <?php endif; ?>

      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">Alertas</h5>
          <?php if (can('alertas.crear_manual')): ?>
            <a class="btn btn-sm btn-primary" href="/admin/alertas/crear.php">+ Crear alerta</a>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <!-- filtros -->
          <form class="row g-2 mb-3" method="get">
            <div class="col-12 col-md-4">
              <input class="form-control" type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por texto, usuario, producto o #pedido…">
            </div>
            <div class="col-6 col-md-2">
              <select class="form-select" name="tipo">
                <option value="0">Todos los tipos</option>
                <?php foreach($tipos as $t): ?>
                  <option value="<?= (int)$t['id_tipo_alerta'] ?>" <?= $idTipo===(int)$t['id_tipo_alerta']?'selected':'' ?>>
                    <?= h($t['nombre']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 col-md-2">
              <select class="form-select" name="estado">
                <option value="">Todos</option>
                <option value="pendiente" <?= $estado==='pendiente'?'selected':'' ?>>Pendiente</option>
                <option value="resuelta"  <?= $estado==='resuelta'?'selected':''  ?>>Resuelta</option>
              </select>
            </div>
            <div class="col-6 col-md-2">
              <input class="form-control" type="date" name="desde" value="<?= h($desde) ?>">
            </div>
            <div class="col-6 col-md-2">
              <input class="form-control" type="date" name="hasta" value="<?= h($hasta) ?>">
            </div>
            <div class="col-12">
              <button class="btn btn-outline-secondary" type="submit">Filtrar</button>
            </div>
          </form>

          <!-- tabla -->
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead class="table-light">
                <tr>
                  <th>ID</th><th>Tipo</th><th>Descripción</th><th>Estado</th>
                  <th>Usuario</th><th>Producto</th><th>Pedido</th><th>Creada</th><th>Acciones</th>
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
                        <span class="badge bg-success-subtle text-success">Resuelta</span>
                      <?php else: ?>
                        <span class="badge bg-warning-subtle text-warning">Pendiente</span>
                      <?php endif; ?>
                    </td>
                    <td><?= h($r['usuario'] ?? '—') ?></td>
                    <td><?= h($r['producto'] ?? '—') ?></td>
                    <td><?= $r['id_pedido']?('#'.(int)$r['id_pedido']):'—' ?></td>
                    <td><?= h(date('d/m/Y H:i', strtotime($r['created_at']))) ?></td>
                    <td>
                      <a class="btn btn-sm btn-outline-primary" href="//admin/alertas/ver.php?id=<?= (int)$r['id_alerta'] ?>">Ver</a>
                      <?php if (can('alertas.resolver') && ($r['estado']??'')!=='resuelta'): ?>
                        <a class="btn btn-sm btn-success" href="/admin/alertas/resolver.php?id=<?= (int)$r['id_alerta'] ?>">Resolver</a>
                      <?php endif; ?>
                      <?php if (can('alertas.eliminar')): ?>
                        <a class="btn btn-sm btn-danger" href="/admin/alertas/eliminar.php?id=<?= (int)$r['id_alerta'] ?>">Eliminar</a>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if(empty($rows)): ?>
                  <tr><td colspan="9" class="text-muted">Sin resultados.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <!-- paginación -->
          <?php if($pages>1): ?>
            <nav>
              <ul class="pagination pagination-sm">
                <?php for($p=1;$p<=$pages;$p++): $qs=$_GET; $qs['page']=$p; $href='?'.http_build_query($qs); ?>
                  <li class="page-item <?= $p===$page?'active':'' ?>">
                    <a class="page-link" href="<?= h($href) ?>"><?= $p ?></a>
                  </li>
                <?php endfor; ?>
              </ul>
            </nav>
          <?php endif; ?>
        </div>
      </div>
    </main>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
