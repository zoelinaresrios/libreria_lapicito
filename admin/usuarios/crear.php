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
require_perm('usuarios.crear_empleado');

if (session_status()===PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf'])) { $_SESSION['csrf']=bin2hex(random_bytes(16)); }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Catálogos
$roles=[]; $r=$conexion->query("SELECT id_rol, nombre_rol FROM rol ORDER BY nombre_rol");
while($row=$r->fetch_assoc()) $roles[]=$row;
$estados=[]; $r=$conexion->query("SELECT id_estado_usuario, nombre_estado FROM estado_usuario ORDER BY nombre_estado");
while($row=$r->fetch_assoc()) $estados[]=$row;

$nombre = trim($_POST['nombre'] ?? '');
$email  = trim($_POST['email'] ?? '');
$idRol  = (int)($_POST['id_rol'] ?? 0);
$idEst  = (int)($_POST['id_estado_usuario'] ?? 0);
$pass   = $_POST['password'] ?? '';
$pass2  = $_POST['password2'] ?? '';

$errors=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) $errors[]='Token inválido.';
  if($nombre==='') $errors[]='El nombre es obligatorio.';
  if(!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[]='Email inválido.';
  if($idRol<=0) $errors[]='Seleccioná un rol.';
  if($idEst<=0) $errors[]='Seleccioná un estado.';
  if(strlen($pass)<6) $errors[]='La contraseña debe tener al menos 6 caracteres.';
  if($pass!==$pass2) $errors[]='Las contraseñas no coinciden.';

  // email único
  if(!$errors){
    $st=$conexion->prepare("SELECT 1 FROM usuario WHERE email=? LIMIT 1");
    $st->bind_param('s',$email);
    $st->execute();
    if($st->get_result()->fetch_row()) $errors[]='Ya existe un usuario con ese email.';
    $st->close();
  }

  if(!$errors){
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $st=$conexion->prepare("
      INSERT INTO usuario(nombre,email,contrasena,id_rol,id_estado_usuario,creado_en,actualizado_en)
      VALUES (?,?,?,?,?,NOW(),NOW())
    ");
    $st->bind_param('sssii',$nombre,$email,$hash,$idRol,$idEst);
    $st->execute(); $st->close();
    $_SESSION['flash_ok']='Usuario creado correctamente.';
    header('Location: /libreria_lapicito/admin/usuarios/'); exit;
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Nuevo usuario — Los Lapicitos</title>
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
        <li><a class="active" href="/libreria_lapicito/admin/usuarios/">Usuarios</a></li>
        <li><a href="/libreria_lapicito/admin/logout.php">Salir</a></li>
      </ul>
    </aside>

    <main class="prod-main">
      <div class="inv-title">Nuevo usuario</div>
      <div class="prod-card">
        <?php if($errors): ?><div class="alert-error"><?php foreach($errors as $e) echo '<div>'.h($e).'</div>'; ?></div><?php endif; ?>

        <form method="post" class="form-vert">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
          <label>Nombre</label>
          <input class="u-full-width" type="text" name="nombre" maxlength="160" value="<?= h($nombre) ?>" required>

          <label>Email</label>
          <input class="u-full-width" type="email" name="email" maxlength="160" value="<?= h($email) ?>" required>

          <div class="row">
            <div class="six columns">
              <label>Rol</label>
              <select name="id_rol" required>
                <option value="0">Seleccionar…</option>
                <?php foreach($roles as $r): ?>
                  <option value="<?= (int)$r['id_rol'] ?>" <?= $idRol===(int)$r['id_rol']?'selected':'' ?>><?= h($r['nombre_rol']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="six columns">
              <label>Estado</label>
              <select name="id_estado_usuario" required>
                <option value="0">Seleccionar…</option>
                <?php foreach($estados as $e): ?>
                  <option value="<?= (int)$e['id_estado_usuario'] ?>" <?= $idEst===(int)$e['id_estado_usuario']?'selected':'' ?>><?= h($e['nombre_estado']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="row">
            <div class="six columns">
              <label>Contraseña</label>
              <input class="u-full-width" type="password" name="password" required>
            </div>
            <div class="six columns">
              <label>Repetir contraseña</label>
              <input class="u-full-width" type="password" name="password2" required>
            </div>
          </div>

          <div class="form-actions">
            <a class="btn-sm btn-muted" href="/libreria_lapicito/admin/usuarios/">Cancelar</a>
            <button class="btn-filter" type="submit">Crear</button>
          </div>
        </form>
      </div>
    </main>
  </div>
</body>
</html>
