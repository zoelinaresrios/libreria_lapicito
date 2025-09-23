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
require_perm('inventario.ver_mov');

if (session_status()===PHP_SESSION_NONE) session_start();
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$idp = (int)($_GET['id'] ?? 0);
if ($idp<=0) { header('Location: /admin/inventario/'); exit; }


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
    KEY (id_producto),
    KEY (id_usuario),
    KEY (creado_en)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Info del producto
$st = $conexion->prepare("SELECT id_producto, nombre FROM producto WHERE id_producto=?");
$st->bind_param('i', $idp); $st->execute();
$prod = $st->get_result()->fetch_assoc();
$st->close();
if (!$prod) { header('Location: /admin/inventario/'); exit; }

// Filtros
$tipo    = $_GET['tipo']   ?? '';   // ingreso, egreso, ajuste
$desde   = trim($_GET['desde'] ?? ''); 
$hasta   = trim($_GET['hasta'] ?? ''); 

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

// WHERE dinámico
$w = ["m.id_producto=?"];
$params = [$idp];
$types  = 'i';

if (in_array($tipo, ['ingreso','egreso','ajuste'], true)) {
  $w[] = "m.tipo=?";
  $params[] = $tipo;
  $types .= 's';
}
if ($desde !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
  $w[] = "m.creado_en >= CONCAT(?, ' 00:00:00')";
  $params[] = $desde;
  $types .= 's';
}
if ($hasta !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
  $w[] = "m.creado_en <= CONCAT(?, ' 23:59:59')";
  $params[] = $hasta;
  $types .= 's';
}
$whereSql = 'WHERE '.implode(' AND ', $w);

// Totales
$sqlCount = "SELECT COUNT(*) total FROM inventario_mov m $whereSql";
$st = $conexion->prepare($sqlCount);
$st->bind_param($types, ...$params);
$st->execute();
$total = (int)$st->get_result()->fetch_assoc()['total'];
$st->close();
$pages = max(1, (int)ceil($total/$perPage));

// Listado
$sql = "
  SELECT m.*, u.nombre AS usuario
  FROM inventario_mov m
  LEFT JOIN usuario u ON u.id_usuario = m.id_usuario
  $whereSql
  ORDER BY m.creado_en DESC, m.id_mov DESC
  LIMIT ? OFFSET ?
";
$typesList = $types.'ii';
$paramsList = $params; $paramsList[] = $perPage; $paramsList[] = $offset;

$st = $conexion->prepare($sql);
$st->bind_param($typesList, ...$paramsList);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Movimientos de inventario — Los Lapicitos</title>
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
        <li><a class="active" href="#">Movimientos</a></li>
      </ul>
    </aside>

    <main class="prod-main">
      <div class="inv-title">Movimientos — #<?= (int)$prod['id_producto'] ?> <?= h($prod['nombre']) ?></div>

      <div class="prod-card">
        <!-- Filtros -->
        <form class="prod-filters" method="get">
          <input type="hidden" name="id" value="<?= (int)$idp ?>">
          <select name="tipo">
            <option value="">Tipo: Todos</option>
            <option value="ingreso" <?= $tipo==='ingreso'?'selected':'' ?>>Ingreso</option>
            <option value="egreso"  <?= $tipo==='egreso'?'selected':''  ?>>Egreso</option>
            <option value="ajuste"  <?= $tipo==='ajuste'?'selected':''  ?>>Ajuste</option>
          </select>
          <input type="date" name="desde" value="<?= h($desde) ?>" placeholder="Desde">
          <input type="date" name="hasta" value="<?= h($hasta) ?>" placeholder="Hasta">
          <button class="btn-filter" type="submit">Filtrar</button>
        </form>

        <div class="table-wrap">
          <table class="u-full-width">
            <thead>
              <tr>
                <th style="width:80px">ID</th>
                <th style="width:140px">Fecha</th>
                <th style="width:110px">Tipo</th>
                <th style="width:110px">Cantidad</th>
                <th style="width:170px">Stock (prev → nuevo)</th>
                <th>Motivo</th>
                <th style="width:200px">Usuario</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($rows as $m): ?>
                <tr>
                  <td>#<?= (int)$m['id_mov'] ?></td>
                  <td><?= h($m['creado_en']) ?></td>
                  <td><?= h($m['tipo']) ?></td>
                  <td><?= (int)$m['cantidad'] ?></td>
                  <td><?= (int)$m['stock_prev'] ?> → <?= (int)$m['stock_nuevo'] ?></td>
                  <td><?= h($m['motivo'] ?? '') ?></td>
                  <td><?= h($m['usuario'] ?? '—') ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($rows)): ?>
                <tr><td colspan="7" class="muted">Sin movimientos para los filtros seleccionados.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        
        <?php if ($pages>1): ?>
          <div class="prod-pager">
            <?php for($p=1;$p<=$pages;$p++):
              $qs=$_GET; $qs['page']=$p; $href='?'.http_build_query($qs); ?>
              <a class="<?= $p===$page?'on':'' ?>" href="<?= h($href) ?>"><?= $p ?></a>
            <?php endfor; ?>
          </div>
        <?php endif; ?>

        <div class="form-actions">
          <a class="btn-sm btn-muted" href="/admin/inventario/">Volver</a>
        </div>
      </div>
    </main>
  </div>
</body>
</html>
