<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_role([ROL_ADMIN]);

$cn = db();
$sql = "SELECT u.id_usuario, u.nombre, u.email, r.nombre_rol
        FROM usuario u
        JOIN rol r ON r.id_rol = u.id_rol
        ORDER BY u.id_usuario DESC";
$usuarios = $cn->query($sql)->fetch_all(MYSQLI_ASSOC);
db_close($cn);

require_once __DIR__ . '/../../includes/header.php';
?>
<h1>Usuarios</h1>
<a class="btn primary" href="crear.php">+ Crear usuario</a>
<table class="tbl">
  <thead><tr><th>ID</th><th>Nombre</th><th>Email</th><th>Rol</th><th>Acciones</th></tr></thead>
  <tbody>
    <?php foreach($usuarios as $u): ?>
      <tr>
        <td><?=$u['id_usuario']?></td>
        <td><?=htmlspecialchars($u['nombre'])?></td>
        <td><?=htmlspecialchars($u['email'])?></td>
        <td><?=htmlspecialchars($u['nombre_rol'])?></td>
        <td>
          <a class="btn" href="editar.php?id=<?=$u['id_usuario']?>">Editar</a>
          <a class="btn danger" href="eliminar.php?id=<?=$u['id_usuario']?>" onclick="return confirm('¿Eliminar usuario?')">Eliminar</a>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
