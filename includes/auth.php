<?php
if (session_status() === PHP_SESSION_NONE) session_start();

function is_logged(): bool {
  return !empty($_SESSION['user'])
      && !empty($_SESSION['user']['id_usuario'])
      && !empty($_SESSION['user']['id_rol']);
}


function require_login(): void {
  if (!is_logged()) {
 
    $next = urlencode($_SERVER['REQUEST_URI'] ?? '');
    header('Location:/admin/login.php?next='.$next);
    exit;
  }
}
