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
if ($id<=0) { header('Location: admin/categorias/'); exit; }

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

if (!$cat) { header('Location: admin/categorias/'); exit; }

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
      header('Location: admin/categorias/'); exit;
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Eliminar categoría — Los Lapicitos</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Estilos del panel -->
  <link href="/css/admin.css" rel="stylesheet">
</head>
<body>

<div class="container-fluid">
  <div class="row">
    <!-- Sidebar -->
    <aside class="col-12 col-md-3 col-lg-2 p-3 bg-light sidebar">
      <ul class="nav flex-column">
        <li class="nav-item"><a class="nav-link" href="/admin/index.php">Inicio</a></li>
        <li class="nav-item"><a class="nav-link" href="/admin/productos/">Productos</a></li>
        <li class="nav-item"><a class="nav-link active" href="/admin/categorias/">Categorías</a></li>
        <li class="nav-item"><a class="nav-link text-danger" href="/admin/logout.php">Salir</a></li>
      </ul>
    </aside>

    <!-- Main -->
    <main class="col-12 col-md-9 col-lg-10 p-4">
      <h4 class="mb-3">Eliminar categoría</h4>

      <?php if ($errors): ?>
        <div class="alert alert-danger">
          <?php foreach($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-body">
          <p class="mb-2">
            Vas a eliminar la categoría <strong><?= h($cat['nombre']) ?></strong>.
          </p>
          <ul class="mb-3">
            <li>Subcategorías asociadas: <strong><?= (int)$cat['subcats'] ?></strong></li>
            <li>Productos asociados: <strong><?= (int)$cat['productos'] ?></strong></li>
          </ul>

          <?php if ((int)$cat['subcats']>0 || (int)$cat['productos']>0): ?>
            <div class="alert alert-warning d-flex align-items-center" role="alert">
              <div>💡 Primero elimina o reasigna las subcategorías/productos.</div>
            </div>
            <a class="btn btn-outline-secondary" href="/libreria_lapicito/admin/categorias/">Volver</a>
          <?php else: ?>
            <form method="post" class="d-flex gap-2">
              <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
              <input type="hidden" name="id" value="<?= (int)$id ?>">
              <a class="btn btn-outline-secondary" href="/libreria_lapicito/admin/categorias/">Cancelar</a>
              <button class="btn btn-danger"
                      type="submit"
                      onclick="return confirm('¿Eliminar definitivamente?')">
                Eliminar
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </main>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

