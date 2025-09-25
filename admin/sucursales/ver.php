<?php
// /admin/sucursales/ver.php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';
$HAS_ACL = file_exists(__DIR__ . '/../includes/acl.php');
if ($HAS_ACL) { require_once __DIR__ . '/../includes/acl.php'; }
else {
  if (session_status()===PHP_SESSION_NONE) session_start();
  if (!function_exists('can')) { function can($k){ return true; } }
  if (!function_exists('require_perm')) { function require_perm($k){ return true; } }
}
require_perm('sucursales.ver');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (session_status()===PHP_SESSION_NONE) session_start();
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$isNew = isset($_GET['new']);
$edit  = isset($_GET['edit']) || $isNew;
$id    = (int)($_GET['id'] ?? 0);

// CSRF
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

// Guardar alta/edición
if ($_SERVER['REQUEST_METHOD'] === 'POST' && can($isNew?'sucursales.crear':'sucursales.editar')) {
  if (!hash_equals($csrf, $_POST['csrf'] ?? '')) { http_response_code(400); exit('CSRF'); }
  $nombre   = trim($_POST['nombre'] ?? '');
  $direccion= trim($_POST['direccion'] ?? '');
  $telefono = trim($_POST['telefono'] ?? '');
  $email    = trim($_POST['email'] ?? '');

  if ($isNew) {
    $st = $conexion->prepare("INSERT INTO sucursal (nombre,direccion,telefono,email,creado_en) VALUES (?,?,?,?,NOW())");
    $st->bind_param('ssss',$nombre,$direccion,$telefono,$email);
    $st->execute();
    $_SESSION['flash_ok'] = 'Sucursal creada';
    header('Location: /admin/sucursales/index.php'); exit;
  } else {
    $st = $conexion->prepare("UPDATE sucursal SET nombre=?, direccion=?, telefono=?, email=? WHERE id_sucursal=?");
    $st->bind_param('ssssi',$nombre,$direccion,$telefono,$email,$id);
    $st->execute();
    $_SESSION['flash_ok'] = 'Sucursal actualizada';
    header('Location: /admin/sucursales/ver.php?id='.$id); exit;
  }
}

