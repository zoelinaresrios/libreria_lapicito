<?php

include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/config.php'; 
require_once __DIR__ . '/../../includes/mailer.php'; 

$HAS_ACL = file_exists(__DIR__ . '/../includes/acl.php');
if ($HAS_ACL) { require_once __DIR__ . '/../includes/acl.php'; }
else {
  if (session_status()===PHP_SESSION_NONE) session_start();
  if (!function_exists('can')) { function can($k){ return true; } }
  if (!function_exists('require_perm')) { function require_perm($k){ return true; } }
}
require_perm('alertas.generar');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (session_status()===PHP_SESSION_NONE) session_start();

if (php_sapi_name() !== 'cli' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  if (empty($_POST['csrf']) || empty($_SESSION['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) {
    http_response_code(400);
    exit('CSRF inválido');
  }
}

$diasSinVentas = max(1, (int)($_POST['dias_sin_ventas'] ?? $_GET['dias'] ?? 30)); //  30 días

function insertar_alerta_si_no_existe($conexion, $idInventario, $idProducto, $tipo) {
  
  $chk = $conexion->prepare("SELECT 1 FROM alerta WHERE id_inventario=? AND id_tipo_alerta=? AND atendida=0 LIMIT 1");
  $chk->bind_param('ii', $idInventario, $tipo);
  $chk->execute();
  $ya = $chk->get_result()->fetch_row();
  $chk->close();

  if ($ya) return false;

  $ins = $conexion->prepare(
    "INSERT INTO alerta (id_producto, id_inventario, id_tipo_alerta, atendida, fecha_creada)
     VALUES (?, ?, ?, 0, NOW())"
  );
  $ins->bind_param('iii', $idProducto, $idInventario, $tipo);
  $ins->execute();
  return true;
}

$conexion->begin_transaction();
try {
  $stats = ['sb'=>0,'ss'=>0,'nv'=>0]; // contadores

  // STOCK BAJO 
  $sqlSB = "SELECT i.id_inventario, i.id_producto
              FROM inventario i
             WHERE i.stock_actual > 0
               AND i.stock_actual <= i.stock_minimo";
  $res = $conexion->query($sqlSB);
  while ($r = $res->fetch_assoc()) {
    if (insertar_alerta_si_no_existe($conexion, (int)$r['id_inventario'], (int)$r['id_producto'], 1)) {
      $stats['sb']++;
    }
  }

  // SIN STOCK 
  $sqlSS = "SELECT i.id_inventario, i.id_producto
              FROM inventario i
             WHERE i.stock_actual = 0";
  $res = $conexion->query($sqlSS);
  while ($r = $res->fetch_assoc()) {
    if (insertar_alerta_si_no_existe($conexion, (int)$r['id_inventario'], (int)$r['id_producto'], 2)) {
      $stats['ss']++;
    }
  }

  // SIN VENTAS 
  $sqlNV = "
    SELECT DISTINCT p.id_producto, i.id_inventario
      FROM producto p
      JOIN inventario i ON i.id_producto = p.id_producto
     WHERE p.activo = 1
       AND NOT EXISTS (
         SELECT 1
           FROM venta_detalle vd
           JOIN venta v ON v.id_venta = vd.id_venta
          WHERE vd.id_producto = p.id_producto
            AND v.fecha_hora >= DATE_SUB(NOW(), INTERVAL ? DAY)
       )";
  $st = $conexion->prepare($sqlNV);
  $st->bind_param('i', $diasSinVentas);
  $st->execute();
  $res = $st->get_result();
  while ($r = $res->fetch_assoc()) {
    if (insertar_alerta_si_no_existe($conexion, (int)$r['id_inventario'], (int)$r['id_producto'], 3)) {
      $stats['nv']++;
    }
  }

  $conexion->commit();

  //  Email 
  $total = $stats['sb'] + $stats['ss'] + $stats['nv'];

  // Enviar solo si hay nuevas alertas 
  if ($total > 0 && defined('ALERT_EMAIL_TO') && ALERT_EMAIL_TO) {
    $host   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https' : 'http').'://'.($_SERVER['HTTP_HOST'] ?? '');
    $link   = $host ? $host.'/admin/alertas/' : '/admin/alertas/';
    $resumenHtml = '
      <div style="font-family:Arial,sans-serif">
        <h2>🔔 Nuevas alertas generadas</h2>
        <p>Se generaron <b>'.$total.'</b> alertas.</p>
        <ul>
          <li><b>Stock bajo:</b> '.$stats['sb'].'</li>
          <li><b>Sin stock:</b> '.$stats['ss'].'</li>
          <li><b>Sin ventas ('.$diasSinVentas.' días):</b> '.$stats['nv'].'</li>
        </ul>
        <p><a href="'.htmlspecialchars($link, ENT_QUOTES, 'UTF-8').'">Ver alertas</a></p>
      </div>';

    @send_alert_email('Nuevas alertas ('.$total.') — Los Lapicitos', $resumenHtml, ALERT_EMAIL_TO);
  }

  if (php_sapi_name()==='cli') {
    echo "OK: $total alertas (SB {$stats['sb']} | SS {$stats['ss']} | NV {$stats['nv']})\n";
  } else {
    $_SESSION['flash_ok'] = "Generadas $total (SB {$stats['sb']} | SS {$stats['ss']} | NV {$stats['nv']}).";
    header('Location: /admin/alertas/index.php');
  }

} catch (Throwable $e) {
  $conexion->rollback();

  if (php_sapi_name()==='cli') {
    fwrite(STDERR, "[ERROR] ".$e->getMessage()."\n");
  } else {
    $_SESSION['flash_err'] = 'Error generando alertas: '.$e->getMessage();
    header('Location: /admin/alertas/index.php');
  }
}
