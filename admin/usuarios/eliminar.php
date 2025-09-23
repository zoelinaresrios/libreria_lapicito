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
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }

$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
if($id<=0){ header('Location: /admin/usuarios/'); exit; }

// Traer datos
$st=$conexion->prepare("
  SELECT u.id_usuario, u.nombre, u.email, u.id_rol, r.nombre_rol
  FROM usuario u LEFT JOIN rol r ON r.id_rol=u.id_rol
  WHERE u.id_usuario=?
");
$st->bind_param('i',$id); $st->execute();
$user=$st->get_result()->fetch_assoc(); $st->close();
if(!$user){ header('Location: /admin/usuarios/'); exit; }

$st=$conexion->prepare("SELECT COUNT(*) AS c FROM usuario WHERE id_rol=?");
$ADMIN_ROLE_ID = 1;
$st->bind_param('i',$ADMIN_ROLE_ID); $st->execute();
$admins=(int)$st->get_result()->fetch_assoc()['c']; $st->close();

$st=$conexion->prepare("SELECT COUNT(*) c FROM venta WHERE id_usuario=?");
$st->bind_param('i',$id); $st->execute();
$ventas=(int)$st->get_result()->fetch_assoc()['c']; $st->close();

$st=$conexion->prepare("SELECT COUNT(*) c FROM auditoria WHERE id_usuario=?");
$st->bind_param('i',$id); $st->execute();
$audits=(int)$st->get_result()->fetch_assoc()['c']; $st->close();

$errors=[];
$meId = $_SESSION['user_id'] ?? 0; // ajustá según tu auth
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) $errors[]='Token inválido.';
  if($meId==$id) $errors[]='No podés eliminar tu propio usuario.';
  if($user['id_rol']==$ADMIN_ROLE_ID && $admins<=1) $errors[]='No se puede eliminar: es el único administrador.';
  if(($ventas+$audits)>0) $errors[]='No se puede eliminar: el usuario posee actividad registrada. Sugerencia: pasarlo a estado INACTIVO.';

  if(!$errors){
    $st=$conexion->prepare("DELETE FROM usuario WHERE id_usuario=?");
    $st->bind_param('i',$id); $st->execute(); $st->close();
    $_SESSION['flash_ok']='Usuario eliminado.';
    header('Location: /admin/usuarios/'); exit;
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Eliminar usuario — Los Lapicitos</title>
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
      <div class="inv-title">Eliminar usuario</div>
      <div class="prod-card">
        <?php if($errors): ?><div class="alert-error"><?php foreach($errors as $e) echo '<div>'.h($e).'</div>'; ?></div><?php endif; ?>

        <p>Vas a eliminar al usuario <strong><?= h($user['nombre']) ?></strong> (<?= h($user['email']) ?>).</p>
        <ul>
          <li>Rol: <strong><?= h($user['nombre_rol'] ?? '—') ?></strong></li>
          <li>Administradores activos en el sistema: <strong><?= $admins ?></strong></li>
          <li>Ventas asociadas: <strong><?= $ventas ?></strong></li>
          <li>Registros de auditoría: <strong><?= $audits ?></strong></li>
        </ul>

        <?php if(($ventas+$audits)>0 || ($user['id_rol']==$ADMIN_ROLE_ID && $admins<=1) || ($meId==$id)): ?>
          <p class="muted">No se puede eliminar por las condiciones arriba. Sugerencia: cambiar a estado “Inactivo”.</p>
          <a class="btn-sm btn-muted" href="/admin/usuarios/">Volver</a>
        <?php else: ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
            <input type="hidden" name="id" value="<?= (int)$id ?>">
            <a class="btn-sm btn-muted" href="/admin/usuarios/">Cancelar</a>
            <button class="btn-danger" type="submit" onclick="return confirm('¿Eliminar definitivamente?')">Eliminar</button>
          </form>
        <?php endif; ?>
      </div>
    </main>
  </div>
</body>
</html>