if (!$isNew) {
  // Datos sucursal
  $st = $conexion->prepare("SELECT * FROM sucursal WHERE id_sucursal=?");
  $st->bind_param('i',$id); $st->execute();
  $suc = $st->get_result()->fetch_assoc(); $st->close();
  if (!$suc) { http_response_code(404); exit('Sucursal no encontrada'); }

  // Stock por producto en esta sucursal
  $inv = $conexion->prepare("
    SELECT i.id_inventario, i.id_producto, p.nombre AS producto, i.stock_actual, i.stock_minimo, i.ubicacion, i.actualizado_en
      FROM inventario i
      JOIN producto p ON p.id_producto = i.id_producto
     WHERE i.id_sucursal = ?
     ORDER BY p.nombre
  ");
  $inv->bind_param('i',$id); $inv->execute();
  $inventario = $inv->get_result()->fetch_all(MYSQLI_ASSOC);
  $inv->close();

  // Ventas del mes
  $vent = $conexion->prepare("
    SELECT COUNT(*) cant, COALESCE(SUM(vd.cantidad*vd.precio_unitario),0) monto
      FROM venta v
      JOIN venta_detalle vd ON vd.id_venta=v.id_venta
     WHERE v.id_sucursal=? AND DATE_FORMAT(v.fecha_hora,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')
  ");
  $vent->bind_param('i',$id); $vent->execute();
  $ventasMes = $vent->get_result()->fetch_assoc(); $vent->close();

  // Últimos movimientos que involucren esta sucursal (como origen o destino)
  $mov = $conexion->prepare("
    SELECT m.id_movimiento, tm.nombre_tipo, m.fecha_hora, m.id_sucursal_origen, m.id_sucursal_destino, m.observacion
      FROM movimiento m
      JOIN tipo_movimiento tm ON tm.id_tipo_movimiento = m.id_tipo_movimiento
     WHERE m.id_sucursal_origen=? OR m.id_sucursal_destino=?
     ORDER BY m.fecha_hora DESC
     LIMIT 12
  ");
  $mov->bind_param('ii',$id,$id); $mov->execute();
  $movs = $mov->get_result()->fetch_all(MYSQLI_ASSOC); $mov->close();
}

function mesY($ts=null){
  $ts=$ts??time();
  $m=['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
  return ucfirst($m[(int)date('n',$ts)-1]).' '.date('Y',$ts);
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title><?= $isNew?'Nueva Sucursal':'Sucursal #'.(int)$id ?> — Los Lapicitos</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/vendor/normalize.css">
  <link rel="stylesheet" href="/vendor/skeleton.css">
  <link rel="stylesheet" href="/css/style.css?v=13">
  <link rel="stylesheet" href="/css/sucursales.css?v=1">
</head>
<body>
<div class="barra"></div>

<div class="prod-shell">
  <aside class="prod-side">
    <ul class="prod-nav">
      <li><a href="/admin/sucursales/">↩ Sucursales</a></li>
    </ul>
  </aside>

  <main class="prod-main">
    <div class="inv-title"><?= $isNew? 'Crear sucursal' : 'Sucursal — Detalle' ?></div>

    <div class="prod-card">
      <div class="prod-head"><h5><?= $isNew?'Datos':'Datos de la sucursal' ?></h5>
        <div>
          <?php if (!$isNew): ?>
            <?php if (!$edit && can('sucursales.editar')): ?>
              <a class="btn-sm" href="?id=<?= (int)$id ?>&edit=1">Editar</a>
            <?php endif; ?>
            <?php if (can('movimientos.crear')): ?>
              <a class="btn-sm" href="/admin/sucursales/transferir.php?origen=<?= (int)$id ?>">Transferir desde aquí</a>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <div class="row">
          <div class="six columns">
            <label>Nombre</label>
            <input type="text" name="nombre" value="<?= h($isNew?'':$suc['nombre']) ?>" <?= $edit?'':'readonly' ?> required>
          </div>
          <div class="six columns">
            <label>Email</label>
            <input type="email" name="email" value="<?= h($isNew?'':$suc['email']) ?>" <?= $edit?'':'readonly' ?>>
          </div>
        </div>
        <div class="row">
          <div class="eight columns">
            <label>Dirección</label>
            <input type="text" name="direccion" value="<?= h($isNew?'':$suc['direccion']) ?>" <?= $edit?'':'readonly' ?>>
          </div>
          <div class="four columns">
            <label>Teléfono</label>
            <input type="text" name="telefono" value="<?= h($isNew?'':$suc['telefono']) ?>" <?= $edit?'':'readonly' ?>>
          </div>
        </div>
        <?php if ($edit): ?>
          <button class="btn-filter" type="submit"><?= $isNew?'Crear':'Guardar cambios' ?></button>
        <?php endif; ?>
      </form>
    </div>

    <?php if(!$isNew): ?>
      <div class="row">
        <div class="six columns">
          <div class="prod-card">
            <div class="prod-head"><h5>Inventario</h5></div>
            <div class="table-wrap" style="max-height:420px;overflow:auto">
              <table class="u-full-width">
                <thead><tr>
                  <th>Producto</th><th>Stock</th><th>Mín.</th><th>Ubicación</th><th>Actualizado</th>
                </tr></thead>
                <tbody>
                  <?php foreach($inventario as $i): ?>
                    <tr class="<?= ($i['stock_actual'] < $i['stock_minimo'] ? 'tr-warn' : '') ?>">
                      <td><?= h($i['producto']) ?></td>
                      <td><?= (int)$i['stock_actual'] ?></td>
                      <td><?= (int)$i['stock_minimo'] ?></td>
                      <td><?= h($i['ubicacion']) ?></td>
                      <td><?= h($i['actualizado_en']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if(empty($inventario)): ?><tr><td colspan="5" class="muted">Sin inventario.</td></tr><?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="six columns">
          <div class="prod-card">
            <div class="prod-head"><h5>Ventas del mes</h5></div>
            <p><b>Comprobantes:</b> <?= (int)($ventasMes['cant'] ?? 0) ?></p>
            <p><b>Monto:</b> $ <?= number_format((float)($ventasMes['monto'] ?? 0),2,',','.') ?> <span class="muted">(<?= h(mesY()) ?>)</span></p>
          </div>

          <div class="prod-card">
            <div class="prod-head"><h5>Últimos movimientos</h5></div>
            <div class="table-wrap" style="max-height:260px;overflow:auto">
              <table class="u-full-width">
                <thead><tr><th>ID</th><th>Tipo</th><th>Fecha</th><th>Desde</th><th>Hacia</th></tr></thead>
                <tbody>
                  <?php foreach($movs as $m): ?>
                    <tr>
                      <td>#<?= (int)$m['id_movimiento'] ?></td>
                      <td><?= h($m['nombre_tipo']) ?></td>
                      <td><?= h($m['fecha_hora']) ?></td>
                      <td><?= (int)$m['id_sucursal_origen'] ?></td>
                      <td><?= (int)$m['id_sucursal_destino'] ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if(empty($movs)): ?><tr><td colspan="5" class="muted">Sin movimientos recientes.</td></tr><?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

        </div>
      </div>
    <?php endif; ?>
  </main>
</div>

<?php
$FLASH_OK  = $_SESSION['flash_ok']  ?? '';
$FLASH_ERR = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);
?>
<script>window.__FLASH__={ok:<?=json_encode($FLASH_OK,JSON_UNESCAPED_UNICODE)?>,err:<?=json_encode($FLASH_ERR,JSON_UNESCAPED_UNICODE)?>};</script>
<script src="/js/toast.js?v=1"></script>
</body>
</html>
