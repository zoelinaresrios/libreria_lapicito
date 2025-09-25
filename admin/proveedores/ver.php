<?php
// /admin/proveedores/ver.php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';

$HAS_ACL = file_exists(__DIR__ . '/../includes/acl.php');
if ($HAS_ACL) { require_once __DIR__ . '/../includes/acl.php'; }
else {
  if (session_status()===PHP_SESSION_NONE) session_start();
  if (!function_exists('can')) { function can($k){ return true; } }
  if (!function_exists('require_perm')) { function require_perm($k){ return true; } }
}
require_perm('proveedores.ver');

if (session_status()===PHP_SESSION_NONE) session_start();
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$id=(int)($_GET['id']??0);
if($id<=0){ header('Location: /admin/proveedores/'); exit; }

$st=$conexion->prepare("SELECT * FROM proveedor WHERE id_proveedor=?");
$st->bind_param('i',$id); $st->execute(); $prov=$st->get_result()->fetch_assoc(); $st->close();
if(!$prov){ $_SESSION['flash_err']='Proveedor inexistente.'; header('Location: /admin/proveedores/'); exit; }

// Totales compras históricas
$tot=$conexion->prepare("SELECT COALESCE(SUM(pd.cantidad_solicitada*pd.precio_unitario),0) total,
                                COUNT(DISTINCT p.id_pedido) pedidos
                         FROM pedido p
                         JOIN pedido_detalle pd ON pd.id_pedido=p.id_pedido
                         WHERE p.id_proveedor=?");
$tot->bind_param('i',$id); $tot->execute(); $agg=$tot->get_result()->fetch_assoc(); $tot->close();

// Últimos pedidos
$list=$conexion->prepare("SELECT p.id_pedido, p.fecha_creado,
                                 COALESCE(SUM(pd.cantidad_solicitada*pd.precio_unitario),0) monto
                          FROM pedido p
                          LEFT JOIN pedido_detalle pd ON pd.id_pedido=p.id_pedido
                          WHERE p.id_proveedor=?
                          GROUP BY p.id_pedido, p.fecha_creado
                          ORDER BY p.fecha_creado DESC
                          LIMIT 20");
$list->bind_param('i',$id); $list->execute(); $rows=$list->get_result()->fetch_all(MYSQLI_ASSOC); $list->close();
?>
<!doctype html><html lang="es"><head>
<meta charset="utf-8"><title>Proveedor #<?= (int)$id ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/vendor/normalize.css">
<link rel="stylesheet" href="/vendor/skeleton.css">
<link rel="stylesheet" href="/css/style.css?v=13">
<link rel="stylesheet" href="/css/toast.css?v=1">
</head>
<body>
<div class="barra"></div>
<div class="prod-shell">
  <aside class="prod-side">
    <ul class="prod-nav">
      <li><a href="/admin/proveedores/">← Volver</a></li>
    </ul>
  </aside>

  <main class="prod-main">
    <div class="inv-title">Proveedor — <?=h($prov['nombre'])?></div>

    <div class="row">
      <div class="six columns">
        <div class="prod-card">
          <div class="prod-head"><h5>Datos de contacto</h5></div>
          <p><b>Contacto:</b> <?=h($prov['contacto_referencia'])?:'—'?></p>
          <p><b>Email:</b> <?=h($prov['email'])?:'—'?></p>
          <p><b>Teléfono:</b> <?=h($prov['telefono'])?:'—'?></p>
          <p><b>Dirección:</b> <?=h($prov['direccion'])?:'—'?></p>
          <?php if (can('proveedores.editar')): ?>
            <a class="btn-sm" href="/admin/proveedores/editar.php?id=<?=$prov['id_proveedor']?>">Editar</a>
          <?php endif; ?>
        </div>
      </div>

      <div class="six columns">
        <div class="prod-card">
          <div class="prod-head"><h5>Resumen de compras</h5></div>
          <div class="row">
            <div class="six columns"><b>Pedidos:</b> <?= (int)($agg['pedidos']??0) ?></div>
            <div class="six columns"><b>Total comprado:</b> $<?= number_format((float)($agg['total']??0),2,',','.') ?></div>
          </div>
        </div>
      </div>
    </div>

    <div class="prod-card">
      <div class="prod-head"><h5>Últimos pedidos</h5></div>
      <div class="table-wrap">
        <table class="u-full-width">
          <thead><tr><th>ID</th><th>Fecha</th><th>Monto</th><th></th></tr></thead>
