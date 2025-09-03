<?php
// /libreria_lapicito/admin/categorias/index.php

include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';

// ACL opcional (como en tu archivo de productos)
$HAS_ACL = file_exists(__DIR__ . '/../includes/acl.php');
if ($HAS_ACL) {
  require_once __DIR__ . '/../includes/acl.php';
} else {
  if (session_status()===PHP_SESSION_NONE) session_start();
  if (!function_exists('can')) { function can($k){ return true; } }
  if (!function_exists('require_perm')) { function require_perm($k){ return true; } }
}

if (function_exists('is_logged') && !is_logged()) {
  header('Location: /libreria_lapicito/admin/login.php'); exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Filtros
$q        = trim($_GET['q'] ?? '');
$has_prod = $_GET['has'] ?? ''; // '', 'con', 'sin'
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 15;
$offset   = ($page - 1) * $perPage;

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Base FROM con agregaciones para contar subcategorías, productos y stock total
$baseFrom = "
  FROM categoria c
  LEFT JOIN subcategoria sc          ON sc.id_categoria   = c.id_categoria
  LEFT JOIN producto p               ON p.id_subcategoria = sc.id_subcategoria
  LEFT JOIN inventario i             ON i.id_producto     = p.id_producto
";

// WHERE por búsqueda
$where = []; $params = []; $types = '';
if ($q !== '') { $where[] = "c.nombre LIKE ?"; $params[] = "%$q%"; $types .= 's'; }
$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

// HAVING por existencia de productos
$having = [];
if ($has_prod === 'con') { $having[] = "COUNT(DISTINCT p.id_producto) > 0"; }
if ($has_prod === 'sin') { $having[] = "COUNT(DISTINCT p.id_producto) = 0"; }
$havingSql = $having ? ('HAVING '.implode(' AND ', $having)) : '';

// Conteo total para paginar
$sqlCount = "
  SELECT COUNT(*) AS total
  FROM (
    SELECT c.id_categoria
    $baseFrom
    $whereSql
    GROUP BY c.id_categoria
    $havingSql
  ) t
";
$st = $conexion->prepare($sqlCount);
if ($types) { $st->bind_param($types, ...$params); }
$st->execute();
$total = (int)($st->get_result()->fetch_assoc()['total'] ?? 0);
$st->close();
$pages = max(1, (int)ceil($total / $perPage));

// Lista
$sqlList = "
  SELECT
    c.id_categoria,
    c.nombre,
    COUNT(DISTINCT sc.id_subcategoria)         AS subcategorias,
    COUNT(DISTINCT p.id_producto)              AS productos,
    COALESCE(SUM(i.stock_actual),0)            AS stock_total
  $baseFrom
  $whereSql
  GROUP BY c.id_categoria, c.nombre
  $havingSql
  ORDER BY c.nombre ASC
  LIMIT ? OFFSET ?
";
$typesList = $types . 'ii';
$paramsList = $params; $paramsList[] = $perPage; $paramsList[] = $offset;

$st = $conexion->prepare($sqlList);
$st->bind_param($typesList, ...$paramsList);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Categorías — Los Lapicitos</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/8.0.1/normalize.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/skeleton/2.0.4/skeleton.min.css">
  <link rel="stylesheet" href="/libreria_lapicito/css/style.css">
</head>
<body>
  <div class="barra"></div>

  <div class="prod-shell">
    <aside class="prod-side">
      <ul class="prod-nav">
        <li><a  href="/libreria_lapicito/admin/index.php">inicio</a></li>
       
        <?php if (can('productos.ver')): ?>
        <li><a href="/libreria_lapicito/admin/productos/">Productos</a></li>
        <?php endif; ?>
        <li><a class="active" href="/libreria_lapicito/admin/categorias/">categorias</a></li>
        <?php if (can('inventario.ver')): ?>
           <li><a href="/libreria_lapicito/admin/subcategorias/">subcategorias</a></li>
        <li><a href="/libreria_lapicito/admin/inventario/">Inventario</a></li>
        <?php endif; ?>
        <?php if (can('pedidos.aprobar')): ?>
        <li><a href="/libreria_lapicito/admin/pedidos/">Pedidos</a></li>
        <?php endif; ?>
        <?php if (can('alertas.ver')): ?>
        <li><a href="/libreria_lapicito/admin/alertas/">Alertas</a></li>
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
      <div class="inv-title">Panel administrativo — Categorías</div>

      <div class="prod-card">
        <div class="prod-head">
          <h5>Categorías</h5>
          <?php if (can('categorias.crear')): ?>
            <a class="btn-add" href="/libreria_lapicito/admin/categorias/crear.php">+ Añadir Categoría</a>
          <?php endif; ?>
        </div>

        <!-- Filtros -->
        <form class="prod-filters" method="get">
          <input class="input-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar categoría…">
          <select name="has" title="Filtrar por productos">
            <option value=""   <?= $has_prod===''    ? 'selected' : '' ?>>Todas</option>
            <option value="con"<?= $has_prod==='con'? 'selected' : '' ?>>Con productos</option>
            <option value="sin"<?= $has_prod==='sin'? 'selected' : '' ?>>Sin productos</option>
          </select>
          <button class="btn-filter" type="submit">Filtrar</button>
        </form>

        <!-- Tabla -->
        <div class="table-wrap">
          <table class="u-full-width">
            <thead>
              <tr>
                <th style="width:80px">ID</th>
                <th>Nombre</th>
                <th style="width:160px">Subcategorías</th>
                <th style="width:160px">Productos</th>
                <th style="width:140px">Stock total</th>
                <th style="width:80px">editar</th>
                <th style="width:90px">eliminar</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($rows as $r): ?>
                <tr>
                  <td>#<?= (int)$r['id_categoria'] ?></td>
                  <td><?= h($r['nombre']) ?></td>
                  <td><?= (int)$r['subcategorias'] ?></td>
                  <td><?= (int)$r['productos'] ?></td>
                  <td>
                    <?php $st = (int)$r['stock_total'];
                      if ($st<=0) echo '<span class="badge no">0</span>';
                      else         echo '<span class="badge ok">'.$st.'</span>';
                    ?>
                  </td>
                  <td>
                    <a class="btn-sm" href="/libreria_lapicito/admin/categorias/editar.php?id=<?= (int)$r['id_categoria'] ?>">Editar</a>
                  </td>
                  <td>
                    <a class="btn-sm" href="/libreria_lapicito/admin/categorias/eliminar.php?id=<?= (int)$r['id_categoria'] ?>">eliminar</a>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($rows)): ?>
                <tr><td colspan="7" class="muted">Sin resultados.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Paginación -->
        <?php if ($pages > 1): ?>
          <div class="prod-pager">
            <?php for($p=1;$p<=$pages;$p++):
              $qs = $_GET; $qs['page']=$p; $href='?'.http_build_query($qs); ?>
              <a class="<?= $p===$page ? 'on' : '' ?>" href="<?= h($href) ?>"><?= $p ?></a>
            <?php endfor; ?>
          </div>
        <?php endif; ?>
      </div>
    </main>
  </div>
</body>
</html>
