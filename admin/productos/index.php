<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_role([ROL_ADMIN]);
require_once __DIR__ . '/../../includes/header.php';
?>
<h1>Productos</h1>
<p>Acá irá el ABM de productos (con categorías, subcategorías y stock mínimo).</p>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
