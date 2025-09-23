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
require_perm('alertas.enviar'); // crea este permiso en tu ACL; o usa alertas.generar

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (session_status()===PHP_SESSION_NONE) session_start();

// CSRF obligatorio
if (empty($_POST['csrf']) || empty($_SESSION['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) {
  http_response_code(400);
  exit('CSRF inválido');
}

$idSuc = (int)($_POST['suc'] ?? 0);

// Filtro base: alertas activas de tipo Stock Bajo (1)
$w = ["a.atendida=0", "a.id_tipo_alerta=1"];
$types=''; $params=[];
if ($idSuc > 0) { $w[]="i.id_sucursal=?"; $types.='i'; $params[]=$idSuc; }
$where = 'WHERE '.implode(' AND ', $w);

// Traer datos para el listado
$sql = "
  SELECT p.codigo, p.nombre AS producto, s.nombre AS sucursal,
         i.stock_actual, i.stock_minimo,
         pr.nombre AS proveedor
    FROM alerta a
    JOIN inventario i ON i.id_inventario = a.id_inventario
    JOIN producto  p ON p.id_producto    = a.id_producto
    JOIN sucursal  s ON s.id_sucursal    = i.id_sucursal
    LEFT JOIN proveedor pr ON pr.id_proveedor = p.id_proveedor
    $where
   ORDER BY (i.stock_actual - i.stock_minimo) ASC, s.nombre, p.nombre";

$st = $conexion->prepare($sql);
if ($types) $st->bind_param($types, ...$params);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

if (empty($rows)) {
  $_SESSION['flash_ok'] = 'No hay alertas de Stock Bajo con este filtro.';
  header('Location: /admin/alertas/index.php'); exit;
}

// Construir HTML del mail
$ttl = 'Listado Stock Bajo';
if ($idSuc>0) {
  // traer nombre sucursal para el título
  $s = $conexion->prepare("SELECT nombre FROM sucursal WHERE id_sucursal=?");
  $s->bind_param('i',$idSuc); $s->execute();
  $ttl .= ' — '.$s->get_result()->fetch_column();
  $s->close();
}

$host   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https' : 'http').'://'.($_SERVER['HTTP_HOST'] ?? '');
$link   = $host ? $host.'/admin/alertas/?tipo=1&estado=0' : '/admin/alertas/?tipo=1&estado=0';

$rowsHtml = '';
foreach ($rows as $r) {
  $rowsHtml .= '<tr>'.
    '<td>'.htmlspecialchars($r['sucursal']).'</td>'.
    '<td>'.htmlspecialchars($r['codigo'] ?? '').'</td>'.
    '<td>'.htmlspecialchars($r['producto']).'</td>'.
    '<td style="text-align:right">'.(int)$r['stock_actual'].'</td>'.
    '<td style="text-align:right">'.(int)$r['stock_minimo'].'</td>'.
    '<td>'.htmlspecialchars($r['proveedor'] ?? '—').'</td>'.
  '</tr>';
}

$html = '
  <div style="font-family:Arial,sans-serif">
    <h2>🔔 '.$ttl.'</h2>
    <p>Total: <b>'.count($rows).'</b> productos con stock bajo.</p>
    <table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;font-size:13px">
      <thead style="background:#f2f2f2">
        <tr>
          <th>Sucursal</th><th>Código</th><th>Producto</th><th>Stock</th><th>Mínimo</th><th>Proveedor</th>
        </tr>
      </thead>
      <tbody>'.$rowsHtml.'</tbody>
    </table>
    <p style="margin-top:12px"><a href="'.htmlspecialchars($link,ENT_QUOTES,'UTF-8').'">Ver en el sistema</a></p>
  </div>';

$ok = @send_alert_email('Stock bajo ('.count($rows).') — Los Lapicitos', $html, ALERT_EMAIL_TO);

$_SESSION['flash_'.($ok?'ok':'err')] = $ok
  ? 'Listado de Stock Bajo enviado por email.'
  : 'No se pudo enviar el email (revisá configuración SMTP).';

header('Location: /admin/alertas/index.php'); exit;
