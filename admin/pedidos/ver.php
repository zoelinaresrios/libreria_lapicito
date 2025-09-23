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
require_perm('pedidos.ver');

if (session_status()===PHP_SESSION_NONE) session_start();
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$id = (int)($_GET['id'] ?? 0);
if($id<=0){ header('Location: /admin/pedidos/'); exit; }

$sql="SELECT p.*, pr.nombre AS proveedor, s.nombre AS sucursal, u.nombre AS usuario,
             e.nombre_estado
      FROM pedido p
      JOIN proveedor pr ON pr.id_proveedor=p.id_proveedor
      JOIN sucursal  s  ON s.id_sucursal=p.id_sucursal
      LEFT JOIN usuario  u ON u.id_usuario=p.id_usuario
      LEFT JOIN estado_pedido e ON e.id_estado_pedido=p.id_estado_pedido
      WHERE p.id_pedido=?";
$st=$conexion->prepare($sql); $st->bind_param('i',$id); $st->execute();
$pedido=$st->get_result()->fetch_assoc(); $st->close();
if(!$pedido){ $_SESSION['flash_err']='Pedido inexistente.'; header('Location:/admin/pedidos/'); exit; }

$det=$conexion->prepare("SELECT d.*, pr.nombre AS producto
                         FROM pedido_detalle d
                         JOIN producto pr ON pr.id_producto=d.id_producto
                         WHERE d.id_pedido=?");
$det->bind_param('i',$id); $det->execute();
$items=$det->get_result()->fetch_all(MYSQLI_ASSOC); $det->close();

// Acciones (POST o GET do=)
$accion = $_POST['accion'] ?? ($_GET['do'] ?? '');
if($accion){
  try{
    $permitido = [
      'aprobar' => [1], 'enviar'=>[2], 'recibir'=>[3], 'cancelar'=>[1,2,3]
    ];
    $estado_actual = (int)$pedido['id_estado_pedido'];
    if(!isset($permitido[$accion]) || !in_array($estado_actual,$permitido[$accion],true)){
      throw new Exception('Acción no permitida para el estado actual.');
    }

    $conexion->begin_transaction();

    if($accion==='aprobar' && can('pedidos.aprobar')){
      $nuevo=2;
      $up=$conexion->prepare("UPDATE pedido SET id_estado_pedido=?, fecha_estado=NOW() WHERE id_pedido=?");
      $up->bind_param('ii',$nuevo,$id); $up->execute();
      $_SESSION['flash_ok']="Pedido #$id aprobado.";
    }
    elseif($accion==='enviar' && can('pedidos.enviar')){
      $nuevo=3;
      $up=$conexion->prepare("UPDATE pedido SET id_estado_pedido=?, fecha_estado=NOW() WHERE id_pedido=?");
      $up->bind_param('ii',$nuevo,$id); $up->execute();
      $_SESSION['flash_ok']="Pedido #$id enviado.";
    }
    elseif($accion==='recibir' && can('pedidos.recibir')){
      $nuevo=4; $id_sucursal=(int)$pedido['id_sucursal'];

      $it=$conexion->prepare("SELECT id_producto, cantidad_solicitada FROM pedido_detalle WHERE id_pedido=?");
      $it->bind_param('i',$id); $it->execute(); $rs=$it->get_result();
      while($row=$rs->fetch_assoc()){
        $pid=(int)$row['id_producto']; $cant=(int)$row['cantidad_solicitada'];

        $sel=$conexion->prepare("SELECT id_inventario, stock_actual FROM inventario WHERE id_sucursal=? AND id_producto=? LIMIT 1");
        $sel->bind_param('ii',$id_sucursal,$pid); $sel->execute();
        $inv=$sel->get_result()->fetch_assoc();
        if($inv){
          $nuevo_stock=(int)$inv['stock_actual']+$cant;
          $upd=$conexion->prepare("UPDATE inventario SET stock_actual=?, actualizado_en=NOW() WHERE id_inventario=?");
          $upd->bind_param('ii',$nuevo_stock,$inv['id_inventario']); $upd->execute();
        } else {
          $stock_min=0; $ubicacion='';
          $ins=$conexion->prepare("INSERT INTO inventario (id_sucursal,id_producto,stock_actual,stock_minimo,ubicacion,actualizado_en)
                                   VALUES (?,?,?,?,?,NOW())");
          $ins->bind_param('iiiis',$id_sucursal,$pid,$cant,$stock_min,$ubicacion); $ins->execute();
        }
      }

      $up=$conexion->prepare("UPDATE pedido SET id_estado_pedido=?, fecha_estado=NOW() WHERE id_pedido=?");
      $up->bind_param('ii',$nuevo,$id); $up->execute();
      $_SESSION['flash_ok']="Pedido #$id recibido y stock actualizado.";
    }
    elseif($accion==='cancelar' && can('pedidos.cancelar')){
      $nuevo=5;
      $up=$conexion->prepare("UPDATE pedido SET id_estado_pedido=?, fecha_estado=NOW() WHERE id_pedido=?");
      $up->bind_param('ii',$nuevo,$id); $up->execute();
      $_SESSION['flash_ok']="Pedido #$id cancelado.";
    } else {
      throw new Exception('Permisos insuficientes.');
    }

    $conexion->commit();
  }catch(Exception $e){
    if($conexion->errno) $conexion->rollback();
    $_SESSION['flash_err']=$e->getMessage();
  }
  header("Location: /admin/pedidos/ver.php?id=".$id); exit;
}

$total=0.0; foreach($items as $it){ $total += (float)$it['cantidad_solicitada']*(float)$it['precio_unitario']; }
?>
<!doctype html><html lang="es"><head>
<meta charset="utf-8"><title>Pedido #<?= (int)$pedido['id_pedido'] ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/vendor/normalize.css">
<link rel="stylesheet" href="/vendor/skeleton.css">
<link rel="stylesheet" href="/css/style.css?v=13">
<link rel="stylesheet" href="/css/pedidos.css?v=2">
<link rel="stylesheet" href="/css/toast.css?v=1">
</head>
<body>
<div class="barra"></div>
<div class="prod-shell">
  <aside class="prod-side">
    <ul class="prod-nav"><li><a href="/admin/pedidos/">← Volver</a></li></ul>
  </aside>

  <main class="prod-main">
    <div class="inv-title">Pedido #<?= (int)$pedido['id_pedido'] ?></div>

    <div class="prod-card">
      <div class="grid2">
        <div><strong>Proveedor:</strong> <?=h($pedido['proveedor'])?></div>
        <div><strong>Sucursal:</strong> <?=h($pedido['sucursal'])?></div>
        <div><strong>Estado:</strong> <?=h($pedido['nombre_estado']??'—')?> (<?= (int)$pedido['id_estado_pedido']?>)</div>
        <div><strong>Creado:</strong> <?=h($pedido['fecha_creado'])?></div>
        <div><strong>Fecha estado:</strong> <?=h($pedido['fecha_estado'])?></div>
        <div><strong>Usuario:</strong> <?=h($pedido['usuario']??'—')?></div>
        <div style="grid-column:1/-1"><strong>Observación:</strong> <?=h($pedido['observacion']??'')?></div>
      </div>
    </div>

    <div class="prod-card">
      <div class="prod-head"><h5>Renglones</h5></div>
      <div class="table-wrap">
        <table class="u-full-width">
          <thead><tr><th>Producto</th><th class="ta-r">Cantidad</th><th class="ta-r">Precio</th><th class="ta-r">Subtotal</th></tr></thead>
          <tbody>
            <?php foreach($items as $it): $sub=(float)$it['cantidad_solicitada']*(float)$it['precio_unitario']; ?>
              <tr>
                <td><?=h($it['producto'])?></td>
                <td class="ta-r"><?= (int)$it['cantidad_solicitada']?></td>
                <td class="ta-r">$<?= number_format((float)$it['precio_unitario'],2,',','.')?></td>
                <td class="ta-r">$<?= number_format($sub,2,',','.')?></td>
              </tr>
            <?php endforeach; ?>
            <?php if(empty($items)): ?><tr><td colspan="4" class="muted">Sin detalle.</td></tr><?php endif; ?>
          </tbody>
          <tfoot><tr><th colspan="3" class="ta-r">Total</th><th class="ta-r">$<?= number_format($total,2,',','.')?></th></tr></tfoot>
        </table>
      </div>
    </div>

    <div class="prod-card">
      <div class="prod-head"><h5>Acciones</h5></div>
      <form method="post" style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if($pedido['id_estado_pedido']==1 && can('pedidos.aprobar')): ?>
          <button name="accion" value="aprobar" class="btn-filter">Aprobar</button>
        <?php endif; ?>
        <?php if($pedido['id_estado_pedido']==2 && can('pedidos.enviar')): ?>
          <button name="accion" value="enviar" class="btn-filter">Enviar</button>
        <?php endif; ?>
        <?php if($pedido['id_estado_pedido']==3 && can('pedidos.recibir')): ?>
          <button name="accion" value="recibir" class="btn-filter">Recibir</button>
        <?php endif; ?>
        <?php if(in_array((int)$pedido['id_estado_pedido'],[1,2,3],true) && can('pedidos.cancelar')): ?>
          <button name="accion" value="cancelar" class="btn-sm">Cancelar</button>
        <?php endif; ?>
      </form>

      
    </div>
  </main>
</div>

<?php
$FLASH_OK  = $_SESSION['flash_ok']  ?? '';
$FLASH_ERR = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);
?>
<script>window.__FLASH__={ok:<?=json_encode($FLASH_OK,JSON_UNESCAPED_UNICODE)?>,err:<?=json_encode($FLASH_ERR,JSON_UNESCAPED_UNICODE)?>};</script>
<script src="/js/toast.js?v=1"></script>
</body></html>
