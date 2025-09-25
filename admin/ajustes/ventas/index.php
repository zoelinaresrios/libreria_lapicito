<?php
// /admin/ventas/index.php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';

$HAS_ACL = file_exists(__DIR__ . '/../includes/acl.php');
if ($HAS_ACL) { require_once __DIR__ . '/../includes/acl.php'; }
else {
  if (session_status()===PHP_SESSION_NONE) session_start();
  if (!function_exists('can')) { function can($k){ return true; } }
  if (!function_exists('require_perm')) { function require_perm($k){ return true; } }
}
require_perm('ventas.rapidas');

if (function_exists('is_logged') && !is_logged()) { header('Location: /admin/login.php'); exit; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (session_status()===PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }

$id_usuario  = (int)($_SESSION['user']['id_usuario'] ?? 0);
$id_sucursal = (int)($_SESSION['user']['id_sucursal'] ?? 1);

// carrito
if (!isset($_SESSION['pos_cart'])) $_SESSION['pos_cart'] = [];  // [id => ['id','nombre','precio','cant','stock']]

$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($action,['add','set','del'],true)) {
  if (!hash_equals($csrf, $_POST['csrf'] ?? '')) { http_response_code(400); exit('CSRF'); }

  if ($action==='add') {
    $idp=(int)($_POST['id_producto']??0);
    $nombre=trim($_POST['nombre']??'');
    $precio=(float)($_POST['precio']??0);
    $stock =(int)($_POST['stock']??0);
    if ($idp>0 && $nombre!=='') {
      if (!isset($_SESSION['pos_cart'][$idp])) {
        $_SESSION['pos_cart'][$idp]=['id'=>$idp,'nombre'=>$nombre,'precio'=>$precio,'cant'=>1,'stock'=>$stock];
      } else { $_SESSION['pos_cart'][$idp]['cant']++; }
    }
  } elseif ($action==='set') {
    $idp=(int)($_POST['id_producto']??0);
    $cant=max(0,(int)($_POST['cant']??1));
    if (isset($_SESSION['pos_cart'][$idp])) {
      if ($cant===0) unset($_SESSION['pos_cart'][$idp]);
      else $_SESSION['pos_cart'][$idp]['cant']=$cant;
    }
  } else { // del
    $idp=(int)($_POST['id_producto']??0);
    unset($_SESSION['pos_cart'][$idp]);
  }
  header('Location: /admin/ventas/'); exit;
}

$items = array_values($_SESSION['pos_cart']);
$total = 0.0; foreach($items as $it){ $total += ($it['precio']*$it['cant']); }
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Panel administrativo — Ventas</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/vendor/normalize.css?v=2">
  <link rel="stylesheet" href="/vendor/skeleton.css?v=3">
  <link rel="stylesheet" href="/css/venta.css?v=13">

  <link rel="stylesheet" href="/css/style.css?v=13">

