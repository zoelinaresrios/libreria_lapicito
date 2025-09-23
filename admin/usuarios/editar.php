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
require_perm('usuarios.gestionar');

if (session_status()===PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf'])) { $_SESSION['csrf']=bin2hex(random_bytes(16)); }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }

$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
if($id<=0){ header('Location: /admin/usuarios/'); exit; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);


$roles=[]; $r=$conexion->query("SELECT id_rol, nombre_rol FROM rol ORDER BY nombre_rol");
while($row=$r->fetch_assoc()) $roles[]=$row;
$estados=[]; $r=$conexion->query("SELECT id_estado_usuario, nombre_estado FROM estado_usuario ORDER BY nombre_estado");
while($row=$r->fetch_assoc()) $estados[]=$row;

$st=$conexion->prepare("SELECT id_usuario, nombre, email, id_rol, id_estado_usuario FROM usuario WHERE id_usuario=?");
$st->bind_param('i',$id); $st->execute();
$u=$st->get_result()->fetch_assoc(); $st->close();
if(!$u){ header('Location: /admin/usuarios/'); exit; }

$nombre=trim($_POST['nombre'] ?? $u['nombre']);
$email =trim($_POST['email']  ?? $u['email']);
$idRol =(int)($_POST['id_rol'] ?? $u['id_rol']);
$idEst =(int)($_POST['id_estado_usuario'] ?? $u['id_estado_usuario']);
$pass = $_POST['password'] ?? '';
$pass2= $_POST['password2'] ?? '';

$errors=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) $errors[]='Token inválido.';
  if($nombre==='') $errors[]='El nombre es obligatorio.';
  if(!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[]='Email inválido.';
  if($idRol<=0) $errors[]='Seleccioná un rol.';
  if($idEst<=0) $errors[]='Seleccioná un estado.';
  if($pass!=='' && strlen($pass)<6) $errors[]='La nueva contraseña debe tener al menos 6 caracteres.';
  if($pass!==$pass2) $errors[]='Las contraseñas no coinciden.';

  
  if(!$errors){
    $st=$conexion->prepare("SELECT 1 FROM usuario WHERE email=? AND id_usuario<>? LIMIT 1");
    $st->bind_param('si',$email,$id);
    $st->execute();
    if($st->get_result()->fetch_row()) $errors[]='Ya existe otro usuario con ese email.';
    $st->close();
  }

  if(!$errors){
    $conexion->begin_transaction();
    try{
      $st=$conexion->prepare("UPDATE usuario SET nombre=?, email=?, id_rol=?, id_estado_usuario=?, actualizado_en=NOW() WHERE id_usuario=?");
      $st->bind_param('ssiii',$nombre,$email,$idRol,$idEst,$id);
      $st->execute(); $st->close();

      if($pass!==''){
        $hash=password_hash($pass,PASSWORD_DEFAULT);
       
        $st=$conexion->prepare("UPDATE usuario SET contrasena=? WHERE id_usuario=?");
        $st->bind_param('si',$hash,$id);
        $st->execute(); $st->close();
      }

      $conexion->commit();
      $_SESSION['flash_ok']='Usuario actualizado.';
      header('Location: /admin/usuarios/'); exit;
    }catch(Exception $e){
      $conexion->rollback();
      $errors[]='Error al guardar: '.$e->getMessage();
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Editar usuario — Los Lapicitos</title>
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
        <li><a class="active" href="/admin/usuarios/">Usuarios</a></li>
        <li><a href="/admin/logout.php">Salir</a></li>
      </ul>
    </aside>

    <main class="prod-main">
      <div class="inv-title">Editar usuario</div>
      <div class="prod-card">
        <?php if($errors): ?><div class="alert-error"><?php foreach($errors as $e) echo '<div>'.h($e).'</div>'; ?></div><?php endif; ?>

        <form method="post" class="form-vert">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
          <input type="hidden" name="id" value="<?= (int)$id ?>">

          <label>Nombre</label>
          <input class="u-full-width" type="text" name="nombre" maxlength="160" value="<?= h($nombre) ?>" required>

          <label>Email</label>
          <input class="u-full-width" type="email" name="email" maxlength="160" value="<?= h($email) ?>" required>

          <div class="row">
            <div class="six columns">
              <label>Rol</label>
              <select name="id_rol" required>
                <?php foreach($roles as $r): ?>
                  <option value="<?= (int)$r['id_rol'] ?>" <?= $idRol===(int)$r['id_rol']?'selected':'' ?>><?= h($r['nombre_rol']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="six columns">
              <label>Estado</label>
              <select name="id_estado_usuario" required>
                <?php foreach($estados as $e): ?>
                  <option value="<?= (int)$e['id_estado_usuario'] ?>" <?= $idEst===(int)$e['id_estado_usuario']?'selected':'' ?>><?= h($e['nombre_estado']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <fieldset class="mt-2">
            <legend>Cambiar contraseña (opcional)</legend>
            <div class="row">
              <div class="six columns">
                <label>Nueva contraseña</label>
                <input class="u-full-width" type="password" name="password">
              </div>
              <div class="six columns">
                <label>Repetir contraseña</label>
                <input class="u-full-width" type="password" name="password2">
              </div>
            </div>
          </fieldset>

          <div class="form-actions">
            <a class="btn-sm btn-muted" href="/admin/usuarios/">Cancelar</a>
            <button class="btn-filter" type="submit">Guardar cambios</button>
          </div>
        </form>
      </div>
    </main>
  </div>
</body>
</html>
