<?php
// /admin/ventas/historial.php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';

$HAS_ACL = file_exists(__DIR__ . '/../includes/acl.php');
if ($HAS_ACL) { require_once __DIR__ . '/../includes/acl.php'; }
else {
  if (session_status()===PHP_SESSION_NONE) session_start();
  if (!function_exists('can')) { function can($k){ return true; } }
  if (!function_exists('require_perm')) { function require_perm($k){ return true; } }
}
require_perm('ventas.ver');

if (function_exists('is_logged') && !is_logged()) { header('Location: /admin/login.php'); exit; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (session_status()===PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }

$desde = $_GET['desde'] ?? date('Y-m-d');
$hasta = $_GET['hasta'] ?? date('Y-m-d');
$qUser = trim($_GET['user'] ?? '');
$qSuc  = (int)($_GET['suc'] ?? 0);
$page  = max(1,(int)($_GET['page'] ?? 1));
$perPage=15; $offset=($page-1)*$perPage;

// Aseguro tabla auxiliar de anulaciones (no tocamos esquema)
$conexion->query("CREATE TABLE IF NOT EXISTS venta_anulada (
  id_venta BIGINT(20) UNSIGNED PRIMARY KEY,
  fecha DATETIME NOT NULL,
  id_usuario INT(10) UNSIGNED NOT NULL,
  motivo VARCHAR(200) NULL
) ENGINE=InnoDB");

$w=[]; $params=[]; $types='';
$w[] = "DATE(v.fecha_hora) BETWEEN ? AND ?"; $params[]=$desde; $params[]=$hasta; $types.='ss';
if ($qSuc>0){ $w[]="v.id_sucursal=?"; $params[]=$qSuc; $types.='i'; }
if ($qUser!==''){ $w[]="u.nombre LIKE ?"; $params[]='%'.$qUser.'%'; $types.='s'; }
$where = 'WHERE '.implode(' AND ',$w);

$sqlCount = "SELECT COUNT(*) c FROM venta v JOIN usuario u ON u.id_usuario=v.id_usuario $where";
$st=$conexion->prepare($sqlCount); $st->bind_param($types,...$params); $st->execute();
$total=(int)($st->get_result()->fetch_assoc()['c']??0); $st->close();
$pages = max(1,(int)ceil($total/$perPage));

$sql = "SELECT v.id_venta, v.fecha_hora, v.id_sucursal, v.total, u.nombre,
        IF(va.id_venta IS NULL, 0, 1) AS anulada
        FROM venta v
        JOIN usuario u ON u.id_usuario=v.id_usuario
        LEFT JOIN venta_anulada va ON va.id_venta=v.id_venta
        $where
        ORDER BY v.fecha_hora DESC
        LIMIT ? OFFSET ?";
$typesL=$types.'ii'; $paramsL=$params; $paramsL[]=$perPage; $paramsL[]=$offset;
$st=$conexion->prepare($sql); $st->bind_param($typesL,...$paramsL); $st->execute();
$rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Historial de ventas</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/vendor/normalize.css?v=2">
  <link rel="stylesheet" href="/vendor/skeleton.css?v=3">
  <link rel="stylesheet" href="/css/style.css?v=13">
  <style>
    .wrap{padding:16px}
    .filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px}
    .badge-anulada{background:#e74c3c;color:#fff;padding:2px 8px;border-radius:10px;font-size:12px}
    .badge-ok{background:#2ecc71;color:#fff;padding:2px 8px;border-radius:10px;font-size:12px}
    .btn-sm{padding:6px 10px;border-radius:8px;border:1px solid var(--borde)}
    .btn-danger{background:#c0392b;color:#fff;border:none;padding:6px 10px;border-radius:8px}
    .pager a{display:inline-block;padding:6px 10px;border:1px solid var(--borde);border-radius:8px;margin-right:6px}
    .pager a.on{background:var(--btn);color:#fff;border-color:var(--btn)}
  </style>
</head>
<body>
  <div class="barra"></div>
  <div class="wrap">
    <h5>Historial de ventas</h5>

    <form class="filters" method="get">
      <input type="date" name="desde" value="<?= h($desde) ?>">
      <input type="date" name="hasta" value="<?= h($hasta) ?>">
      <input type="text" name="user" value="<?= h($qUser) ?>" placeholder="Usuario…">
      <input type="number" name="suc" value="<?= (int)$qSuc ?>" placeholder="Sucursal…">
      <button class="button-primary" type="submit">Filtrar</button>
      <a class="button" href="/admin/ventas/">Punto de venta</a>
    </form>

    <div class="table-wrap">
      <table class="u-full-width">
        <thead>
          <tr>
            <th>#</th><th>Fecha</th><th>Usuario</th><th>Sucursal</th><th>Total</th><th>Estado</th><th style="width:180px">Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php if(empty($rows)): ?>
          <tr><td colspan="7" class="muted">Sin resultados.</td></tr>
        <?php else: foreach($rows as $r): ?>
          <tr>
            <td>#<?= (int)$r['id_venta'] ?></td>
            <td><?= h($r['fecha_hora']) ?></td>
            <td><?= h($r['nombre']) ?></td>
            <td><?= (int)$r['id_sucursal'] ?></td>
            <td>$ <?= number_format((float)$r['total'],2,',','.') ?></td>
            <td><?= $r['anulada']?'⛔ <span class="badge-anulada">Anulada</span>':'✅ <span class="badge-ok">Vigente</span>' ?></td>
            <td>
              <a class="btn-sm" href="/admin/ventas/ver.php?id=<?= (int)$r['id_venta'] ?>">Ver</a>
              <?php if (!$r['anulada'] && can('ventas.anular')): ?>
                <form class="u-inline" method="post" action="/admin/ventas/anular.php" onsubmit="return confirm('¿Anular venta #<?= (int)$r['id_venta'] ?>?');">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="id" value="<?= (int)$r['id_venta'] ?>">
                  <button class="btn-danger">Anular</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <?php if($pages>1): ?>
      <div class="pager" style="margin-top:8px">
        <?php for($p=1;$p<=$pages;$p++):
          $qs=$_GET; $qs['page']=$p; $href='?'.http_build_query($qs); ?>
          <a class="<?= $p===$page?'on':'' ?>" href="<?= h($href) ?>"><?= $p ?></a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
