<?php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';// Autenticación general (login)

$HAS_ACL = file_exists(__DIR__ . '/../includes/acl.php');
if ($HAS_ACL) { require_once __DIR__ . '/../includes/acl.php'; }
else {
  if (session_status()===PHP_SESSION_NONE) session_start();//revisAa si hay sesion activa
  if (!function_exists('can')) { function can($k){ return true; } }
  if (!function_exists('require_perm')) { function require_perm($k){ return true; } }
}

require_perm('categorias.crear');// si no tiene retriccion de permisos da todos

if (session_status()===PHP_SESSION_NONE) session_start();
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }

$errors = [];
$nombre = trim($_POST['nombre'] ?? '');

if ($_SERVER['REQUEST_METHOD']==='POST') {
  
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    $errors[] = 'Token inválido.';
  }
  // Validaciones
  if ($nombre==='')               $errors[] = 'El nombre es obligatorio.';
  if (mb_strlen($nombre) < 3)     $errors[] = 'El nombre debe tener al menos 3 caracteres.';
  if (mb_strlen($nombre) > 120)   $errors[] = 'Máximo 120 caracteres.';

  
  if (!$errors) {
    $st = $conexion->prepare("SELECT 1 FROM categoria WHERE nombre = ? LIMIT 1");
    $st->bind_param('s', $nombre);
    $st->execute();
    if ($st->get_result()->fetch_row()) $errors[] = 'Ya existe una categoría con ese nombre.';
    $st->close();
  }

  if (!$errors) {
    $st = $conexion->prepare("INSERT INTO categoria(nombre) VALUES (?)");
    $st->bind_param('s', $nombre);
    $st->execute();
    $st->close();
    $_SESSION['flash_ok'] = 'Categoría creada correctamente.';
    header('Location: /admin/categorias/'); exit;
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Nueva categoría — Los Lapicitos</title>
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
        <li><a href="/admin/index.php">inicio</a></li>
        <li><a href="/admin/productos/">Productos</a></li>
        <li><a class="active" href="/admin/categorias/">Categorías</a></li>
        <li><a href="/admin/logout.php">Salir</a></li>
      </ul>
    </aside>

    <main class="prod-main">
      <div class="inv-title">Nueva categoría</div>

      <div class="prod-card">
        <?php if ($errors): ?>
          <div class="alert-error">
            <?php foreach($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="post" class="form-vert">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
          <label for="nombre">Nombre</label>
          <input class="u-full-width" type="text" id="nombre" name="nombre" maxlength="120"
                 value="<?= h($nombre) ?>" placeholder="Ej: Papelería" required>
          <div class="form-actions">
            <a class="btn-sm btn-muted" href="/admin/categorias/">Cancelar</a>
            <button class="btn-filter" type="submit">Crear</button>
          </div>
        </form>
      </div>
    </main>
  </div>
</body>
</html>
