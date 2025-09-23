<?php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';
$HAS_ACL = file_exists(__DIR__ . '/../includes/acl.php');
if ($HAS_ACL) {
  require_once __DIR__ . '/../includes/acl.php';
} else {
  if (session_status()===PHP_SESSION_NONE) session_start();
  if (!function_exists('can')) { function can($k){ return true; } }
  if (!function_exists('require_perm')) { function require_perm($k){ return true; } }
}

if (function_exists('is_logged') && !is_logged()) {
  header('Location: /admin/login.php'); exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (session_status()===PHP_SESSION_NONE) session_start();

// CSRF para acciones POST
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

$q       = trim($_GET['q'] ?? '');
$id_cat  = (int)($_GET['cat'] ?? 0);
$stock_f = $_GET['stock'] ?? '';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

$cats = [];
$rC = $conexion->query("SELECT id_categoria, nombre FROM categoria ORDER BY nombre");
while ($row = $rC->fetch_assoc()) { $cats[] = $row; }

$baseFrom = "
  FROM producto p
  LEFT JOIN subcategoria sc ON sc.id_subcategoria = p.id_subcategoria
  LEFT JOIN categoria c     ON c.id_categoria     = sc.id_categoria
  LEFT JOIN inventario i    ON i.id_producto      = p.id_producto
";

$where = []; $params = []; $types = '';
if ($q !== '')     { $where[]="p.nombre LIKE ?";   $params[]="%$q%"; $types .= 's'; }
if ($id_cat > 0)   { $where[]="c.id_categoria=?";  $params[]=$id_cat; $types .= 'i'; }
$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

$having = [];
if ($stock_f === 'bajo') { $having[] = "COALESCE(SUM(i.stock_actual),0) <= COALESCE(MIN(i.stock_minimo),0)"; }
if ($stock_f === 'sin')  { $having[] = "COALESCE(SUM(i.stock_actual),0) = 0"; }
$havingSql = $having ? ('HAVING '.implode(' AND ', $having)) : '';

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
if ($types) { $st->bind_param($types, ...$params); }
$st->execute();
$total = (int)($st->get_result()->fetch_assoc()['total'] ?? 0);
$st->close();
$pages = max(1, (int)ceil($total / $perPage));

$sqlList = "
  SELECT
    p.id_producto,
    p.nombre,
    p.activo,                           
    c.nombre AS categoria,
    COALESCE(SUM(i.stock_actual),0) AS stock_total,
    COALESCE(MIN(i.stock_minimo),0) AS stock_min,
    p.precio_venta
  $baseFrom
  $whereSql
  GROUP BY p.id_producto, p.nombre, p.activo, categoria, p.precio_venta
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

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Productos — Los Lapicitos</title>
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
        <li><a  href="/admin/index.php">inicio</a></li>
       
        <?php if (can('productos.ver')): ?>
        <li><a class="active" href="/admin/productos/">Productos</a></li>
        <?php endif; ?>
        <li><a href="/admin/categorias/">categorias</a></li>
        <?php if (can('inventario.ver')): ?>
           <li><a href="/admin/subcategorias/">subcategorias</a></li>
        <li><a href="/admin/inventario/">Inventario</a></li>
        <?php endif; ?>
        <?php if (can('pedidos.aprobar')): ?>
        <li><a href="/admin/pedidos/">Pedidos</a></li>
        <?php endif; ?>
        <?php if (can('alertas.ver')): ?>
        <li><a href="/admin/alertas/">Alertas</a></li>
        <?php endif; ?>
        <?php if (can('reportes.detallados') || can('reportes.simple')): ?>
        <li><a href="/admin/reportes/">Reportes</a></li>
        <?php endif; ?>
         <?php if (can('ventas.rapidas')): ?>
        <li><a href="/admin/ventas/">Ventas</a></li>
        <?php endif; ?>
        <?php if (can('usuarios.gestionar') || can('usuarios.crear_empleado')): ?>
        <li><a href="/admin/usuarios/">Usuarios</a></li>
        <?php endif; ?>
        <?php if (can('usuarios.gestionar')): ?>
        <li><a href="/admin/roles/">Roles y permisos</a></li>
        <?php endif; ?>
        <li><a href="/admin/ajustes/">Ajustes</a></li>
        <li><a href="/admin/logout.php">Salir</a></li>
      </ul>
    </aside>

    <main class="prod-main">
      <div class="inv-title">Panel administrativo- Productos</div>

      <div class="prod-card">
        <div class="prod-head">
          <h5>Productos</h5>
          <a class="btn-add" href="/admin/productos/crear.php">+ Añadir Producto</a>
        </div>

        <form class="prod-filters" method="get">
          <input class="input-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar…">
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

        <div class="table-wrap">
          <table class="u-full-width">
            <thead>
              <tr>
                <th style="width:80px">ID</th>
                <th>Nombre</th>
                <th style="width:220px">Categoría</th>
                <th style="width:120px">Stock</th>
                <th style="width:120px">Precio</th>
                <th style="width:120px">Estado</th>    
                <th style="width:80px">Editar</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($rows as $r): ?>
                <?php
                  $stT = (int)$r['stock_total'];
                  $min = (int)$r['stock_min'];
                  $isOn = (int)($r['activo'] ?? 0) === 1;
                  $next = $isOn ? 0 : 1;
                ?>
                <tr>
                  <td>#<?= (int)$r['id_producto'] ?></td>
                  <td><?= h($r['nombre']) ?></td>
                  <td><?= h($r['categoria'] ?? '—') ?></td>
                  <td>
                    <?php
                      if ($stT <= 0) {
                        echo '<span class="badge no">0</span>';
                      } elseif ($stT <= $min) {
                        echo '<span class="badge no">'.$stT.' (min '.$min.')</span>';
                      } else {
                        echo '<span class="badge ok">'.$stT.'</span>';
                      }
                    ?>
                  </td>
                  <td>$ <?= number_format((float)($r['precio_venta'] ?? 0), 2, ',', '.') ?></td>
                  <td>
                    <?php if (can('productos.activar')): ?>
                      <form class="prod-actions" method="post" action="/admin/productos/toggle.php" onsubmit="return confirmToggle(this);">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="id" value="<?= (int)$r['id_producto'] ?>">
                        <input type="hidden" name="to" value="<?= (int)$next ?>">
                        <button type="submit"
                                class="btn-state <?= $isOn ? 'on':'off' ?>"
                                data-next="<?= (int)$next ?>"
                                data-name="<?= h($r['nombre']) ?>">
                          <?= $isOn ? 'Activo' : 'Desactivado' ?>
                        </button>
                      </form>
                    <?php else: ?>
                      <span class="badge <?= $isOn ? 'ok':'no' ?>"><?= $isOn ? 'Activo' : 'Desactivado' ?></span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <a class="btn-sm" href="/admin/editar.php?id=<?= (int)$r['id_producto'] ?>">Editar</a>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($rows)): ?>
                <tr><td colspan="7" class="muted">Sin resultados.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <?php if ($pages > 1): ?>
          <div class="prod-pager">
            <?php for($p=1; $p<=$pages; $p++):
              $qs = $_GET; $qs['page'] = $p; $href = '?'.http_build_query($qs); ?>
              <a class="<?= $p===$page ? 'on' : '' ?>" href="<?= h($href) ?>"><?= $p ?></a>
            <?php endfor; ?>
          </div>
        <?php endif; ?>
      </div>
    </main>
  </div>

  <script>
    function confirmToggle(form){
      var btn   = form.querySelector('.btn-state');
      var next  = btn.getAttribute('data-next'); // "1" = activar, "0" = desactivar
      var name  = btn.getAttribute('data-name') || 'este producto';
      var msg   = next === '1'
        ? ('¿Confirmás ACTIVAR «'+name+'»?')
        : ('¿Confirmás DESACTIVAR «'+name+'»?');
      return confirm(msg);
    }
  </script>
</body>
</html>
