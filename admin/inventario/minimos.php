<?php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';

$HAS_ACL = file_exists(__DIR__ . '/../includes/acl.php');
if ($HAS_ACL) { require_once __DIR__ . '/../includes/acl.php'; }
else { if (session_status()===PHP_SESSION_NONE) session_start();
  if (!function_exists('can')) { function can($k){ return true; } }
  if (!function_exists('require_perm')) { function require_perm($k){ return true; } }
}
require_perm('inventario.ajustar');

if (session_status()===PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$q = trim($_GET['q'] ?? '');
$id_cat = (int)($_GET['cat'] ?? 0);
$page=max(1,(int)($_GET['page']??1)); $perPage=25; $offset=($page-1)*$perPage;

// Catálogo de categorías
$cats=[]; $rc=$conexion->query("SELECT id_categoria, nombre FROM categoria ORDER BY nombre");
while($row=$rc->fetch_assoc()) $cats[]=$row;

// Aplica cambios 
$errors=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) $errors[]='Token inválido.';
  if(empty($errors) && !empty($_POST['min']) && is_array($_POST['min'])){
    $conexion->begin_transaction();
    try{
      foreach($_POST['min'] as $idp=>$val){
        if(!ctype_digit((string)$idp)) continue;
        if($val==='' || !ctype_digit((string)$val) || (int)$val<0) continue;
        $idp=(int)$idp; $nm=(int)$val;

        // asegurar fila inventario
        $conexion->query("INSERT IGNORE INTO inventario(id_producto,stock_actual,stock_minimo) VALUES ($idp,0,0)");

        $st=$conexion->prepare("UPDATE inventario SET stock_minimo=? WHERE id_producto=?");
        $st->bind_param('ii',$nm,$idp);
        $st->execute(); $st->close();
      }
      $conexion->commit();
      $_SESSION['flash_ok']='Mínimos actualizados.';
      header('Location: '.$_SERVER['REQUEST_URI']); exit;
    }catch(Exception $e){
      $conexion->rollback();
      $errors[]='No se pudo guardar: '.$e->getMessage();
    }
  }
}

// WHERE
$w=[]; $params=[]; $types='';
if($q!==''){ $w[]="(p.nombre LIKE ? OR p.id_producto=?)"; $params[]="%$q%"; $params[]=(int)$q; $types.='si'; }
if($id_cat>0){ $w[]="c.id_categoria=?"; $params[]=$id_cat; $types.='i'; }
$whereSql=$w?('WHERE '.implode(' AND ',$w)):'';

// Conteo
$sqlC="
  SELECT COUNT(*) total
  FROM producto p
  LEFT JOIN subcategoria sc ON sc.id_subcategoria=p.id_subcategoria
  LEFT JOIN categoria c ON c.id_categoria=sc.id_categoria
  $whereSql
";
$st=$conexion->prepare($sqlC); if($types) $st->bind_param($types,...$params);
$st->execute(); $total=(int)($st->get_result()->fetch_assoc()['total']??0); $st->close();
$pages=max(1,(int)ceil($total/$perPage));

// Lista
$sql="
  SELECT p.id_producto, p.nombre, c.nombre AS categoria,
         COALESCE(i.stock_actual,0) AS stock_actual,
         COALESCE(i.stock_minimo,0) AS stock_minimo
  FROM producto p
  LEFT JOIN subcategoria sc ON sc.id_subcategoria=p.id_subcategoria
  LEFT JOIN categoria c ON c.id_categoria=sc.id_categoria
  LEFT JOIN inventario i ON i.id_producto=p.id_producto
  $whereSql
  ORDER BY c.nombre, p.nombre
  LIMIT ? OFFSET ?
";
$typesList=$types.'ii'; $paramsList=$params; $paramsList[]=$perPage; $paramsList[]=$offset;
$st=$conexion->prepare($sql); $st->bind_param($typesList, ...$paramsList);
$st->execute(); $rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
?>
<!doctype html><html lang="es"><head>
<meta charset="utf-8"><title>Mínimos (lote) — Inventario</title>
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
      <li><a href="/admin/inventario/bajo.php">Bajo stock</a></li>
      <li><a class="active" href="/admin/inventario/minimos.php">Mínimos (lote)</a></li>
    </ul>
  </aside>

  <main class="prod-main">
    <div class="inv-title">Editar mínimos en lote</div>

    <?php if(!empty($_SESSION['flash_ok'])): ?>
      <div class="alert-ok"><?= h($_SESSION['flash_ok']); unset($_SESSION['flash_ok']); ?></div>
    <?php endif; ?>
    <?php if($errors): ?><div class="alert-error"><?php foreach($errors as $e) echo '<div>'.h($e).'</div>'; ?></div><?php endif; ?>

    <div class="prod-card">
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
        <button class="btn-filter" type="submit">Filtrar</button>
      </form>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <div class="table-wrap">
          <table class="u-full-width">
            <thead>
              <tr>
                <th style="width:80px">ID</th>
                <th>Producto</th>
                <th style="width:220px">Categoría</th>
                <th style="width:120px">Stock</th>
                <th style="width:140px">Mínimo</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($rows as $r): ?>
                <tr>
                  <td>#<?= (int)$r['id_producto'] ?></td>
                  <td><?= h($r['nombre']) ?></td>
                  <td><?= h($r['categoria'] ?? '—') ?></td>
                  <td><?= (int)$r['stock_actual'] ?></td>
                  <td>
                    <input type="number" name="min[<?= (int)$r['id_producto'] ?>]" value="<?= (int)$r['stock_minimo'] ?>" min="0" style="width:110px">
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if(empty($rows)): ?>
                <tr><td colspan="5" class="muted">Sin resultados.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="form-actions">
          <button class="btn-filter" type="submit">Guardar mínimos</button>
          <a class="btn-sm btn-muted" href="/admin/inventario/exportar_csv.php">Exportar CSV</a>
          <a class="btn-sm" href="/admin/inventario/importar_csv.php">Importar CSV</a>
        </div>
      </form>

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
</body></html>
