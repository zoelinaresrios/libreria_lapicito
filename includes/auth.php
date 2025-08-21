<?php
// includes/auth.php — sesión y helpers de autenticación/roles
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }

define('ROL_ADMIN',      1);
define('ROL_SUPERVISOR', 2);
define('ROL_EMPLEADO',   3);

function is_logged(): bool {
    return isset($_SESSION['user']);
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function require_login(): void {
    if (!is_logged()) {
        header('Location: /los-lapicitos/admin/login.php');
        exit;
    }
}

function require_role(array $roles): void {
    require_login();
    $u = current_user();
    if (!$u || !in_array((int)$u['id_rol'], $roles, true)) {
        http_response_code(403);
        echo '<h2>Acceso denegado</h2>';
        exit;
    }
}
