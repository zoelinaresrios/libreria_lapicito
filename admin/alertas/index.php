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
require_perm('alertas.ver');

if (session_status()===PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['accion'] ?? '')==='atender') {
  if (!can('alertas.atender')) $errors[]='No tenés permiso.';
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) $errors[]='Token inválido.';

  $id_alerta=(int)($_POST['id_alerta'] ?? 0);
  $id_usuario=(int)($_SESSION['id_usuario'] ?? 0);

  if ($id_alerta<=0) $errors[]='ID inválido.';
  if (!$errors) {
    $st=$conexion->prepare("UPDATE alerta SET atendida=1, atendida_por=?, fecha_atendida=NOW() WHERE id_alerta=?");
    $st->bind_param('ii',$id_usuario,$id_alerta);
    $st->execute(); $st->close();
    $_SESSION['flash_ok']="Alerta #$id_alerta marcada como atendida.";
    header('Location: '.$_SERVER['REQUEST_URI']); exit;
  }
}

$q      = trim($_GET['q'] ?? '');
$tipo   = (int)($_GET['tipo'] ?? 0);       
$estado = $_GET['estado'] ?? '';          

$page    = max(1,(int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page-1)*$perPage;

$tipos=[]; 
$r=$conexion->query("SELECT id_tipo_alerta,nombre_tipo FROM tipo_alerta ORDER BY nombre_tipo");
while($row=$r->fetch_assoc()) $tipos[]=$row;

$where=[]; $params=[]; $types='';
if ($q!==''){
  $where[]="(a.producto_nombre LIKE ? OR a.producto_codigo LIKE ?)";
  $params[]="%$q%"; $params[]="%$q%"; $types.='ss';
}
if ($tipo>0){ $where[]="a.id_tipo_alerta=?"; $params[]=$tipo; $types.='i'; }
if ($estado==='pend'){ $where[]="a.atendida=0"; }
if ($estado==='atend'){ $where[]="a.atendida=1"; }

$whereSql=$where?('WHERE '.implode(' AND ',$where)):'';

$sqlCount="SELECT COUNT(*) total FROM alerta a $whereSql";
$st=$conexion->prepare($sqlCount);
if ($types) $st->bind_param($types,...$params);
$st->execute();
$total=(int)($st->get_result()->fetch_assoc()['total']??0);
$st->close();
$pages=max(1,(int)ceil($total/$perPage));

$sql="
  SELECT a.*, ta.nombre_tipo, u.nombre AS atendido_por
  FROM alerta a
  LEFT JOIN tipo_alerta ta ON ta.id_tipo_alerta=a.id_tipo_alerta
  LEFT JOIN usuario u ON u.id_usuario=a.atendida_por
  $whereSql
  ORDER BY a.fecha_creada DESC
  LIMIT ? OFFSET ?
";
$typesList=$types.'ii'; $paramsList=$params;
$paramsList[]=$perPage; $paramsList[]=$offset;

$st=$conexion->prepare($sql);
$st->bind_param($typesList,...$paramsList);
$st->execute();
$rows=$st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Alertas — Los Lapicitos</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/8.0.1/normalize.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/skeleton/2.0.4/skeleton.min.css">
  <link rel="stylesheet" href="/libreria_lapicito/css/style.css">
</head>
<body>
<div class="barra"></div>

  <div class="prod-shell">
    <aside class="prod-side">
      <ul class="prod-nav">
        <li><a href="/libreria_lapicito/admin/index.php">inicio</a></li>
       
        <?php if (can('productos.ver')): ?>
        <li><a href="/libreria_lapicito/admin/productos/">Productos</a></li>
        <?php endif; ?>
        <li><a href="/libreria_lapicito/admin/categorias/">categorias</a></li>
        <?php if (can('inventario.ver')): ?>
           <li><a href="/libreria_lapicito/admin/subcategorias/">subcategorias</a></li>
        <li><a href="/libreria_lapicito/admin/inventario/">Inventario</a></li>
        <?php endif; ?>
        <?php if (can('pedidos.aprobar')): ?>
        <li><a href="/libreria_lapicito/admin/pedidos/">Pedidos</a></li>
        <?php endif; ?>
        <?php if (can('alertas.ver')): ?>
        <li><a class="active" href="/libreria_lapicito/admin/alertas/">Alertas</a></li>
        <?php endif; ?>
        <?php if (can('reportes.detallados') || can('reportes.simple')): ?>
        <li><a href="/libreria_lapicito/admin/reportes/">Reportes</a></li>
        <?php endif; ?>
         <?php if (can('ventas.rapidas')): ?>
        <li><a href="/libreria_lapicito/admin/ventas/">Ventas</a></li>
        <?php endif; ?>
        <?php if (can('usuarios.gestionar') || can('usuarios.crear_empleado')): ?>
        <li><a href="/libreria_lapicito/admin/usuarios/">Usuarios</a></li>
        <?php endif; ?>
        <?php if (can('usuarios.gestionar')): ?>
        <li><a href="/libreria_lapicito/admin/roles/">Roles y permisos</a></li>
        <?php endif; ?>
        <li><a href="/libreria_lapicito/admin/ajustes/">Ajustes</a></li>
        <li><a href="/libreria_lapicito/admin/logout.php">Salir</a></li>
      </ul>
    </aside>

  <main class="prod-main">
    <div class="inv-title">Panel administrativo — Alertas</div>

    <?php if(!empty($_SESSION['flash_ok'])): ?>
      <div class="alert-ok"><?= h($_SESSION['flash_ok']); unset($_SESSION['flash_ok']); ?></div>
    <?php endif; ?>
    <?php if($errors): ?>
      <div class="alert-error"><?php foreach($errors as $e) echo '<div>'.h($e).'</div>'; ?></div>
    <?php endif; ?>

    <div class="prod-card">
      <div class="prod-head"><h5>Alertas</h5></div>

      <form class="prod-filters" method="get">
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar producto…">
        <select name="tipo">
          <option value="0">Todos los tipos</option>
          <?php foreach($tipos as $t): ?>
            <option value="<?= (int)$t['id_tipo_alerta'] ?>" <?= $tipo===(int)$t['id_tipo_alerta']?'selected':'' ?>>
              <?= h($t['nombre_tipo']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <select name="estado">
          <option value="">Todas</option>
          <option value="pend" <?= $estado==='pend'?'selected':'' ?>>Pendientes</option>
          <option value="atend" <?= $estado==='atend'?'selected':'' ?>>Atendidas</option>
        </select>
        <button class="btn-filter" type="submit">Filtrar</button>
      </form>

      <div class="table-wrap">
        <table class="u-full-width">
          <thead>
            <tr>
              <th>ID</th>
              <th>Fecha</th>
              <th>Tipo</th>
              <th>Producto</th>
              <th>Stock</th>
              <th>Estado</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($rows as $r): ?>
              <tr>
                <td>#<?= (int)$r['id_alerta'] ?></td>
                <td><?= h($r['fecha_creada']) ?></td>
                <td><?= h($r['nombre_tipo'] ?? '—') ?></td>
                <td><?= h($r['producto_nombre'] ?? '') ?> (<?= h($r['producto_codigo'] ?? '') ?>)</td>
                <td><?= (int)$r['stock_actual'] ?>/<?= (int)$r['stock_minimo'] ?></td>
                <td>
                  <?php if($r['atendida']): ?>
                    <span class="badge ok">Atendida</span>
                    <?php if($r['atendido_por']): ?> por <?= h($r['atendido_por']) ?><?php endif; ?>
                  <?php else: ?>
                    <span class="badge warn">Pendiente</span>
                  <?php endif; ?>
                </td>
                <td>
                  <a class="btn-sm" href="/libreria_lapicito/admin/productos/editar.php?id=<?= (int)$r['id_producto'] ?>">Ver producto</a>
                  <?php if(!$r['atendida'] && can('alertas.atender')): ?>
                    <form method="post" style="display:inline">
                      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                      <input type="hidden" name="accion" value="atender">
                      <input type="hidden" name="id_alerta" value="<?= (int)$r['id_alerta'] ?>">
                      <button class="btn-sm">Atender</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if(empty($rows)): ?>
              <tr><td colspan="7" class="muted">Sin resultados.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if($pages>1): ?>
        <div class="prod-pager">
          <?php for($p=1;$p<=$pages;$p++):
            $qs=$_GET; $qs['page']=$p; $href='?'.http_build_query($qs); ?>
            <a class="<?= $p===$page?'on':'' ?>" href="<?= h($href) ?>"><?= $p ?></a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>
</body>
</html>
