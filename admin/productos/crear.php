<?php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';

if (function_exists('is_logged') && !is_logged()) {
  header('Location: /libreria_lapicito/admin/login.php'); exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function toFloat($s){
  $s = preg_replace('/[^\d,.\-]/','', (string)$s);
  if (strpos($s, ',') !== false && strpos($s, '.') !== false){
    $s = str_replace('.', '', $s);      
    $s = str_replace(',', '.', $s);     
  } else {
    $s = str_replace(',', '.', $s);
  }
  return (float)$s;
}
$subcats = [];
$proveedores = [];
$sucursales = [];

$res = $conexion->query("SELECT id_subcategoria, nombre FROM subcategoria ORDER BY nombre");
while ($row = $res->fetch_assoc()) { $subcats[] = $row; }

$res = $conexion->query("SELECT id_proveedor, nombre FROM proveedor ORDER BY nombre");
while ($row = $res->fetch_assoc()) { $proveedores[] = $row; }

$res = $conexion->query("SELECT id_sucursal, nombre FROM sucursal ORDER BY nombre");
while ($row = $res->fetch_assoc()) { $sucursales[] = $row; }

$errores = [];
$ok = false;

$codigo  = trim($_POST['codigo']  ?? '');
$nombre  = trim($_POST['nombre']  ?? '');
$id_subcategoria = (int)($_POST['id_subcategoria'] ?? 0);
$id_proveedor    = (int)($_POST['id_proveedor']    ?? 0);
$ubicacion = trim($_POST['ubicacion'] ?? '');
$precio_compra = (string)($_POST['precio_compra'] ?? '');
$precio_venta  = (string)($_POST['precio_venta']  ?? '');
$activo  = isset($_POST['activo']) ? 1 : 1; 

$crear_inventario = isset($_POST['crear_inventario']) ? 1 : 0;
$id_sucursal = (int)($_POST['id_sucursal'] ?? 0);
$stock_minimo  = (int)($_POST['stock_minimo']  ?? 0);
$stock_inicial = (int)($_POST['stock_inicial'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  
  if ($codigo === '')   $errores[] = 'El código es obligatorio.';
  if ($nombre === '')   $errores[] = 'El nombre es obligatorio.';
  if ($id_subcategoria <= 0) $errores[] = 'Seleccioná una subcategoría.';
  if ($id_proveedor    <= 0) $errores[] = 'Seleccioná un proveedor.';

  $pc = toFloat($precio_compra);
  $pv = toFloat($precio_venta);
  if ($pc < 0) $errores[] = 'Precio de compra inválido.';
  if ($pv < 0) $errores[] = 'Precio de venta inválido.';
  if ($pc > 0 && $pv > 0 && $pc > $pv) $errores[] = 'El precio de venta debe ser mayor o igual al de compra.';

  
  $stmt = $conexion->prepare("SELECT 1 FROM subcategoria WHERE id_subcategoria=?");
  $stmt->bind_param('i',$id_subcategoria); $stmt->execute();
  if (!$stmt->get_result()->fetch_row()) $errores[] = 'La subcategoría no existe.'; $stmt->close();

  $stmt = $conexion->prepare("SELECT 1 FROM proveedor WHERE id_proveedor=?");
  $stmt->bind_param('i',$id_proveedor); $stmt->execute();
  if (!$stmt->get_result()->fetch_row()) $errores[] = 'El proveedor no existe.'; $stmt->close();

  
  $stmt = $conexion->prepare("SELECT 1 FROM producto WHERE codigo=? LIMIT 1");
  $stmt->bind_param('s',$codigo); $stmt->execute();
  if ($stmt->get_result()->fetch_row()) $errores[] = 'Ya existe un producto con ese código.'; $stmt->close();

  // Inventario inicial
  if ($crear_inventario) {
    if ($id_sucursal <= 0) $errores[] = 'Seleccioná una sucursal para el inventario.';
    if ($stock_minimo < 0) $errores[] = 'Stock mínimo inválido.';
    if ($stock_inicial < 0) $errores[] = 'Stock inicial inválido.';
  }

  if (!$errores) {
    $conexion->begin_transaction();
    try {
     
      $stmt = $conexion->prepare("
        INSERT INTO producto
          (codigo, nombre, id_subcategoria, id_proveedor, ubicacion,
           precio_compra, precio_venta, activo, creado_en, actualizado_en)
        VALUES (?,?,?,?,?,?,?, ?, NOW(), NOW())
      ");
    
      $stmt->bind_param(
        'ssiisddi',
        $codigo,          
        $nombre,           
        $id_subcategoria,  
        $id_proveedor,     
        $ubicacion,        
        $pc,               
        $pv,               
        $activo           
      );
      $stmt->execute();
      $id_producto = $conexion->insert_id;
      $stmt->close();

      if ($crear_inventario) {
        $stmt = $conexion->prepare("SELECT 1 FROM inventario WHERE id_sucursal=? AND id_producto=? LIMIT 1");
        $stmt->bind_param('ii', $id_sucursal, $id_producto);
        $stmt->execute();
        $existeInv = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();

        if (!$existeInv) {
          $stmt = $conexion->prepare("
            INSERT INTO inventario (id_sucursal, id_producto, stock_minimo, stock_actual)
            VALUES (?,?,?,?)
          ");
          $stmt->bind_param('iiii', $id_sucursal, $id_producto, $stock_minimo, $stock_inicial);
          $stmt->execute();
          $stmt->close();
        }
      }

      $conexion->commit();
      $ok = true;

      $codigo = $nombre = $ubicacion = $precio_compra = $precio_venta = '';
      $id_subcategoria = $id_proveedor = 0;
      $activo = 1; $crear_inventario = 0; $id_sucursal = 0; $stock_minimo = $stock_inicial = 0;

    } catch (Throwable $e) {
      $conexion->rollback();
      $errores[] = 'No se pudo guardar el producto: '.$e->getMessage();
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Crear producto — Los Lapicitos</title>
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
        <li><a class="active" href="/libreria_lapicito/admin/productos/crear.php"><strong>Crear producto</strong></a></li>
      </ul>
    </aside>

    
    <main class="prod-main">
      <div class="prod-card">
        <div class="prod-head">
          <h5>Nuevo producto</h5>
        </div>

        <?php if ($ok): ?>
          <div class="lp-card" style="background:#fff;border-color:#cfe8d1;color:#215a2b">
             Producto creado correctamente.
          </div>
        <?php endif; ?>

        <?php if ($errores): ?>
          <div class="lp-card" style="background:#fff;border-color:#e7b1b1;color:#8a1f1f">
            <?php foreach($errores as $e): ?>
              <div>• <?= h($e) ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="post" class="form-grid" autocomplete="off">
          
          <div>
            <label for="codigo">Código *</label>
            <input id="codigo" type="text" name="codigo" value="<?= h($codigo) ?>" required>

            <label for="nombre">Nombre *</label>
            <input id="nombre" type="text" name="nombre" value="<?= h($nombre) ?>" required>

            <label for="id_subcategoria">Subcategoría *</label>
            <select id="id_subcategoria" name="id_subcategoria" required>
              <option value="0">Seleccioná…</option>
              <?php foreach($subcats as $s): ?>
                <option value="<?= (int)$s['id_subcategoria'] ?>" <?= $id_subcategoria===(int)$s['id_subcategoria']?'selected':'' ?>>
                  <?= h($s['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>

            <label for="id_proveedor">Proveedor *</label>
            <select id="id_proveedor" name="id_proveedor" required>
              <option value="0">Seleccioná…</option>
              <?php foreach($proveedores as $p): ?>
                <option value="<?= (int)$p['id_proveedor'] ?>" <?= $id_proveedor===(int)$p['id_proveedor']?'selected':'' ?>>
                  <?= h($p['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>

            <label for="ubicacion">Ubicación</label>
            <input id="ubicacion" type="text" name="ubicacion" value="<?= h($ubicacion) ?>" placeholder="Ej: A1-03">
          </div>

          <div>
            <div class="row">
              <div class="six columns">
                <label for="precio_compra">Precio compra</label>
                <input id="precio_compra" type="number" step="0.01" min="0" name="precio_compra" value="<?= h($precio_compra) ?>" placeholder="0,00">
              </div>
              <div class="six columns">
                <label for="precio_venta">Precio venta</label>
                <input id="precio_venta" type="number" step="0.01" min="0" name="precio_venta" value="<?= h($precio_venta) ?>" placeholder="0,00">
              </div>
            </div>

            <label style="display:flex;align-items:center;gap:8px;margin-top:8px">
              <input type="checkbox" name="activo" value="1" <?= $activo? 'checked':'' ?>>
              Activo
            </label>

            <hr>

            <label style="display:flex;align-items:center;gap:8px">
              <input type="checkbox" name="crear_inventario" value="1" <?= $crear_inventario? 'checked':'' ?> id="chkInv">
              Crear inventario inicial
            </label>

            <div id="invBlock" class="<?= $crear_inventario? '':'is-hidden' ?>">
              <label for="id_sucursal">Sucursal</label>
              <select id="id_sucursal" name="id_sucursal">
                <option value="0">Seleccioná…</option>
                <?php foreach($sucursales as $s): ?>
                  <option value="<?= (int)$s['id_sucursal'] ?>" <?= $id_sucursal===(int)$s['id_sucursal']?'selected':'' ?>>
                    <?= h($s['nombre']) ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <div class="row">
                <div class="six columns">
                  <label for="stock_minimo">Stock mínimo</label>
                  <input id="stock_minimo" type="number" min="0" name="stock_minimo" value="<?= (int)$stock_minimo ?>">
                </div>
                <div class="six columns">
                  <label for="stock_inicial">Stock inicial</label>
                  <input id="stock_inicial" type="number" min="0" name="stock_inicial" value="<?= (int)$stock_inicial ?>">
                </div>
              </div>
            </div>
          </div>

          <div class="form-actions">
            <a class="button button-outline" href="/libreria_lapicito/admin/productos/">Cancelar</a>
            <button class="lp-btn" type="submit">Guardar producto</button>
          </div>
        </form>
      </div>
    </main>
  </div>

  <script>
    const chk = document.getElementById('chkInv');
    const blk = document.getElementById('invBlock');
    if (chk) chk.addEventListener('change',()=> {
      if (chk.checked) blk.classList.remove('is-hidden');
      else blk.classList.add('is-hidden');
    });
  </script>
</body>
</html>