</head>
<body>
  <div class="barra"></div>

  <div class="prod-shell">
    <aside class="prod-side">
      <ul class="prod-nav">
        <li><a href="/admin/index.php">inicio</a></li>
       
        <li><a href="/admin/productos/">Productos</a></li>
        <li><a href="/admin/categorias/">categorias</a></li>
       <li><a  href="/admin/subcategorias/">subcategorias</a></li>
        <li><a href="/admin/inventario/">Inventario</a></li>
        <li><a href="/admin/pedidos/">Pedidos</a></li>
        <li><a href="/admin/proveedores/">Proveedores</a></li>
          <li><a href="/admin/sucursales/">sucursales</a></li>
        <li><a href="/admin/alertas/">Alertas</a></li>
        <li><a href="/admin/reportes/">Reportes y estadisticas</a></li>
        <li><a class="active" href="/admin/ventas/">Ventas</a></li>
        <li><a   href="/admin/usuarios/">Usuarios</a></li>
        <li><a href="/admin/roles/">Roles y permisos</a></li>
        <li><a href="/admin/ajustes/">Ajustes</a></li>
         <li><a href="/admin/ajustes/">Audutorias</a></li>
        <li><a href="/admin/logout.php">Salir</a></li>
      </ul>
    </aside>

    <main class="prod-main">
      <div class="inv-title">Panel administrativo — Ventas</div>

      <div class="pos-panel">
        <!-- fila 2 columnas -->
        <div class="pos-grid">
          <!-- Buscador -->
          <section class="pos-card">
            <div class="pos-head"><h5>Búsqueda de productos</h5></div>
            <div class="pos-search">
              <input id="q" class="input-search" type="text" placeholder="Buscar por nombre o código…">
              <button class="btn-green" id="btnBuscar">BUSCAR</button>
            </div>
            <div class="pos-results" id="resList"></div>

            <div class="pos-quick">
              <a class="btn-line" href="/admin/ventas/historial.php">Historial</a>
              <a class="btn-line" href="/admin/ventas/cierre.php">Cierre diario</a>
            </div>
          </section>

          <!-- Carrito -->
          <section class="pos-card">
            <div class="pos-head"><h5>Carrito</h5></div>
            <div class="table-wrap">
              <table class="u-full-width pos-table">
                <thead>
                  <tr>
                    <th>Producto</th>
                    <th style="width:120px">Precio</th>
                    <th style="width:150px">Cantidad</th>
                    <th style="width:130px">Subtotal</th>
                    <th style="width:80px">Quitar</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($items)): ?>
                    <tr><td colspan="5" class="muted">Aún no hay ítems.</td></tr>
                  <?php else: foreach($items as $it): ?>
                    <tr>
                      <td>
                        <div class="item-name"><?= h($it['nombre']) ?></div>
                        <?php if ((int)$it['stock']<=0): ?>
                          <span class="badge ss">Sin stock</span>
                        <?php elseif ((int)$it['stock']<$it['cant']): ?>
                          <span class="badge ss">Stock insuficiente (<?= (int)$it['stock'] ?>)</span>
                        <?php else: ?>
                          <span class="badge-ok">Stock OK (<?= (int)$it['stock'] ?>)</span>
                        <?php endif; ?>
                      </td>
                      <td>$ <?= number_format($it['precio'],2,',','.') ?></td>
                      <td>
                        <form method="post" class="u-inline pos-qty">
                          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                          <input type="hidden" name="action" value="set">
                          <input type="hidden" name="id_producto" value="<?= (int)$it['id'] ?>">
                          <input class="qty" type="number" min="0" name="cant" value="<?= (int)$it['cant'] ?>">
                          <button class="btn-line">OK</button>
                        </form>
                      </td>
                      <td>$ <?= number_format($it['precio']*$it['cant'],2,',','.') ?></td>
                      <td>
                        <form method="post" class="u-inline" onsubmit="return confirm('Quitar ítem?')">
                          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                          <input type="hidden" name="action" value="del">
                          <input type="hidden" name="id_producto" value="<?= (int)$it['id'] ?>">
                          <button class="btn-red">x</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>

            <div class="pos-total">
              <div>Total:</div>
              <div class="pos-total-val">$ <?= number_format($total,2,',','.') ?></div>
            </div>

            <form method="post" action="/admin/ventas/finalizar.php" onsubmit="return confirm('¿Confirmás registrar la venta?')">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="id_sucursal" value="<?= (int)$id_sucursal ?>">
              <input class="pos-ob" type="text" name="observacion" placeholder="Observación (opcional)">
              <div class="pos-actions">
                <button class="btn-green" <?= empty($items)?'disabled':''; ?>>FINALIZAR VENTA</button>
              </div>
            </form>
          </section>
        </div>
      </div>
    </main>
  </div>

<script>
const $ = s=>document.querySelector(s);
const list = $('#resList');
$('#btnBuscar').onclick = doSearch;
$('#q').addEventListener('keydown', e=>{ if(e.key==='Enter'){ e.preventDefault(); doSearch(); }});

function doSearch(){
  const q = $('#q').value.trim();
  if(!q){ list.innerHTML='<div class="muted">Ingresá un término.</div>'; return; }
  list.innerHTML = '<div class="muted">Buscando…</div>';
  fetch('/admin/ventas/buscar.php?q='+encodeURIComponent(q))
    .then(r=>r.json())
    .then(js=>{
      list.innerHTML='';
      if(!js || js.length===0){ list.innerHTML='<div class="muted">Sin resultados</div>'; return; }
      js.forEach(p=>{
        const row=document.createElement('div');
        row.className='res-item';
        row.innerHTML =
          '<div class="res-info"><div class="res-n">'+escapeHtml(p.nombre)+'</div>'+
          '<div class="res-m">Cod: '+(p.codigo||'-')+' · Stock: '+p.stock+' · $ '+p.precio.toFixed(2).replace(".",",")+'</div></div>'+
          '<form method="post" class="res-add">'+
            '<input type="hidden" name="csrf" value="<?= h($csrf) ?>">'+
            '<input type="hidden" name="action" value="add">'+
            '<input type="hidden" name="id_producto" value="'+p.id+'">'+
            '<input type="hidden" name="nombre" value="'+escapeHtml(p.nombre)+'">'+
            '<input type="hidden" name="precio" value="'+p.precio+'">'+
            '<input type="hidden" name="stock" value="'+p.stock+'">'+
            '<button class="btn-green">Agregar</button>'+
          '</form>';
        list.appendChild(row);
      });
    })
    .catch(_=>{ list.innerHTML='<div class="muted">Error al buscar</div>'; });
}
function escapeHtml(s){ return s.replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }
</script>
</body>
</html>
