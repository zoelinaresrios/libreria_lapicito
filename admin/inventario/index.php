<?php
// /libreria_lapicito/admin/inventario/index.php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';

$HAS_ACL = file_exists(__DIR__ . '/../includes/acl.php');
if ($HAS_ACL) { require_once __DIR__ . '/../includes/acl.php'; }
else {
  if (session_status()===PHP_SESSION_NONE) session_start();
  if (!function_exists('can')) { function can($k){ return true; } }
  if (!function_exists('require_perm')) { function require_perm($k){ return true; } }
}
require_perm('inventario.ver');

if (session_status()===PHP_SESSION_NONE) session_start();
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Filtros
$q       = trim($_GET['q'] ?? '');
$id_cat  = (int)($_GET['cat'] ?? 0);
$stock_f = $_GET['stock'] ?? ''; // '', 'bajo', 'sin'

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

// Catálogo de categorías
$cats = [];
$rC = $conexion->query("SELECT id_categoria, nombre FROM categoria ORDER BY nombre");
while ($row = $rC->fetch_assoc()) $cats[] = $row;

// Base FROM
$baseFrom = "
  FROM producto p
  LEFT JOIN subcategoria sc ON sc.id_subcategoria = p.id_subcategoria
  LEFT JOIN categoria c     ON c.id_categoria     = sc.id_categoria
  LEFT JOIN inventario i    ON i.id_producto      = p.id_producto
";

// WHERE dinámico
$where = []; $params = []; $types = '';
if ($q!=='')      { $where[]="(p.nombre LIKE ? OR p.id_producto=?)"; $params[]="%$q%"; $params[]=(int)$q; $types.='si'; }
if ($id_cat>0)    { $where[]="c.id_categoria=?"; $params[]=$id_cat; $types.='i'; }
$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

// HAVING por estado de stock
$having = [];
if ($stock_f==='bajo') $having[] = "COALESCE(i.stock_actual,0) <= COALESCE(i.stock_minimo,0)";
if ($stock_f==='sin')  $having[] = "COALESCE(i.stock_actual,0) = 0";
$havingSql = $having ? ('HAVING '.implode(' AND ', $having)) : '';

// Conteo para paginar
$sqlCount = "
  SELECT COUNT(*) AS total
  FROM (
    SELECT p.id_producto
    $baseFrom
    $whereSql
    GROUP BY p.id_producto
    $havingSql
  ) t
";
$st = $conexion->prepare($sqlCount);
if ($types) $st->bind_param($types, ...$params);
$st->execute();
$total = (int)($st->get_result()->fetch_assoc()['total'] ?? 0);
$st->close();
$pages = max(1, (int)ceil($total / $perPage));

// Listado
$sqlList = "
  SELECT
    p.id_producto,
    p.nombre,
    c.nombre AS categoria,
    COALESCE(i.stock_actual,0) AS stock_actual,
    COALESCE(i.stock_minimo,0) AS stock_minimo
  $baseFrom
  $whereSql
  GROUP BY p.id_producto, p.nombre, categoria, i.stock_actual, i.stock_minimo
  $havingSql
  ORDER BY p.nombre ASC
  LIMIT ? OFFSET ?
