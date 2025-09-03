<?php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';

$HAS_ACL = file_exists(__DIR__ . '/../includes/acl.php');
if ($HAS_ACL) { require_once __DIR__ . '/../includes/acl.php'; }
else {
  if (session_status()===PHP_SESSION_NONE) session_start();
  if (!function_exists('can')) { function can($k){ return true; } }
  if (!function_exists('require_perm')) { function require_perm($k){ return true; } }
}
require_perm('pedidos.crear'); // ajustá al permiso

if (session_status()===PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);


$conexion->query("
  CREATE TABLE IF NOT EXISTS pedido (
    id_pedido INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    estado ENUM('borrador','enviado','recibido','cancelado') NOT NULL DEFAULT 'borrador',
    comentario VARCHAR(255) DEFAULT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$conexion->query("
  CREATE TABLE IF NOT EXISTS pedido_item (
    id_item INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_pedido INT UNSIGNED NOT NULL,
    id_producto INT UNSIGNED NOT NULL,
    cantidad INT NOT NULL,
    precio_unitario DECIMAL(10,2) DEFAULT NULL,
    FOREIGN KEY (id_pedido) REFERENCES pedido(id_pedido) ON DELETE CASCADE,
    KEY (id_producto)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");


$pref_idp = (int)($_GET['id_producto'] ?? 0);
$pref_sug = max(1, (int)($_GET['sugerido'] ?? 1));

// Catálogo de productos 
$prods=[]; 
$r=$conexion->query("SELECT id_producto, nombre FROM producto ORDER BY nombre");
while($row=$r->fetch_assoc()) $prods[]=$row;

$comentario = trim($_POST['comentario'] ?? '');

$ids  = $_POST['id_producto'] ?? [];
$cants= $_POST['cantidad'] ?? [];
$precs= $_POST['precio_unitario'] ?? [];

$errors=[]; $ok=false;

if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) $errors[]='Token inválido.';

  $items=[];
  if(is_array($ids) && is_array($cants)){
    for($i=0; $i<count($ids); $i++){
      $pid = (int)($ids[$i] ?? 0);
      $cant= (int)($cants[$i] ?? 0);
      $ppu = isset($precs[$i]) && $precs[$i]!=='' ? (float)$precs[$i] : null;
      if($pid>0 && $cant>0){
        $items[]=['id_producto'=>$pid,'cantidad'=>$cant,'precio'=>$ppu];
      }
    }
  }
  if(empty($items)) $errors[]='Agregá al menos un ítem con cantidad > 0.';
  if(mb_strlen($comentario)>255) $errors[]='Comentario muy largo.';

  if(!$errors){
    try{
      $conexion->begin_transaction();

      $st=$conexion->prepare("INSERT INTO pedido(estado,comentario) VALUES ('borrador',?)");
      $st->bind_param('s',$comentario);
      $st->execute();
      $id_pedido = (int)$conexion->insert_id;
      $st->close();

      $st=$conexion->prepare("INSERT INTO pedido_item(id_pedido,id_producto,cantidad,precio_unitario) VALUES (?,?,?,?)");
      foreach($items as $it){
        // precio puede ser NULL
        if($it['precio']===null){
          $null = null;
          $st->bind_param('iiid',$id_pedido,$it['id_producto'],$it['cantidad'],$null);
        }else{
          $st->bind_param('iiid',$id_pedido,$it['id_producto'],$it['cantidad'],$it['precio']);
        }
        $st->execute();
      }
      $st->close();

      $conexion->commit();
      $_SESSION['flash_ok']='Pedido creado en estado BORRADOR (#'.$id_pedido.').';
      header('Location: /libreria_lapicito/admin/inventario/'); exit;
    }catch(Exception $e){
      $conexion->rollback();
      $errors[]='No se pudo crear el pedido: '.$e->getMessage();
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Crear pedido — Los Lapicitos</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/8.0.1/normalize.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/skeleton/2.0.4/skeleton.min.css">
  <link rel="stylesheet" href="/libreria_lapicito/css/style.css">
  <script>
    function addRow() {
      const tbody = document.getElementById('items-body');
      const tpl = document.getElementById('row-tpl').content.cloneNode(true);
      tbody.appendChild(tpl);
    }
    function delRow(btn){
      const tr = btn.closest('tr');
      tr.parentNode.removeChild(tr);
    }
  </script>
</head>
<body>
  <div class="barra"></div>
  <div class="prod-shell">
    <aside class="prod-side">
      <ul class="prod-nav">
        <li><a href="/libreria_lapicito/admin/inventario/">Inventario</a></li>
        <li><a class="active" href="/libreria_lapicito/admin/pedidos/crear.php">Nuevo pedido</a></li>
      </ul>
    </aside>

    <main class="prod-main">
      <div class="inv-title">Nuevo pedido (borrador)</div>

      <div class="prod-card">
        <?php if($errors): ?><div class="alert-error"><?php foreach($errors as $e) echo '<div>'.h($e).'</div>'; ?></div><?php endif; ?>

        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">

          <label>Comentario (opcional)</label>
          <input class="u-full-width" type="text" name="comentario" maxlength="255" value="<?= h($comentario) ?>" placeholder="Ej: Pedido al proveedor X">

          <div class="table-wrap">
            <table class="u-full-width">
              <thead>
                <tr>
                  <th style="width:60%">Producto</th>
                  <th style="width:15%">Cantidad</th>
                  <th style="width:15%">P. Unit. (opcional)</th>
                  <th style="width:10%">Acción</th>
                </tr>
              </thead>
              <tbody id="items-body">
                <?php if($pref_idp>0): ?>
                  <tr>
                    <td>
                      <select name="id_producto[]" required>
                        <option value="">Seleccionar…</option>
                        <?php foreach($prods as $p): ?>
                          <option value="<?= (int)$p['id_producto'] ?>" <?= $pref_idp===(int)$p['id_producto']?'selected':'' ?>>
                            #<?= (int)$p['id_producto'] ?> — <?= h($p['nombre']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                    <td><input type="number" name="cantidad[]" min="1" value="<?= (int)$pref_sug ?>" required></td>
                    <td><input type="number" step="0.01" name="precio_unitario[]" placeholder=""></td>
                    <td><button type="button" class="btn-sm btn-muted" onclick="delRow(this)">Quitar</button></td>
                  </tr>
                <?php else: ?>
                  <tr>
                    <td>
                      <select name="id_producto[]" required>
                        <option value="">Seleccionar…</option>
                        <?php foreach($prods as $p): ?>
                          <option value="<?= (int)$p['id_producto'] ?>">#<?= (int)$p['id_producto'] ?> — <?= h($p['nombre']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                    <td><input type="number" name="cantidad[]" min="1" value="1" required></td>
                    <td><input type="number" step="0.01" name="precio_unitario[]" placeholder=""></td>
                    <td><button type="button" class="btn-sm btn-muted" onclick="delRow(this)">Quitar</button></td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="form-actions">
            <button type="button" class="btn-sm" onclick="addRow()">+ Agregar ítem</button>
            <a class="btn-sm btn-muted" href="/libreria_lapicito/admin/inventario/">Cancelar</a>
            <button class="btn-filter" type="submit">Crear pedido</button>
          </div>
        </form>

        
        <template id="row-tpl">
          <tr>
            <td>
              <select name="id_producto[]" required>
                <option value="">Seleccionar…</option>
                <?php foreach($prods as $p): ?>
                  <option value="<?= (int)$p['id_producto'] ?>">#<?= (int)$p['id_producto'] ?> — <?= h($p['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input type="number" name="cantidad[]" min="1" value="1" required></td>
            <td><input type="number" step="0.01" name="precio_unitario[]" placeholder=""></td>
            <td><button type="button" class="btn-sm btn-muted" onclick="delRow(this)">Quitar</button></td>
          </tr>
        </template>
      </div>
    </main>
  </div>
</body>
</html>
