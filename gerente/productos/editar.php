<?php
// /libreria_lapicito/admin/productos/editar.php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';

if (function_exists('is_logged') && !is_logged()) {
  header('Location: /libreria_lapicito/admin/login.php'); exit;
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function toFloat($s){
  $s = preg_replace('/[^\d,.\-]/','',(string)$s);
  if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
    $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
  } else { $s = str_replace(',', '.', $s); }
  return (float)$s;
}

$id = max(1, (int)($_GET['id'] ?? 0));

/* Cargar producto */
$st = $conexion->prepare("SELECT * FROM producto WHERE id_producto=? LIMIT 1");
$st->bind_param('i', $id);
$st->execute();
$prod = $st->get_result()->fetch_assoc();
$st->close();
if (!$prod){ http_response_code(404); echo "Producto no encontrado"; exit; }

/* Listas */
$subcats=[]; $proveedores=[];
$res = $conexion->query("SELECT id_subcategoria, nombre FROM subcategoria ORDER BY nombre");
while($r=$res->fetch_assoc()) $subcats[]=$r;
$res = $conexion->query("SELECT id_proveedor, nombre FROM proveedor ORDER BY nombre");
while($r=$res->fetch_assoc()) $proveedores[]=$r;

/* Valores del form (pre-cargados) */
$errores=[]; $ok=false;

$codigo  = $_POST ? trim($_POST['codigo'] ?? '') : $prod['codigo'];
$nombre  = $_POST ? trim($_POST['nombre'] ?? '') : $prod['nombre'];
$id_subcategoria = $_POST ? (int)($_POST['id_subcategoria'] ?? 0) : (int)$prod['id_subcategoria'];
$id_proveedor    = $_POST ? (int)($_POST['id_proveedor'] ?? 0)    : (int)$prod['id_proveedor'];
$ubicacion = $_POST ? trim($_POST['ubicacion'] ?? '') : ($prod['ubicacion'] ?? '');
$precio_compra = $_POST ? (string)($_POST['precio_compra'] ?? '') : (string)$prod['precio_compra'];
$precio_venta  = $_POST ? (string)($_POST['precio_venta']  ?? '') : (string)$prod['precio_venta'];
$activo  = $_POST ? (isset($_POST['activo']) ? 1 : 0) : (int)$prod['activo'];

if ($_SERVER['REQUEST_METHOD']==='POST'){
  if ($codigo==='') $errores[]='El código es obligatorio.';
  if ($nombre==='') $errores[]='El nombre es obligatorio.';
  if ($id_subcategoria<=0) $errores[]='Seleccioná una subcategoría.';
  if ($id_proveedor<=0) $errores[]='Seleccioná un proveedor.';

  $pc = toFloat($precio_compra);
  $pv = toFloat($precio_venta);
  if ($pc<0) $errores[]='Precio de compra inválido.';
  if ($pv<0) $errores[]='Precio de venta inválido.';
  if ($pc>0 && $pv>0 && $pc>$pv) $errores[]='El precio de venta debe ser ≥ compra.';

  // Código único (excluyendo el propio)
  $st = $conexion->prepare("SELECT 1 FROM producto WHERE codigo=? AND id_producto<>? LIMIT 1");
  $st->bind_param('si', $codigo, $id);
  $st->execute();
  if ($st->get_result()->fetch_row()) $errores[]='Ya existe otro producto con ese código.';
  $st->close();

  if (!$errores){
    $st = $conexion->prepare("
      UPDATE producto
      SET codigo=?, nombre=?, id_subcategoria=?, id_proveedor=?, ubicacion=?,
          precio_compra=?, precio_venta=?, activo=?, actualizado_en=NOW()
      WHERE id_producto=?
    ");
    // tipos: s s i i s d d i i
    $st->bind_param('ssiisddii',
      $codigo, $nombre, $id_subcategoria, $id_proveedor, $ubicacion,
      $pc, $pv, $activo, $id
    );
    $st->execute(); $st->close();
    $ok = true;

    // Refrescar datos
    $st = $conexion->prepare("SELECT * FROM producto WHERE id_producto=?");
    $st->bind_param('i',$id); $st->execute();
    $prod = $st->get_result()->fetch_assoc(); $st->close();
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Editar producto — Los Lapicitos</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/8.0.1/normalize.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/skeleton/2.0.4/skeleton.min.css">
  <link rel="stylesheet" href="/libreria_lapicito/css/style.css">
</head>
<body>
  <div class="barra"></div>
  <div class="inv-title">Gestión de Inventario</div>

  <div class="prod-shell">
    <aside class="prod-side">
      <ul class="prod-nav">
        <li><a href="/libreria_lapicito/admin/productos/">← Volver a Productos</a></li>
        <li><a class="active" href="#"><strong>Editar producto</strong></a></li>
      </ul>
    </aside>

    <main class="prod-main">
      <div class="prod-card">
        <div class="prod-head"><h5>Editar: <?= h($prod['nombre']) ?></h5></div>

        <?php if($ok): ?>
          <div class="lp-card" style="background:#fff;border-color:#cfe8d1;color:#215a2b">✅ Cambios guardados.</div>
        <?php endif; ?>
        <?php if($errores): ?>
          <div class="lp-card" style="background:#fff;border-color:#e7b1b1;color:#8a1f1f">
            <?php foreach($errores as $e): ?><div>• <?= h($e) ?></div><?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="post" class="form-grid" autocomplete="off">
          <div>
            <label>Código *</label>
            <input type="text" name="codigo" value="<?= h($codigo) ?>" required>

            <label>Nombre *</label>
            <input type="text" name="nombre" value="<?= h($nombre) ?>" required>

            <label>Subcategoría *</label>
            <select name="id_subcategoria" required>
              <option value="0">Seleccioná…</option>
              <?php foreach($subcats as $s): ?>
                <option value="<?= (int)$s['id_subcategoria'] ?>" <?= $id_subcategoria===(int)$s['id_subcategoria']?'selected':'' ?>>
                  <?= h($s['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>

            <label>Proveedor *</label>
            <select name="id_proveedor" required>
              <option value="0">Seleccioná…</option>
              <?php foreach($proveedores as $p): ?>
                <option value="<?= (int)$p['id_proveedor'] ?>" <?= $id_proveedor===(int)$p['id_proveedor']?'selected':'' ?>>
                  <?= h($p['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>

            <label>Ubicación</label>
            <input type="text" name="ubicacion" value="<?= h($ubicacion) ?>" placeholder="Ej: A1-03">
          </div>

          <div>
            <div class="row">
              <div class="six columns">
                <label>Precio compra</label>
                <input type="number" step="0.01" min="0" name="precio_compra" value="<?= h($precio_compra) ?>">
              </div>
              <div class="six columns">
                <label>Precio venta</label>
                <input type="number" step="0.01" min="0" name="precio_venta" value="<?= h($precio_venta) ?>">
              </div>
            </div>

            <label style="display:flex;align-items:center;gap:8px;margin-top:8px">
              <input type="checkbox" name="activo" value="1" <?= $activo? 'checked':'' ?>> Activo
            </label>
          </div>

          <div class="form-actions">
            <a class="button button-outline" href="/libreria_lapicito/admin/productos/">Cancelar</a>
            <button class="lp-btn" type="submit">Guardar cambios</button>
          </div>
        </form>
      </div>
    </main>
  </div>
</body>
</html>
