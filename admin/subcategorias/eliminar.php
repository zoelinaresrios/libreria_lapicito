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
require_perm('subcategorias.eliminar');

if (session_status()===PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
if($id<=0){ header('Location: /admin/subcategorias/'); exit; }

// Datos + conteo de productos
$st=$conexion->prepare("
  SELECT sc.id_subcategoria, sc.nombre, sc.id_categoria, c.nombre AS categoria,
         COUNT(p.id_producto) AS productos
  FROM subcategoria sc
  LEFT JOIN categoria c ON c.id_categoria=sc.id_categoria
  LEFT JOIN producto p  ON p.id_subcategoria=sc.id_subcategoria
  WHERE sc.id_subcategoria=?
");
$st->bind_param('i',$id); $st->execute();
$sc=$st->get_result()->fetch_assoc(); $st->close();
if(!$sc){ header('Location: /admin/subcategorias/'); exit; }

$errors=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) $errors[]='Token inválido.';
  if((int)$sc['productos']>0) $errors[]='No se puede eliminar: hay productos asociados. Reasignalos o elimínalos primero.';

  if(!$errors){
    $st=$conexion->prepare("DELETE FROM subcategoria WHERE id_subcategoria=?");
    $st->bind_param('i',$id); $st->execute(); $st->close();
    $_SESSION['flash_ok']='Subcategoría eliminada.';
    header('Location: /admin/subcategorias/?cat='.$sc['id_categoria']); exit;
  }
}
?>
<!doctype html><html lang="es"><head>
<meta charset="utf-8"><title>Eliminar subcategoría — Los Lapicitos</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/vendor/normalize.css?v=2">
<link rel="stylesheet" href="/vendor/skeleton.css?v=3">
<link rel="stylesheet" href="/css/style.css?v=13">

</head><body>
<div class="barra"></div>
<div class="prod-shell">
  <aside class="prod-side">
    <ul class="prod-nav">
      <li><a href="/admin/subcategorias/">Subcategorías</a></li>
    </ul>
  </aside>

  <main class="prod-main">
    <div class="inv-title">Eliminar subcategoría</div>
    <div class="prod-card">
      <?php if($errors): ?><div class="alert-error"><?php foreach($errors as $e) echo '<div>'.h($e).'</div>'; ?></div><?php endif; ?>

      <p>Vas a eliminar la subcategoría <strong><?= h($sc['nombre']) ?></strong> de <strong><?= h($sc['categoria'] ?? '—') ?></strong>.</p>
      <ul><li>Productos asociados: <strong><?= (int)$sc['productos'] ?></strong></li></ul>

      <?php if((int)$sc['productos']>0): ?>
        <p class="muted">No se puede eliminar mientras existan productos asociados.</p>
        <a class="btn-sm btn-muted" href="/admin/subcategorias/?cat=<?= (int)$sc['id_categoria'] ?>">Volver</a>
      <?php else: ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
          <input type="hidden" name="id" value="<?= (int)$id ?>">
          <a class="btn-sm btn-muted" href="/admin/subcategorias/?cat=<?= (int)$sc['id_categoria'] ?>">Cancelar</a>
          <button class="btn-danger" type="submit" onclick="return confirm('¿Eliminar definitivamente?')">Eliminar</button>
        </form>
      <?php endif; ?>
    </div>
  </main>
</div>
</body></html>