";
$typesList  = $types.'ii';
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
  <title>Inventario — Los Lapicitos</title>
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
        <li><a href="/libreria_lapicito/admin/index.php">inicio</a></li>
        <?php if (can('ventas.rapidas')): ?><li><a href="/libreria_lapicito/admin/ventas/">Ventas</a></li><?php endif; ?>
        <li><a href="/libreria_lapicito/admin/productos/">Productos</a></li>
        <li><a href="/libreria_lapicito/admin/categorias/">Categorías</a></li>
        <li><a class="active" href="/libreria_lapicito/admin/inventario/">Inventario</a></li>
        <?php if (can('pedidos.aprobar')): ?><li><a href="/libreria_lapicito/admin/pedidos/">Pedidos</a></li><?php endif; ?>
        <?php if (can('alertas.ver')): ?><li><a href="/libreria_lapicito/admin/alertas/">Alertas</a></li><?php endif; ?>
        <?php if (can('reportes.detallados') || can('reportes.simple')): ?><li><a href="/libreria_lapicito/admin/reportes/">Reportes</a></li><?php endif; ?>
        <li><a href="/libreria_lapicito/admin/usuarios/">Usuarios</a></li>
        <?php if (can('usuarios.gestionar')): ?><li><a href="/libreria_lapicito/admin/roles/">Roles y permisos</a></li><?php endif; ?>
        <li><a href="/libreria_lapicito/admin/ajustes/">Ajustes</a></li>
        <li><a href="/libreria_lapicito/admin/logout.php">Salir</a></li>
      </ul>
    </aside>

    <main class="prod-main">
      <div class="inv-title">Panel administrativo — Inventario</div>

      <?php if (!empty($_SESSION['flash_ok'])): ?>
        <div class="alert-ok"><?= h($_SESSION['flash_ok']); unset($_SESSION['flash_ok']); ?></div>
      <?php endif; ?>
      <?php if (!empty($_SESSION['flash_err'])): ?>
        <div class="alert-error"><?= h($_SESSION['flash_err']); unset($_SESSION['flash_err']); ?></div>
      <?php endif; ?>

      <div class="prod-card">
        <div class="prod-head">
          <h5>Inventario</h5>
          <div class="btns-right">
            <a class="btn-sm" href="/libreria_lapicito/admin/inventario/bajo.php">Bajo stock</a>
            <a class="btn-sm" href="/libreria_lapicito/admin/inventario/minimos.php">Mínimos (lote)</a>
            <a class="btn-sm" href="/libreria_lapicito/admin/inventario/exportar_csv.php">Exportar CSV</a>
            <a class="btn-sm" href="/libreria_lapicito/admin/inventario/importar_csv.php">Importar CSV</a>
          </div>
        </div>

        <!-- Filtros -->
        <form class="prod-filters" method="get">
          <input class="input-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por nombre o ID…">
          <select name="cat">
            <option value="0">Todas las categorías</option>
            <?php foreach($cats as $c): ?>
              <option value="<?= (int)$c['id_categoria'] ?>" <?= $id_cat===(int)$c['id_categoria']?'selected':'' ?>>
                <?= h($c['nombre']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <select name="stock">
            <option value="">Stock: Todos</option>
            <option value="bajo" <?= $stock_f==='bajo'?'selected':'' ?>>Bajo (≤ mínimo)</option>
            <option value="sin"  <?= $stock_f==='sin'?'selected':''  ?>>Sin stock</option>
          </select>
          <button class="btn-filter" type="submit">Filtrar</button>
        </form>

        <!-- Tabla -->
        <div class="table-wrap">
          <table class="u-full-width">
            <thead>
              <tr>
                <th style="width:80px">ID</th>
                <th>Producto</th>
                <th style="width:220px">Categoría</th>
                <th style="width:120px">Stock</th>
                <th style="width:120px">Mínimo</th>
                <th style="width:90px">Ajustar</th>
                <th style="width:110px">Movimientos</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($rows as $r): ?>
                <tr>
                  <td>#<?= (int)$r['id_producto'] ?></td>
                  <td><?= h($r['nombre']) ?></td>
                  <td><?= h($r['categoria'] ?? '—') ?></td>
                  <td>
                    <?php
                      $st=(int)$r['stock_actual']; $min=(int)$r['stock_minimo'];
                      if ($st<=0)            echo '<span class="badge no">0</span>';
                      elseif ($st<=$min)     echo '<span class="badge no">'.$st.' (min '.$min.')</span>';
                      else                   echo '<span class="badge ok">'.$st.'</span>';
                    ?>
                  </td>
                  <td><?= (int)$r['stock_minimo'] ?></td>
                  <td>
                    <?php if (can('inventario.ajustar')): ?>
                      <a class="btn-sm" href="/libreria_lapicito/admin/inventario/ajustar.php?id=<?= (int)$r['id_producto'] ?>">Ajustar</a>
                    <?php endif; ?>
                  </td>
                  <td><a class="btn-sm" href="/libreria_lapicito/admin/inventario/movimientos.php?id=<?= (int)$r['id_producto'] ?>">Ver</a></td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($rows)): ?>
                <tr><td colspan="7" class="muted">Sin resultados.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Paginación -->
        <?php if ($pages>1): ?>
          <div class="prod-pager">
            <?php for($p=1;$p<=$pages;$p++):
              $qs=$_GET; $qs['page']=$p; $href='?'.http_build_query($qs); ?>
              <a class="<?= $p===$page ? 'on' : '' ?>" href="<?= h($href) ?>"><?= $p ?></a>
            <?php endfor; ?>
          </div>
        <?php endif; ?>
      </div>
    </main>
  </div>
</body>
</html>
