<?php
include(__DIR__ . '/../includes/db.php');   // crea $conexion
require_once __DIR__ . '/../includes/auth.php'; // si ya lo tenés; si no, podés quitar esta línea

if (function_exists('is_logged') && is_logged()) {
  header('Location: /libreria_lapicito/admin/index.php');
  exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $pass  = $_POST['password'] ?? '';

  // si hubo error de conexión, db.php ya hizo exit; si estamos acá, $conexion existe
  $stmt = $conexion->prepare("SELECT id_usuario, nombre, email, contrasena, id_rol
                              FROM usuario
                              WHERE email = ? AND id_estado_usuario = 1
                              LIMIT 1");
  if ($stmt) {
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $res  = $stmt->get_result();
    $user = $res->fetch_assoc();

    if ($user && password_verify($pass, $user['contrasena'])) {
      if (session_status() === PHP_SESSION_NONE) { session_start(); }
      $_SESSION['user'] = [
        'id_usuario' => (int)$user['id_usuario'],
        'nombre'     => $user['nombre'],
        'email'      => $user['email'],
        'id_rol'     => (int)$user['id_rol'],
      ];
      header('Location: /libreria_lapicito/admin/index.php');
      exit;
    } else {
      $error = 'Credenciales inválidas';
    }

    $stmt->close();
  } else {
    $error = 'Error de consulta';
  }

  // opcional: cerrar conexión
  // $conexion->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ingresar — Admin</title>
<link rel="stylesheet" href="/libreria_lapicito/admin/assets/css/admin.css">
</head>
<body class="login-body">
  <form class="card login" method="post">
    <h1>Los Lapicitos</h1>
    <p class="muted">Panel Administrativo</p>
    <?php if($error): ?><div class="alert"><?=htmlspecialchars($error)?></div><?php endif; ?>
    <label>Email</label>
    <input type="email" name="email" required>
    <label>Contraseña</label>
    <input type="password" name="password" required>
    <button type="submit" class="btn primary">Ingresar</button>
  </form>
</body>
</html>
