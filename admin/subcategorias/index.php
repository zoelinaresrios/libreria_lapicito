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
require_perm('subcategorias.ver');

if (session_status()===PHP_SESSION_NONE) session_start();
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$q      = trim($_GET['q'] ?? '');
$idCat  = (int)($_GET['cat'] ?? 0);

$page= max(1,(int)($_GET['page']??1));
$perPage=15; $offset=($page-1)*$perPage;

// Catálogo de categorías (para filtro y combos)
$cats=[]; $r=$conexion->query("SELECT id_categoria, nombre FROM categoria ORDER BY nombre");
while($row=$r->fetch_assoc()) $cats[]=$row;

// WHERE
$w=[]; $params=[]; $types='';
if ($q!==''){ $w[]="sc.nombre LIKE ?"; $params[]="%$q%"; $types.='s'; }
if ($idCat>0){ $w[]="sc.id_categoria=?"; $params[]=$idCat; $types.='i'; }
$whereSql = $w?('WHERE '.implode(' AND ',$w)):'';

// Total para paginación
$sqlCount="
  SELECT COUNT(*) total
  FROM subcategoria sc
  $whereSql
";
$st=$conexion->prepare($sqlCount);
if($types) $st->bind_param($types, ...$params);
$st->execute();
$total=(int)($st->get_result()->fetch_assoc()['total']??0);
$st->close();
$pages=max(1,(int)ceil($total/$perPage));

// Lista con métricas (productos y stock acumulado)
$sqlList="
  SELECT
    sc.id_subcategoria,
    sc.nombre,
    sc.id_categoria,
    c.nombre AS categoria,
    COUNT(DISTINCT p.id_producto)       AS productos,
    COALESCE(SUM(i.stock_actual),0)     AS stock_total
  FROM subcategoria sc
  LEFT JOIN categoria c  ON c.id_categoria=sc.id_categoria
  LEFT JOIN producto p   ON p.id_subcategoria=sc.id_subcategoria
  LEFT JOIN inventario i ON i.id_producto=p.id_producto
  $whereSql
  GROUP BY sc.id_subcategoria, sc.nombre, sc.id_categoria, categoria
  ORDER BY categoria ASC, sc.nombre ASC
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
<meta charset="utf-8"><title>Subcategorías — Los Lapicitos</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/8.0.1/normalize.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/skeleton/2.0.4/skeleton.min.css">
<link rel="stylesheet" href="/libreria_lapicito/css/style.css">
</head><body>
<div class="barra"></div>
<div class="prod-shell">
  <aside class="prod-side">
    <ul class="prod-nav">
      <li><a href="/libreria_lapicito/admin/index.php">inicio</a></li>
      <li><a href="/libreria_lapicito/admin/categorias/">Categorías</a></li>
      <li><a class="active" href="/libreria_lapicito/admin/subcategorias/">Subcategorías</a></li>
      <li><a href="/libreria_lapicito/admin/logout.php">Salir</a></li>
    </ul>
  </aside>

  <main class="prod-main">
    <div class="inv-title">Panel administrativo — Subcategorías</div>

    <?php if(!empty($_SESSION['flash_ok'])): ?>
      <div class="alert-ok"><?= h($_SESSION['flash_ok']); unset($_SESSION['flash_ok']); ?></div>
    <?php endif; ?>
    <?php if(!empty($_SESSION['flash_err'])): ?>
      <div class="alert-error"><?= h($_SESSION['flash_err']); unset($_SESSION['flash_err']); ?></div>
    <?php endif; ?>

    <div class="prod-card">
      <div class="prod-head">
        <h5>Subcategorías</h5>
        <?php if (can('subcategorias.crear')): ?>
          <a class="btn-add" href="/libreria_lapicito/admin/subcategorias/crear.php<?= $idCat>0?'?cat='.((int)$idCat):'' ?>">+ Añadir Subcategoría</a>
        <?php endif; ?>
      </div>

      <form class="prod-filters" method="get">
        <input class="input-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar subcategoría…">
        <select name="cat">
          <option value="0">Todas las categorías</option>
          <?php foreach($cats as $c): ?>
            <option value="<?= (int)$c['id_categoria'] ?>" <?= $idCat===(int)$c['id_categoria']?'selected':'' ?>>
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
              <th>Subcategoría</th>
              <th style="width:220px">Categoría</th>
              <th style="width:140px">Productos</th>
              <th style="width:140px">Stock total</th>
              <th style="width:80px">editar</th>
              <th style="width:90px">eliminar</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($rows as $r): ?>
              <tr>
                <td>#<?= (int)$r['id_subcategoria'] ?></td>
                <td><?= h($r['nombre']) ?></td>
                <td><?= h($r['categoria'] ?? '—') ?></td>
                <td><?= (int)$r['productos'] ?></td>
                <td><?= (int)$r['stock_total'] ?></td>
                <td><a class="btn-sm" href="/libreria_lapicito/admin/subcategorias/editar.php?id=<?= (int)$r['id_subcategoria'] ?>">Editar</a></td>
                <td><a class="btn-sm" href="/libreria_lapicito/admin/subcategorias/eliminar.php?id=<?= (int)$r['id_subcategoria'] ?>">eliminar</a></td>
              </tr>
            <?php endforeach; ?>
            <?php if(empty($rows)): ?>
              <tr><td colspan="7" class="muted">Sin resultados.</td></tr>
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
