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

require_perm('categorias.editar');

if (session_status()===PHP_SESSION_NONE) session_start();
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }

$id = (int)($_GET['id'] ?? 0);
if ($id<=0) { header('Location: /admin/categorias/'); exit; }

$st = $conexion->prepare("SELECT id_categoria, nombre FROM categoria WHERE id_categoria=?");
$st->bind_param('i', $id);
$st->execute();
$cat = $st->get_result()->fetch_assoc();
$st->close();
if (!$cat) { header('Location: /admin/categorias/'); exit; }

$errors = [];
$nombre = trim($_POST['nombre'] ?? $cat['nombre']);

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    $errors[] = 'Token inválido.';
  }
  if ($nombre==='')               $errors[] = 'El nombre es obligatorio.';
  if (mb_strlen($nombre) < 3)     $errors[] = 'El nombre debe tener al menos 3 caracteres.';
  if (mb_strlen($nombre) > 120)   $errors[] = 'Máximo 120 caracteres.';

  if (!$errors) {
    $st = $conexion->prepare("SELECT 1 FROM categoria WHERE nombre=? AND id_categoria<>? LIMIT 1");
    $st->bind_param('si', $nombre, $id);
    $st->execute();
    if ($st->get_result()->fetch_row()) $errors[] = 'Ya existe otra categoría con ese nombre.';
    $st->close();
  }

  if (!$errors) {
    $st = $conexion->prepare("UPDATE categoria SET nombre=? WHERE id_categoria=?");
    $st->bind_param('si', $nombre, $id);
    $st->execute();
    $st->close();
    $_SESSION['flash_ok'] = 'Categoría actualizada.';
    header('Location: /admin/categorias/'); exit;
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Editar categoría — Los Lapicitos</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <link href="/css/admin.css" rel="stylesheet">
</head>
<body>

<div class="container-fluid">
  <div class="row">
    
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
      <h4 class="mb-3">Editar categoría</h4>

      <?php if ($errors): ?>
        <div class="alert alert-danger">
          <?php foreach($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if(!empty($_SESSION['flash_ok'])): ?>
        <div class="alert alert-success"><?= h($_SESSION['flash_ok']); unset($_SESSION['flash_ok']); ?></div>
      <?php endif; ?>

      <div class="card">
        <div class="card-body">
          <form method="post" class="row gy-3">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">

            <div class="col-12 col-md-8 col-lg-6">
              <label for="nombre" class="form-label">Nombre</label>
              <input
                type="text"
                class="form-control"
                id="nombre"
                name="nombre"
                maxlength="120"
                value="<?= h($nombre) ?>"
                required
                autofocus
              >
              <div class="form-text">Entre 3 y 120 caracteres.</div>
            </div>

            <div class="col-12 d-flex gap-2 justify-content-end">
              <a class="btn btn-outline-secondary" href="/libreria_lapicito/admin/categorias/">Cancelar</a>
              <button class="btn btn-primary" type="submit">Guardar cambios</button>
            </div>
          </form>
        </div>
      </div>
    </main>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
