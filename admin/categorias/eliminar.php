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

require_perm('categorias.eliminar');

if (session_status()===PHP_SESSION_NONE) session_start();
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }

$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
if ($id<=0) { header('Location: /libreria_lapicito/admin/categorias/'); exit; }


$sql = "
  SELECT
    c.id_categoria, c.nombre,
    COUNT(DISTINCT sc.id_subcategoria) AS subcats,
    COUNT(DISTINCT p.id_producto)      AS productos
  FROM categoria c
  LEFT JOIN subcategoria sc ON sc.id_categoria = c.id_categoria
  LEFT JOIN producto p ON p.id_subcategoria = sc.id_subcategoria
  WHERE c.id_categoria = ?
";
$st = $conexion->prepare($sql);
$st->bind_param('i', $id);
$st->execute();
$cat = $st->get_result()->fetch_assoc();
$st->close();

if (!$cat) { header('Location: /libreria_lapicito/admin/categorias/'); exit; }

$errors = [];
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    $errors[] = 'Token inválido.';
  }
  if (!$errors) {
    if ((int)$cat['subcats']>0 || (int)$cat['productos']>0) {
      $errors[] = 'No se puede eliminar: la categoría tiene subcategorías o productos asociados.';
    } else {
      $st = $conexion->prepare("DELETE FROM categoria WHERE id_categoria=?");
      $st->bind_param('i', $id);
      $st->execute();
      $st->close();
      $_SESSION['flash_ok'] = 'Categoría eliminada.';
      header('Location: /libreria_lapicito/admin/categorias/'); exit;
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Eliminar categoría — Los Lapicitos</title>
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
        <li><a href="/libreria_lapicito/admin/productos/">Productos</a></li>
        <li><a class="active" href="/libreria_lapicito/admin/categorias/">Categorías</a></li>
        <li><a href="/libreria_lapicito/admin/logout.php">Salir</a></li>
      </ul>
    </aside>

    <main class="prod-main">
      <div class="inv-title">Eliminar categoría</div>
      <div class="prod-card">
        <?php if ($errors): ?>
          <div class="alert-error">
            <?php foreach($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
          </div>
        <?php endif; ?>

        <p>Vas a eliminar la categoría <strong><?= h($cat['nombre']) ?></strong>.</p>
        <ul>
          <li>Subcategorías asociadas: <strong><?= (int)$cat['subcats'] ?></strong></li>
          <li>Productos asociados: <strong><?= (int)$cat['productos'] ?></strong></li>
        </ul>

        <?php if ((int)$cat['subcats']>0 || (int)$cat['productos']>0): ?>
          <p class="muted">💡 Primero elimina o reasigna las subcategorías/productos.</p>
          <a class="btn-sm btn-muted" href="/libreria_lapicito/admin/categorias/">Volver</a>
        <?php else: ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
            <input type="hidden" name="id" value="<?= (int)$id ?>">
            <a class="btn-sm btn-muted" href="/libreria_lapicito/admin/categorias/">Cancelar</a>
            <button class="btn-danger" type="submit" onclick="return confirm('¿Eliminar definitivamente?')">Eliminar</button>
          </form>
        <?php endif; ?>
      </div>
    </main>
  </div>
</body>
</html>
