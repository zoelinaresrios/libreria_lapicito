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
require_perm('pedidos.crear');

if (session_status()===PHP_SESSION_NONE) session_start();
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Catálogos
$proveedores = $conexion->query("SELECT id_proveedor, nombre FROM proveedor ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);
$productos   = $conexion->query("SELECT id_producto, nombre, precio_compra FROM producto ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);
$sucursales  = $conexion->query("SELECT id_sucursal, nombre FROM sucursal ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);

// Guardar
if($_SERVER['REQUEST_METHOD']==='POST'){
  try{
    $id_proveedor=(int)($_POST['id_proveedor']??0);
    $id_sucursal =(int)($_POST['id_sucursal']??0);
    $obs=trim($_POST['observacion']??'');
    $ids = $_POST['prod_id'] ?? [];
    $cans= $_POST['cant'] ?? [];
    $pres= $_POST['precio'] ?? [];

    if($id_proveedor<=0 || $id_sucursal<=0) throw new Exception('Proveedor y sucursal son obligatorios.');
    if(empty($ids)) throw new Exception('Agregá al menos un renglón.');

    $det=[];
    for($i=0;$i<count($ids);$i++){
      $pid=(int)$ids[$i]; $c=(int)$cans[$i]; $pu=(float)str_replace(',','.',(string)$pres[$i]);
      if($pid>0 && $c>0 && $pu>=0) $det[]=[$pid,$c,$pu];
    }
    if(empty($det)) throw new Exception('Revisá cantidades y precios.');

    $conexion->begin_transaction();
    $id_usuario = isset($_SESSION['user']['id_usuario'])?(int)$_SESSION['user']['id_usuario']:null;
    $estado_borrador=1;
    $ins=$conexion->prepare("INSERT INTO pedido (id_proveedor,id_sucursal,id_usuario,id_estado_pedido,fecha_creado,fecha_estado,observacion)
                             VALUES (?,?,?,?,NOW(),NOW(),?)");
    $ins->bind_param('iiiss',$id_proveedor,$id_sucursal,$id_usuario,$estado_borrador,$obs);
    $ins->execute();
    $id_pedido=(int)$conexion->insert_id;

    $d=$conexion->prepare("INSERT INTO pedido_detalle (id_pedido,id_producto,cantidad_solicitada,precio_unitario) VALUES (?,?,?,?)");
    foreach($det as [$pid,$c,$pu]){ $d->bind_param('iiid',$id_pedido,$pid,$c,$pu); $d->execute(); }

    $conexion->commit();
    $_SESSION['flash_ok']="Pedido #$id_pedido creado.";
    header("Location: /admin/pedidos/ver.php?id=".$id_pedido); exit;

  }catch(Exception $e){
    if($conexion->errno) $conexion->rollback();
    $_SESSION['flash_err']=$e->getMessage();
    header("Location: /admin/pedidos/crear.php"); exit;
  }
}
?>
<!doctype html><html lang="es"><head>
<meta charset="utf-8"><title>Nuevo pedido</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/vendor/normalize.css">
<link rel="stylesheet" href="/vendor/skeleton.css">
<link rel="stylesheet" href="/css/style.css?v=13">
<link rel="stylesheet" href="/css/pedidos.css?v=2">
<link rel="stylesheet" href="/css/toast.css?v=1">
<script>
function addRow(prefId='',prefCant='',prefPrecio=''){
  const tpl = document.getElementById('row-tpl').content.cloneNode(true);
  if(prefId) tpl.querySelector('select[name="prod_id[]"]').value=prefId;
  if(prefCant) tpl.querySelector('input[name="cant[]"]').value=prefCant;
  if(prefPrecio) tpl.querySelector('input[name="precio[]"]').value=prefPrecio;
  document.getElementById('rows-body').appendChild(tpl);
}
function rmRow(btn){ btn.closest('tr').remove(); }
document.addEventListener('DOMContentLoaded', ()=>{ addRow(); });
</script>
</head>
<body>
<div class="barra"></div>
<div class="prod-shell">
  <aside class="prod-side">
    <ul class="prod-nav"><li><a href="/admin/pedidos/">← Volver</a></li></ul>
  </aside>

  <main class="prod-main">
    <div class="inv-title">Nuevo pedido</div>

    <div class="prod-card">
      <form method="post">
        <div class="grid2">
          <label>Proveedor
            <select name="id_proveedor" required>
              <option value="">Seleccionar…</option>
              <?php foreach($proveedores as $p): ?>
                <option value="<?=$p['id_proveedor']?>"><?=h($p['nombre'])?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Sucursal
            <select name="id_sucursal" required>
              <option value="">Seleccionar…</option>
              <?php foreach($sucursales as $s): ?>
                <option value="<?=$s['id_sucursal']?>"><?=h($s['nombre'])?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>

        <label>Observación
          <input type="text" name="observacion" placeholder="Notas (opcional)">
        </label>

        <div class="prod-head" style="margin-top:10px">
          <h5>Renglones</h5>
          <button type="button" class="btn-add" onclick="addRow()">+ Agregar renglón</button>
        </div>

        <div class="table-wrap">
          <table class="u-full-width">
            <thead><tr><th style="width:50%">Producto</th><th>Cantidad</th><th>Precio unit.</th><th>—</th></tr></thead>
            <tbody id="rows-body"></tbody>
          </table>
        </div>

        <div style="margin-top:14px">
          <button class="btn-filter" type="submit">Guardar Borrador</button>
          <a class="btn-sm" href="/admin/pedidos/">Cancelar</a>
        </div>
      </form>
    </div>
  </main>
</div>

<template id="row-tpl">
  <tr>
    <td>
      <select name="prod_id[]" required>
        <option value="">Elegir…</option>
        <?php foreach($productos as $pr): ?>
          <option value="<?=$pr['id_producto']?>"><?=h($pr['nombre'])?></option>
        <?php endforeach; ?>
      </select>
    </td>
    <td><input type="number" name="cant[]" min="1" step="1" required></td>
    <td><input type="number" name="precio[]" min="0" step="0.01" required></td>
    <td><button type="button" class="btn-sm" onclick="rmRow(this)">Quitar</button></td>
  </tr>
</template>

<?php
$FLASH_OK  = $_SESSION['flash_ok']  ?? '';
$FLASH_ERR = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);
?>
<script>window.__FLASH__={ok:<?=json_encode($FLASH_OK,JSON_UNESCAPED_UNICODE)?>,err:<?=json_encode($FLASH_ERR,JSON_UNESCAPED_UNICODE)?>};</script>
<script src="/js/toast.js?v=1"></script>
</body></html>
