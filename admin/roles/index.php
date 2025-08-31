<?php
// /libreria_lapicito/admin/roles/index.php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/acl.php';

if (function_exists('is_logged') && !is_logged()) {
  header('Location: /libreria_lapicito/admin/login.php'); exit;
}

/* Solo Admin (o quien tenga gestión total de usuarios) */
require_perm('usuarios.gestionar');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* Roles desde DB */
$roles = [];
$res = $conexion->query("SELECT id_rol, nombre_rol FROM rol ORDER BY id_rol ASC");
while ($r = $res->fetch_assoc()) $roles[] = $r;

/* Cantidad de usuarios por rol */
$roleCounts = [];
$res = $conexion->query("SELECT id_rol, COUNT(*) AS cant FROM usuario GROUP BY id_rol");
while ($r = $res->fetch_assoc()) $roleCounts[(int)$r['id_rol']] = (int)$r['cant'];

/* Etiquetas amigables para cada permiso */
$PERM_LABELS = [
  'ventas.rapidas'            => 'Ventas rápidas',
  'inventario.ver'            => 'Ver inventario',
  'inventario.ingresar'       => 'Ingresar stock',
  'inventario.editar'         => 'Editar stock',
  'inventario.movimientos.ver'=> 'Ver mov. de stock',
  'alertas.ver'               => 'Ver alertas',
  'alertas.gestionar'         => 'Gestionar alertas',
  'productos.ver'             => 'Ver productos',
  'productos.crear'           => 'Crear productos',
  'productos.editar'          => 'Editar productos',
  'productos.modificar_precios'=> 'Modificar precios',
  'productos.duplicar'        => 'Duplicar productos',
  'productos.eliminar'        => 'Eliminar productos',
  'productos.top.ver'         => 'Top productos',
  'categorias.editar'         => 'Editar categorías',
  'pedidos.aprobar'           => 'Aprobar pedidos',
  'ventas.historial.ver'      => 'Historial de ventas',
  'reportes.simple'           => 'Reportes simples',
  'reportes.detallados'       => 'Reportes detallados',
  'reportes.full'             => 'Reportes completos',
  'usuarios.crear_empleado'   => 'Crear empleados',
  'usuarios.gestionar'        => 'Gestionar usuarios',
  'sistema.config'            => 'Configurar sistema',
  'parametros.editar'         => 'Parámetros del sistema',
  'sucursales.gestionar'      => 'Gestionar sucursales',
  'ventas.eliminar'           => 'Eliminar ventas',
  'backups.mant'              => 'Backups y mantenimiento',
  'auditoria.ver'             => 'Ver auditoría',
];

/* Helper para mostrar una etiqueta bonita aunque no esté en el mapa */
function perm_label(string $k, array $labels): string {
  if (isset($labels[$k])) return $labels[$k];
  return ucwords(str_replace('.', ' → ', str_replace('_',' ', $k)));
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Roles y permisos — Los Lapicitos</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/8.0.1/normalize.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/skeleton/2.0.4/skeleton.min.css">
  <link rel="stylesheet" href="/libreria_lapicito/css/style.css">
</head>
<body>
  <div class="barra"></div>
  <div class="inv-title">Roles y permisos (solo lectura)</div>

  <div class="prod-shell">
    <aside class="prod-side">
      <ul class="prod-nav">
        <li><a href="/libreria_lapicito/admin/index.php">← Volver al Dashboard</a></li>
        <li><a class="active" href="#"><strong>Roles y permisos</strong></a></li>
      </ul>
    </aside>

    <main class="prod-main">
      <div class="prod-card">
        <div class="prod-head">
          <h5>Resumen de roles</h5>
          <div class="muted">Ver y auditar qué accede cada rol. (Esta pantalla no modifica nada)</div>
        </div>

        <?php foreach ($roles as $rol): ?>
          <?php
            $idRol = (int)$rol['id_rol'];
            $perms = perms_from_role($idRol);           // viene de includes/acl.php
            $isAll = isset($perms['*']);
            $cant  = $roleCounts[$idRol] ?? 0;
          ?>
          <div class="lp-role">
            <div class="lp-role-head">
              <h6 style="margin:0">
                <?= h($rol['nombre_rol']) ?>
                <small class="lp-chip"><?= $cant ?> usuario<?= $cant===1?'':'s' ?></small>
              </h6>
            </div>

            <?php if ($isAll): ?>
              <div class="lp-chip all">Todos los permisos</div>
              <p class="muted" style="margin-top:8px">Incluye absolutamente todas las secciones y acciones.</p>
            <?php else: ?>
              <div class="lp-perms">
                <?php foreach (array_keys($perms) as $k): ?>
                  <span class="lp-chip"><?= h(perm_label($k, $PERM_LABELS)) ?></span>
                <?php endforeach; ?>
                <?php if (empty($perms)): ?>
                  <span class="muted">Este rol aún no tiene permisos asignados.</span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <div class="lp-note muted" style="margin-top:14px">
          Tip: para cambiar el <strong>rol</strong> de un usuario, usá
          <a href="/libreria_lapicito/admin/usuarios/">Usuarios</a> → “Cambiar rol”.
        </div>
      </div>
    </main>
  </div>
</body>
</html>
