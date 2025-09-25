<?php
// /admin/sucursales/transferir.php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';
$HAS_ACL = file_exists(__DIR__ . '/../includes/acl.php');
if ($HAS_ACL) { require_once __DIR__ . '/../includes/acl.php'; }
else {
  if (session_status()===PHP_SESSION_NONE) session_start();
  if (!function_exists('can')) { function can($k){ return true; } }
  if (!function_exists('require_perm')) { function require_perm($k){ return true; } }
}
require_perm('movimientos.crear');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (session_status()===PHP_SESSION_NONE) session_start();
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// CSRF
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$sucursales = $conexion->query("SELECT id_sucursal, nombre FROM sucursal ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);
$productos  = $conexion->query("SELECT id_producto, nombre FROM producto WHERE activo=1 ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);

$origen = (int)($_GET['origen'] ?? 0);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Transferir productos — Los Lapicitos</title>
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
    <div class="inv-title">Transferir productos entre sucursales</div>

    <div class="prod-card">
      <div class="prod-head"><h5>Datos de la transferencia</h5></div>

      <form method="post" action="/admin/sucursales/transferir_guardar.php" id="f">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <div class="row">
          <div class="six columns">
            <label>Origen</label>
            <select name="id_sucursal_origen" required>
              <option value="">Seleccioná…</option>
              <?php foreach($sucursales as $s): ?>
                <option value="<?=$s['id_sucursal']?>" <?= $origen===$s['id_sucursal']?'selected':'' ?>>
                  <?= h($s['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="six columns">
            <label>Destino</label>
            <select name="id_sucursal_destino" required>
              <option value="">Seleccioná…</option>
              <?php foreach($sucursales as $s): ?>
                <option value="<?=$s['id_sucursal']?>"><?= h($s['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <label>Observación (opcional)</label>
        <input type="text" name="observacion" placeholder="Ej: Reposición de novedades">

        <div class="prod-head" style="margin-top:14px"><h5>Productos</h5></div>
        <div id="rows"></div>
        <button type="button" class="btn-sm" onclick="addRow()">+ Agregar producto</button>

        <div style="margin-top:16px">
          <button class="btn-filter" type="submit">Confirmar transferencia</button>
        </div>
      </form>
    </div>
  </main>
</div>

<script>
const productos = <?= json_encode($productos, JSON_UNESCAPED_UNICODE) ?>;
function addRow() {
  const wrap = document.getElementById('rows');
  const idx = wrap.children.length;
  const row = document.createElement('div');
  row.className = 'row trans-row';
  row.innerHTML = `
    <div class="eight columns">
      <label>Producto</label>
      <select name="prod[${idx}][id_producto]" required>
        <option value="">Seleccioná…</option>
        ${productos.map(p => `<option value="${p.id_producto}">${p.nombre.replace(/</g,'&lt;')}</option>`).join('')}
      </select>
    </div>
    <div class="four columns">
      <label>Cantidad</label>
      <input type="number" min="1" step="1" name="prod[${idx}][cantidad]" required>
    </div>`;
  wrap.appendChild(row);
}
addRow();
</script>
</body>
</html>
