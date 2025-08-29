<?php
include(__DIR__ . '/../includes/db.php');
require_once __DIR__ . '/../includes/auth.php';


if (function_exists('is_logged') && is_logged()) {
  header('Location: /libreria_lapicito/admin/index.php'); exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $pass  = $_POST['password'] ?? '';

  try {
    $stmt = $conexion->prepare("SELECT id_usuario, nombre, email, contrasena, id_rol
                                  FROM usuario
                                 WHERE email=? AND id_estado_usuario=1
                                 LIMIT 1");
    $stmt->bind_param('s',$email);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();

    if ($u && password_verify($pass, $u['contrasena'])) {
      if (session_status() === PHP_SESSION_NONE) session_start();
      $_SESSION['user'] = [
        'id_usuario'=>(int)$u['id_usuario'],
        'nombre'=>$u['nombre'],
        'email'=>$u['email'],
        'id_rol'=>(int)$u['id_rol'],
      ];
      header('Location: /libreria_lapicito/admin/index.php'); exit;
    } else {
      $error = 'Credenciales inválidas';
    }
  } catch (Throwable $e) {
    $error = 'Error de conexión o consulta';
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Ingresar — Los Lapicitos</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/8.0.1/normalize.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/skeleton/2.0.4/skeleton.min.css">
  <link rel="stylesheet" href="../css/style.css">
</head>
<body>

  
  <div class="barra"></div>

  
  <section class="lienzo">
    <div class="container">
      <div class="row">
       
      
        <div class="seven columns">
          <h1 class="titulo">Libreria Los Lapicitos</h1>
          <p class="desc">
            Bienvenido a tu sistema de gestión de inventario. Mantén el control de tus
            productos, ventas y pedidos de forma fácil y rápida.
            mail:admin@loslapicitos.com contraseña:Admin123!
          </p>

          <?php if ($error): ?>
            <div class="login-error"><?= htmlspecialchars($error) ?></div>
          <?php endif; ?>

          <form method="post" class="login-form" autocomplete="off">
            <input class="login-input" type="email" name="email" placeholder="Correo electrónico" required>
            <input class="login-input" type="password" name="password" placeholder="•••••••" required>
            <button class="btn" type="submit">Iniciar sesión</button>
          </form>
        </div>

        <!-- Columna derecha: ilustración -->
        <div class="five columns texto-derecha">
          <img class="ilustracion" src="../img/libro.png" alt="Libros y lápiz">
        </div>
      </div>
    </div>
  </section>

</body>
</html>
