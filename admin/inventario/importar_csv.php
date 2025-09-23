<?php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';

$HAS_ACL = file_exists(__DIR__ . '/../includes/acl.php');
if ($HAS_ACL) { require_once __DIR__ . '/../includes/acl.php'; }
else { if (session_status()===PHP_SESSION_NONE) session_start();
  if (!function_exists('can')) { function can($k){ return true; } }
  if (!function_exists('require_perm')) { function require_perm($k){ return true; } }
}
require_perm('inventario.ajustar');

if (session_status()===PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$errors=[]; $ok=false;

if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) $errors[]='Token inválido.';
  $modo = $_POST['modo'] ?? 'minimos'; // minimos | minimos_y_stock

  if(empty($_FILES['file']['tmp_name'])) $errors[]='Subí un archivo CSV.';
  if(!$errors){
    $fh=fopen($_FILES['file']['tmp_name'],'r');
    if(!$fh){ $errors[]='No se pudo leer el archivo.'; }
    else{
      // Cabecera esperada
      $hdr=fgetcsv($fh);
      $expected=['id_producto','nombre','stock_actual','stock_minimo'];
      if(!$hdr || array_map('strtolower',$hdr)!==$expected){
        $errors[]='Cabecera inválida. Esperado: '.implode(',',$expected);
      }else{
        $conexion->begin_transaction();
        try{
          while(($row=fgetcsv($fh))!==false){
            $idp=(int)($row[0] ?? 0);
            $stk=(int)($row[2] ?? 0);
            $min=(int)($row[3] ?? 0);
            if($idp<=0) continue;

            // asegurar fila inventario
            $conexion->query("INSERT IGNORE INTO inventario(id_producto,stock_actual,stock_minimo) VALUES ($idp,0,0)");

            // actualizar mínimo
            $st=$conexion->prepare("UPDATE inventario SET stock_minimo=? WHERE id_producto=?");
            $st->bind_param('ii',$min,$idp); $st->execute(); $st->close();

            if($modo==='minimos_y_stock'){
              $st=$conexion->prepare("UPDATE inventario SET stock_actual=? WHERE id_producto=?");
              $st->bind_param('ii',$stk,$idp); $st->execute(); $st->close();

              // opcional: registrar ajuste como movimiento
              $uid=(int)($_SESSION['user_id'] ?? 0);
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
            
              $st=$conexion->prepare("INSERT INTO inventario_mov (id_producto,tipo,cantidad,motivo,stock_prev,stock_nuevo,id_usuario) VALUES (?,?,?,?,?,?,?)");
              $mot='Importación CSV'; $cant=$stk; $prev=-1; $nuevo=$stk;
              $tipo='ajuste';
              $st->bind_param('isissii',$idp,$tipo,$cant,$mot,$prev,$nuevo,$uid);
              $st->execute(); $st->close();
            }
          }
          $conexion->commit();
          $ok=true;
        }catch(Exception $e){
          $conexion->rollback();
          $errors[]='Error en importación: '.$e->getMessage();
        }
      }
      fclose($fh);
    }
  }
}
?>
<!doctype html><html lang="es"><head>
<meta charset="utf-8"><title>Importar CSV — Inventario</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
 <link rel="stylesheet" href="/vendor/normalize.css?v=2">
<link rel="stylesheet" href="/vendor/skeleton.css?v=3">
<link rel="stylesheet" href="/css/style.css?v=13">
</head><body>
<div class="barra"></div>
<div class="prod-shell">
  <aside class="prod-side">
    <ul class="prod-nav">
      <li><a href="/admin/inventario/">Inventario</a></li>
      <li><a href="/admin/inventario/exportar_csv.php">Exportar CSV</a></li>
      <li><a class="active" href="/admin/inventario/importar_csv.php">Importar CSV</a></li>
    </ul>
  </aside>

  <main class="prod-main">
    <div class="inv-title">Importar CSV</div>

    <div class="prod-card">
      <?php if($ok): ?>
        <div class="alert-ok">Importación realizada con éxito.</div>
      <?php endif; ?>
      <?php if($errors): ?><div class="alert-error"><?php foreach($errors as $e) echo '<div>'.h($e).'</div>'; ?></div><?php endif; ?>

      <p>Formato esperado (misma cabecera que el archivo exportado):</p>
      <pre>id_producto,nombre,stock_actual,stock_minimo</pre>

      <form method="post" enctype="multipart/form-data" class="form-vert">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">

        <label>Archivo CSV</label>
        <input type="file" name="file" accept=".csv" required>

        <label>¿Qué querés actualizar?</label>
        <select name="modo" required>
          <option value="minimos">Sólo mínimos (recomendado)</option>
          <option value="minimos_y_stock">Mínimos y stock (ajusta stock a valor exacto del CSV)</option>
        </select>

        <div class="form-actions">
          <a class="btn-sm btn-muted" href="/admin/inventario/">Cancelar</a>
          <button class="btn-filter" type="submit">Importar</button>
        </div>
      </form>

      <p class="muted">Tip: primero <a href="/admin/inventario/exportar_csv.php">exportá</a> el CSV, editalo y luego volvé a importarlo.</p>
    </div>
  </main>
</div>
</body></html>
