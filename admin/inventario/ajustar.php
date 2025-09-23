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
require_perm('inventario.ajustar');

if (session_status()===PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$idp = (int)($_GET['id'] ?? ($_POST['id_producto'] ?? 0));
if($idp<=0){ header('Location: /libreria_lapicito/admin/inventario/'); exit; }

$st=$conexion->prepare("
  SELECT p.id_producto, p.nombre,
         COALESCE(i.stock_actual,0) AS stock_actual,
         COALESCE(i.stock_minimo,0) AS stock_minimo
  FROM producto p
  LEFT JOIN inventario i ON i.id_producto=p.id_producto
  WHERE p.id_producto=? LIMIT 1
");
$st->bind_param('i',$idp);
$st->execute();
$item=$st->get_result()->fetch_assoc();
$st->close();
if(!$item){ header('Location: /admin/inventario/'); exit; }

$tipo   = $_POST['tipo'] ?? '';
$cant   = (int)($_POST['cantidad'] ?? 0);
$motivo = trim($_POST['motivo'] ?? '');
$newMin = $_POST['nuevo_min'] ?? '';

$errors=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) $errors[]='Token inválido.';
  if(!in_array($tipo,['ingreso','egreso','ajuste'],true)) $errors[]='Tipo inválido.';
  if($cant<=0) $errors[]='La cantidad debe ser > 0.';
  if(mb_strlen($motivo)>200) $errors[]='Motivo demasiado largo.';
  if($newMin!==''){
    if(!ctype_digit($newMin) || (int)$newMin<0) $errors[]='Mínimo inválido.';
  }

  if(!$errors){
    try{
      $conexion->begin_transaction();

      $conexion->query("INSERT IGNORE INTO inventario(id_producto, stock_actual, stock_minimo) VALUES ($idp, 0, 0)");

      $st=$conexion->prepare("SELECT stock_actual, stock_minimo FROM inventario WHERE id_producto=? FOR UPDATE");
      $st->bind_param('i',$idp); $st->execute();
      $inv=$st->get_result()->fetch_assoc(); $st->close();

      $prev=(int)($inv['stock_actual'] ?? 0);
      $nuevo=$prev;
      if($tipo==='ingreso') $nuevo = $prev + $cant;
      elseif($tipo==='egreso') $nuevo = max(0, $prev - $cant);
      elseif($tipo==='ajuste') $nuevo = $cant;

      $st=$conexion->prepare("UPDATE inventario SET stock_actual=? WHERE id_producto=?");
      $st->bind_param('ii',$nuevo,$idp); $st->execute(); $st->close();

      if($newMin!==''){
        $nm=(int)$newMin;
        $st=$conexion->prepare("UPDATE inventario SET stock_minimo=? WHERE id_producto=?");
        $st->bind_param('ii',$nm,$idp); $st->execute(); $st->close();
        $item['stock_minimo']=$nm; 
      }

      $conexion->query("
        CREATE TABLE IF NOT EXISTS inventario_mov (
          id_mov INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          id_producto INT UNSIGNED NOT NULL,
          tipo ENUM('ingreso','egreso','ajuste') NOT NULL,
          cantidad INT NOT NULL,
          motivo VARCHAR(200) DEFAULT NULL,
          stock_prev INT NOT NULL,
          stock_nuevo INT NOT NULL,
          id_usuario INT UNSIGNED DEFAULT NULL,
          creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          KEY (id_producto), KEY (id_usuario)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
      ");
      $uid = (int)($_SESSION['user_id'] ?? 0);
      $st=$conexion->prepare("INSERT INTO inventario_mov (id_producto,tipo,cantidad,motivo,stock_prev,stock_nuevo,id_usuario) VALUES (?,?,?,?,?,?,?)");
      $st->bind_param('isissii',$idp,$tipo,$cant,$motivo,$prev,$nuevo,$uid);
      $st->execute(); $st->close();

      $conexion->commit();
      $_SESSION['flash_ok']='Inventario actualizado.';
      header('Location: /admin/inventario/'); exit;
    }catch(Exception $e){
      $conexion->rollback();
      $errors[]='No se pudo guardar: '.$e->getMessage();
    }
  }
}

$faltan = max(0, (int)$item['stock_minimo'] - (int)$item['stock_actual']);
$sugerido = max(5, $faltan); //ajustar 
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Ajustar inventario — Los Lapicitos</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/vendor/normalize.css?v=2">
<link rel="stylesheet" href="/vendor/skeleton.css?v=3">
<link rel="stylesheet" href="/css/style.css?v=13">
</head>
<body>
  <div class="barra"></div>
  <div class="prod-shell">
    <aside class="prod-side">
      <ul class="prod-nav">
        <li><a href="/admin/inventario/">Inventario</a></li>
      </ul>
    </aside>

    <main class="prod-main">
      <div class="inv-title">Ajustar inventario</div>

      <div class="prod-card">
        <?php if($errors): ?><div class="alert-error"><?php foreach($errors as $e) echo '<div>'.h($e).'</div>'; ?></div><?php endif; ?>

        <p><strong>Producto:</strong> #<?= (int)$item['id_producto'] ?> — <?= h($item['nombre']) ?></p>
        <p>Stock actual: <strong><?= (int)$item['stock_actual'] ?></strong> — Mínimo: <strong><?= (int)$item['stock_minimo'] ?></strong></p>

        <p>
          <?php if($faltan>0): ?>
            Faltan <strong><?= $faltan ?></strong> para alcanzar el mínimo.
            <a class="btn-sm" href="/admin/pedidos/crear.php?id_producto=<?= (int)$item['id_producto'] ?>&sugerido=<?= (int)$sugerido ?>">
              Crear pedido sugerido (<?= (int)$sugerido ?>)
            </a>
          <?php else: ?>
            Stock por encima del mínimo.
          <?php endif; ?>
        </p>

        <form method="post" class="form-vert">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
          <input type="hidden" name="id_producto" value="<?= (int)$idp ?>">

          <label>Tipo de operación</label>
          <select name="tipo" required>
            <option value="">Seleccionar…</option>
            <option value="ingreso" <?= $tipo==='ingreso'?'selected':'' ?>>Ingreso (+)</option>
            <option value="egreso"  <?= $tipo==='egreso'?'selected':''  ?>>Egreso (−)</option>
            <option value="ajuste"  <?= $tipo==='ajuste'?'selected':''  ?>>Ajuste (fijar valor exacto)</option>
          </select>

          <label>Cantidad</label>
          <input class="u-full-width" type="number" name="cantidad" min="1" value="<?= $cant>0?(int)$cant:'' ?>" required>

          <label>Motivo (opcional)</label>
          <input class="u-full-width" type="text" name="motivo" maxlength="200" value="<?= h($motivo) ?>" placeholder="Compra, rotura, conteo, etc.">

          <label>Nuevo mínimo (opcional)</label>
          <input class="u-full-width" type="number" name="nuevo_min" min="0" value="<?= h($newMin) ?>" placeholder="Dejar vacío para no cambiar">

          <div class="form-actions">
            <a class="btn-sm btn-muted" href="/admin/inventario/">Cancelar</a>
            <button class="btn-filter" type="submit">Guardar</button>
          </div>
        </form>
      </div>
    </main>
  </div>
</body>
</html>
