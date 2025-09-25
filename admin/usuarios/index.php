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
require_perm('usuarios.gestionar');

if (session_status()===PHP_SESSION_NONE) session_start();
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Filtros
$q     = trim($_GET['q'] ?? '');
$idRol = (int)($_GET['rol'] ?? 0);
$idEst = (int)($_GET['estado'] ?? 0);

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

// Catálogos
$roles = [];
$r = $conexion->query("SELECT id_rol, nombre_rol FROM rol ORDER BY nombre_rol");
while ($row=$r->fetch_assoc()) $roles[]=$row;

$estados = [];
$r = $conexion->query("SELECT id_estado_usuario, nombre_estado FROM estado_usuario ORDER BY nombre_estado");
while ($row=$r->fetch_assoc()) $estados[]=$row;

$w=[]; $params=[]; $types='';
if ($q!==''){ $w[]="(u.nombre LIKE ? OR u.email LIKE ?)"; $params[]="%$q%"; $params[]="%$q%"; $types.='ss'; }
if ($idRol>0){ $w[]="u.id_rol=?"; $params[]=$idRol; $types.='i'; }
if ($idEst>0){ $w[]="u.id_estado_usuario=?"; $params[]=$idEst; $types.='i'; }
$whereSql = $w ? ('WHERE '.implode(' AND ', $w)) : '';

$sqlCount = "
  SELECT COUNT(*) AS total
  FROM usuario u
  $whereSql
";
$st=$conexion->prepare($sqlCount);
if($types) $st->bind_param($types, ...$params);
$st->execute();
$total = (int)($st->get_result()->fetch_assoc()['total'] ?? 0);
$st->close();
$pages = max(1, (int)ceil($total/$perPage));


$sqlList = "
  SELECT u.id_usuario, u.nombre, u.email, u.creado_en, u.actualizado_en,
         r.nombre_rol, e.nombre_estado
  FROM usuario u
  LEFT JOIN rol r ON r.id_rol = u.id_rol
  LEFT JOIN estado_usuario e ON e.id_estado_usuario = u.id_estado_usuario
  $whereSql
  ORDER BY u.nombre ASC
  LIMIT ? OFFSET ?
";
$typesList = $types.'ii';
$paramsList = $params; $paramsList[]=$perPage; $paramsList[]=$offset;

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
  <title>Usuarios — Los Lapicitos</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
 <link rel="stylesheet" href="/vendor/normalize.css?v=2">
<link rel="stylesheet" href="/vendor/skeleton.css?v=3">
<link rel="stylesheet" href="/css/style.css?v=13">
</head>
<body>
  <div class="barra"></div>

  <div class="prod-shell">
    <aside class="prod-side">
      <ul class="prod-nav">
        <li><a href="/admin/index.php">inicio</a></li>
       
        <li><a href="/admin/productos/">Productos</a></li>
        <li><a href="/admin/categorias/">categorias</a></li>
       <li><a  href="/admin/subcategorias/">subcategorias</a></li>
        <li><a href="/admin/inventario/">Inventario</a></li>
        <li><a href="/admin/pedidos/">Pedidos</a></li>
        <li><a href="/admin/proveedores/">Proveedores</a></li>
          <li><a href="/admin/sucursales/">sucursales</a></li>
        <li><a href="/admin/alertas/">Alertas</a></li>
        <li><a href="/admin/reportes/">Reportes y estadisticas</a></li>
        <li><a href="/admin/ventas/">Ventas</a></li>
        <li><a class="active"  href="/admin/usuarios/">Usuarios</a></li>
        <li><a href="/admin/roles/">Roles y permisos</a></li>
        <li><a href="/admin/ajustes/">Ajustes</a></li>
         <li><a href="/admin/ajustes/">Audutorias</a></li>
        <li><a href="/admin/logout.php">Salir</a></li>
      </ul>
    </aside>


    <main class="prod-main">
      <div class="inv-title">Panel administrativo — Usuarios</div>

      <?php if (!empty($_SESSION['flash_ok'])): ?>
        <div class="alert-ok"><?= h($_SESSION['flash_ok']); unset($_SESSION['flash_ok']); ?></div>
      <?php endif; ?>
      <?php if (!empty($_SESSION['flash_err'])): ?>
        <div class="alert-error"><?= h($_SESSION['flash_err']); unset($_SESSION['flash_err']); ?></div>
      <?php endif; ?>

      <div class="prod-card">
        <div class="prod-head">
          <h5>Usuarios</h5>
          <?php if (can('usuarios.crear_empleado')): ?>
            <a class="btn-add" href="/admin/usuarios/crear.php">+ Añadir Usuario</a>
          <?php endif; ?>
        </div>

        <form class="prod-filters" method="get">
          <input class="input-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por nombre o email…">
          <select name="rol">
            <option value="0">Rol: Todos</option>
            <?php foreach($roles as $r): ?>
              <option value="<?= (int)$r['id_rol'] ?>" <?= $idRol===(int)$r['id_rol']?'selected':'' ?>>
                <?= h($r['nombre_rol']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <select name="estado">
            <option value="0">Estado: Todos</option>
            <?php foreach($estados as $e): ?>
              <option value="<?= (int)$e['id_estado_usuario'] ?>" <?= $idEst===(int)$e['id_estado_usuario']?'selected':'' ?>>
                <?= h($e['nombre_estado']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button class="btn-filter" type="submit">Filtrar</button>
        </form>

        <div class="table-wrap">
          <table class="u-full-width">
            <thead>
              <tr>
                <th style="width:80px">ID</th>
                <th>Nombre</th>
                <th>Email</th>
                <th style="width:160px">Rol</th>
                <th style="width:140px">Estado</th>
                <th style="width:120px">Creado</th>
                <th style="width:80px">Editar</th>
                <th style="width:90px">Eliminar</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($rows as $u): ?>
                <tr>
                  <td>#<?= (int)$u['id_usuario'] ?></td>
                  <td><?= h($u['nombre']) ?></td>
                  <td><?= h($u['email']) ?></td>
                  <td><?= h($u['nombre_rol'] ?? '—') ?></td>
                  <td><?= h($u['nombre_estado'] ?? '—') ?></td>
                  <td><?= h($u['creado_en']) ?></td>
                  <td><a class="btn-sm" href="/admin/usuarios/editar.php?id=<?= (int)$u['id_usuario'] ?>">Editar</a></td>
                  <td><a class="btn-sm" href="/admin/usuarios/eliminar.php?id=<?= (int)$u['id_usuario'] ?>">eliminar</a></td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($rows)): ?>
                <tr><td colspan="8" class="muted">Sin resultados.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <?php if ($pages>1): ?>
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
</body>
</html>
