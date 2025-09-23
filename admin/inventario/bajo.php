<?php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';

$HAS_ACL = file_exists(__DIR__ . '/../includes/acl.php');
if ($HAS_ACL) { require_once __DIR__ . '/../includes/acl.php'; }
else { if (session_status()===PHP_SESSION_NONE) session_start();
  if (!function_exists('can')) { function can($k){ return true; } }
  if (!function_exists('require_perm')) { function require_perm($k){ return true; } }
}
require_perm('inventario.ver');

if (session_status()===PHP_SESSION_NONE) session_start();
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$id_cat = (int)($_GET['cat'] ?? 0);
$cats=[]; $rc=$conexion->query("SELECT id_categoria, nombre FROM categoria ORDER BY nombre");
while($row=$rc->fetch_assoc()) $cats[]=$row;

$w = " WHERE COALESCE(i.stock_actual,0) <= COALESCE(i.stock_minimo,0) ";
$params=[]; $types='';
if($id_cat>0){ $w .= " AND c.id_categoria=? "; $params[]=$id_cat; $types.='i'; }

$sql="
  SELECT p.id_producto, p.nombre, c.nombre AS categoria,
         COALESCE(i.stock_actual,0) AS stock_actual,
         COALESCE(i.stock_minimo,0) AS stock_minimo
  FROM producto p
  LEFT JOIN subcategoria sc ON sc.id_subcategoria=p.id_subcategoria
  LEFT JOIN categoria c ON c.id_categoria=sc.id_categoria
  LEFT JOIN inventario i ON i.id_producto=p.id_producto
  $w
  ORDER BY c.nombre, p.nombre
";
$st=$conexion->prepare($sql);
if($types){ $st->bind_param($types, ...$params); }
$st->execute(); $rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
?>
<!doctype html><html lang="es"><head>
<meta charset="utf-8"><title>Stock bajo — Inventario</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
 <link rel="stylesheet" href="/vendor/normalize.css?v=2">
<link rel="stylesheet" href="/vendor/skeleton.css?v=3">
<link rel="stylesheet" href="/css/style.css?v=13">
</head><body>
<div class="barra"></div>
<div class="prod-shell">
  <aside class="prod-side">
    <ul class="prod-nav">
      <li><a href="/admin/inventario/">Inventario</a></li>
      <li><a class="active" href="/admin/inventario/bajo.php">Bajo stock</a></li>
      <li><a href="/admin/inventario/minimos.php">Mínimos (lote)</a></li>
      <li><a href="/admin/inventario/exportar_csv.php">Exportar CSV</a></li>
      <li><a href="/admin/inventario/importar_csv.php">Importar CSV</a></li>
    </ul>
  </aside>

  <main class="prod-main">
    <div class="inv-title">Productos con stock bajo</div>

    <div class="prod-card">
      <form class="prod-filters" method="get">
        <select name="cat">
          <option value="0">Todas las categorías</option>
          <?php foreach($cats as $c): ?>
            <option value="<?= (int)$c['id_categoria'] ?>" <?= $id_cat===(int)$c['id_categoria']?'selected':'' ?>>
              <?= h($c['nombre']) ?>
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
              <th>Producto</th>
              <th style="width:220px">Categoría</th>
              <th style="width:120px">Stock</th>
              <th style="width:120px">Mínimo</th>
              <th style="width:90px">Ajustar</th>
              <th style="width:120px">Pedido</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($rows as $r): $faltan=max(0,(int)$r['stock_minimo']-(int)$r['stock_actual']); ?>
              <tr>
                <td>#<?= (int)$r['id_producto'] ?></td>
                <td><?= h($r['nombre']) ?></td>
                <td><?= h($r['categoria'] ?? '—') ?></td>
                <td><span class="badge no"><?= (int)$r['stock_actual'] ?></span></td>
                <td><?= (int)$r['stock_minimo'] ?></td>
                <td><a class="btn-sm" href="/admin/inventario/ajustar.php?id=<?= (int)$r['id_producto'] ?>">Ajustar</a></td>
                <td>
                  <a class="btn-sm" href="/admin/pedidos/crear.php?id_producto=<?= (int)$r['id_producto'] ?>&sugerido=<?= max(5,$faltan) ?>">
                    Borrador
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if(empty($rows)): ?>
              <tr><td colspan="7" class="muted">No hay productos en bajo stock.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</div>
</body></html>
