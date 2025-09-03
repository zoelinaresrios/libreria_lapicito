<?php
// /libreria_lapicito/admin/pedidos/index.php
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
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ---------- Cambio de estado (POST) ---------- */
$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['accion']) && $_POST['accion']==='cambiar_estado') {
  if (!can('pedidos.cambiar_estado')) { $errors[]='No tenés permiso para cambiar estados.'; }
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { $errors[]='Token inválido.'; }

  $id_pedido = (int)($_POST['id_pedido'] ?? 0);
  $dest      = $_POST['estado'] ?? '';

  $valid = ['borrador','enviado','recibido','cancelado'];
  if ($id_pedido<=0 || !in_array($dest, $valid, true)) { $errors[]='Datos inválidos.'; }

  if (!$errors) {
    // leer estado actual
    $st = $conexion->prepare("SELECT estado FROM pedido WHERE id_pedido=?");
    $st->bind_param('i', $id_pedido); $st->execute();
    $row = $st->get_result()->fetch_assoc(); $st->close();
    if (!$row) { $errors[]='Pedido inexistente.'; }
    else {
      $orig = $row['estado'];
      $okTrans = false;
      // Transiciones permitidas
      if ($orig==='borrador' && $dest==='enviado') $okTrans=true;
      if ($orig==='enviado' && $dest==='recibido') $okTrans=true;
      if (in_array($orig, ['borrador','enviado'], true) && $dest==='cancelado') $okTrans=true;

      if (!$okTrans) { $errors[]="Transición no permitida ($orig → $dest)."; }
      else {
        try{
          $conexion->begin_transaction();
          $st=$conexion->prepare("UPDATE pedido SET estado=? WHERE id_pedido=?");
          $st->bind_param('si', $dest, $id_pedido);
          $st->execute(); $st->close();
          $conexion->commit();
          $_SESSION['flash_ok']="Pedido #$id_pedido: estado cambiado a $dest.";
          header('Location: '.$_SERVER['REQUEST_URI']); exit;
        }catch(Exception $e){
          $conexion->rollback();
          $errors[]='No se pudo cambiar el estado: '.$e->getMessage();
        }
      }
    }
  }
}

/* ---------- Filtros ---------- */
$q      = trim($_GET['q'] ?? '');         // busca por id o comentario
$estado = $_GET['estado'] ?? '';          // '', borrador, enviado, recibido, cancelado
$desde  = trim($_GET['desde'] ?? '');     // YYYY-MM-DD
$hasta  = trim($_GET['hasta'] ?? '');     // YYYY-MM-DD

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

/* ---------- WHERE ---------- */
$where = []; $params=[]; $types='';
if ($q!=='') {
  if (ctype_digit($q)) {
    $where[] = "(p.id_pedido = ? OR p.comentario LIKE ?)";
    $params[]=(int)$q; $types.='i';
    $params[]='%'.$q.'%'; $types.='s';
  } else {
    $where[] = "p.comentario LIKE ?";
    $params[]='%'.$q.'%'; $types.='s';
  }
}
if (in_array($estado, ['borrador','enviado','recibido','cancelado'], true)) {
  $where[]="p.estado=?"; $params[]=$estado; $types.='s';
}
if ($desde!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$desde)) {
  $where[]="p.creado_en >= CONCAT(?, ' 00:00:00')"; $params[]=$desde; $types.='s';
}
if ($hasta!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$hasta)) {
  $where[]="p.creado_en <= CONCAT(?, ' 23:59:59')"; $params[]=$hasta; $types.='s';
}
$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

/* ---------- Conteo ---------- */
$sqlCount = "
  SELECT COUNT(*) total
  FROM pedido p
  $whereSql
";
$st=$conexion->prepare($sqlCount);
if ($types) $st->bind_param($types, ...$params);
$st->execute();
$total = (int)($st->get_result()->fetch_assoc()['total'] ?? 0);
$st->close();
$pages = max(1, (int)ceil($total / $perPage));

/* ---------- Listado con totales ---------- */
$sqlList = "
  SELECT
    p.id_pedido,
    p.estado,
    p.comentario,
    p.creado_en,
    COUNT(pi.id_item) AS items,
    COALESCE(SUM(pi.cantidad * COALESCE(pi.precio_unitario,0)),0) AS total
  FROM pedido p
  LEFT JOIN pedido_item pi ON pi.id_pedido=p.id_pedido
  $whereSql
  GROUP BY p.id_pedido, p.estado, p.comentario, p.creado_en
  ORDER BY p.creado_en DESC, p.id_pedido DESC
  LIMIT ? OFFSET ?
";
$typesList=$types.'ii';
$paramsList=$params; $paramsList[]=$perPage; $paramsList[]=$offset;

$st=$conexion->prepare($sqlList);
$st->bind_param($typesList, ...$paramsList);
$st->execute();
$rows=$st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Pedidos — Los Lapicitos</title>
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
        <li><a  href="/libreria_lapicito/admin/index.php">inicio</a></li>
       
        <?php if (can('productos.ver')): ?>
        <li><a href="/libreria_lapicito/admin/productos/">Productos</a></li>
        <?php endif; ?>
        <li><a href="/libreria_lapicito/admin/categorias/">categorias</a></li>
        <?php if (can('inventario.ver')): ?>
           <li><a href="/libreria_lapicito/admin/subcategorias/">subcategorias</a></li>
        <li><a href="/libreria_lapicito/admin/inventario/">Inventario</a></li>
        <?php endif; ?>
        <?php if (can('pedidos.aprobar')): ?>
        <li><a class="active" href="/libreria_lapicito/admin/pedidos/">Pedidos</a></li>
        <?php endif; ?>
        <?php if (can('alertas.ver')): ?>
        <li><a href="/libreria_lapicito/admin/alertas/">Alertas</a></li>
        <?php endif; ?>
        <?php if (can('reportes.detallados') || can('reportes.simple')): ?>
        <li><a href="/libreria_lapicito/admin/reportes/">Reportes</a></li>
        <?php endif; ?>
         <?php if (can('ventas.rapidas')): ?>
        <li><a href="/libreria_lapicito/admin/ventas/">Ventas</a></li>
        <?php endif; ?>
        <?php if (can('usuarios.gestionar') || can('usuarios.crear_empleado')): ?>
        <li><a  href="/libreria_lapicito/admin/usuarios/">Usuarios</a></li>
        <?php endif; ?>
        <?php if (can('usuarios.gestionar')): ?>
        <li><a href="/libreria_lapicito/admin/roles/">Roles y permisos</a></li>
        <?php endif; ?>
        <li><a href="/libreria_lapicito/admin/ajustes/">Ajustes</a></li>
        <li><a href="/libreria_lapicito/admin/logout.php">Salir</a></li>
      </ul>
    </aside>


    <main class="prod-main">
      <div class="inv-title">Panel administrativo — Pedidos</div>

      <?php if(!empty($_SESSION['flash_ok'])): ?>
        <div class="alert-ok"><?= h($_SESSION['flash_ok']); unset($_SESSION['flash_ok']); ?></div>
      <?php endif; ?>
      <?php if($errors): ?>
        <div class="alert-error"><?php foreach($errors as $e) echo '<div>'.h($e).'</div>'; ?></div>
      <?php endif; ?>

      <div class="prod-card">
        <div class="prod-head">
          <h5>Pedidos</h5>
          <?php if (can('pedidos.crear')): ?>
            <a class="btn-add" href="/libreria_lapicito/admin/pedidos/crear.php">+ Nuevo pedido</a>
          <?php endif; ?>
        </div>

        <!-- Filtros -->
        <form class="prod-filters" method="get">
          <input class="input-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por ID o comentario…">
          <select name="estado">
            <option value="">Estado: Todos</option>
            <?php
              $opts=['borrador'=>'Borrador','enviado'=>'Enviado','recibido'=>'Recibido','cancelado'=>'Cancelado'];
              foreach($opts as $val=>$lab):
            ?>
              <option value="<?= $val ?>" <?= $estado===$val?'selected':'' ?>><?= $lab ?></option>
            <?php endforeach; ?>
          </select>
          <input type="date" name="desde" value="<?= h($desde) ?>" placeholder="Desde">
          <input type="date" name="hasta" value="<?= h($hasta) ?>" placeholder="Hasta">
          <button class="btn-filter" type="submit">Filtrar</button>
        </form>

        <!-- Tabla -->
        <div class="table-wrap">
          <table class="u-full-width">
            <thead>
              <tr>
                <th style="width:90px">#ID</th>
                <th style="width:160px">Fecha</th>
                <th style="width:140px">Estado</th>
                <th>Comentario</th>
                <th style="width:120px">Ítems</th>
                <th style="width:140px">Total</th>
                <th style="width:220px">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($rows as $r): ?>
                <tr>
                  <td>#<?= (int)$r['id_pedido'] ?></td>
                  <td><?= h($r['creado_en']) ?></td>
                  <td>
                    <?php
                      $est = $r['estado'];
                      $badge = ($est==='borrador'?'muted':($est==='enviado'?'warn':($est==='recibido'?'ok':'no')));
                      echo '<span class="badge '.$badge.'">'.h(ucfirst($est)).'</span>';
                    ?>
                  </td>
                  <td><?= h($r['comentario'] ?? '') ?></td>
                  <td><?= (int)$r['items'] ?></td>
                  <td>$ <?= number_format((float)$r['total'], 2, ',', '.') ?></td>
                  <td>
                    <a class="btn-sm" href="/libreria_lapicito/admin/pedidos/ver.php?id=<?= (int)$r['id_pedido'] ?>">Ver</a>

                    <?php if (can('pedidos.cambiar_estado')): ?>
                      <?php if ($r['estado']==='borrador'): ?>
                        <form method="post" style="display:inline">
                          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                          <input type="hidden" name="accion" value="cambiar_estado">
                          <input type="hidden" name="id_pedido" value="<?= (int)$r['id_pedido'] ?>">
                          <input type="hidden" name="estado" value="enviado">
                          <button class="btn-sm">Enviar</button>
                        </form>
                        <form method="post" style="display:inline">
                          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                          <input type="hidden" name="accion" value="cambiar_estado">
                          <input type="hidden" name="id_pedido" value="<?= (int)$r['id_pedido'] ?>">
                          <input type="hidden" name="estado" value="cancelado">
                          <button class="btn-sm btn-muted">Cancelar</button>
                        </form>
                      <?php elseif ($r['estado']==='enviado'): ?>
                        <form method="post" style="display:inline">
                          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                          <input type="hidden" name="accion" value="cambiar_estado">
                          <input type="hidden" name="id_pedido" value="<?= (int)$r['id_pedido'] ?>">
                          <input type="hidden" name="estado" value="recibido">
                          <button class="btn-sm">Recibido</button>
                        </form>
                        <form method="post" style="display:inline">
                          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                          <input type="hidden" name="accion" value="cambiar_estado">
                          <input type="hidden" name="id_pedido" value="<?= (int)$r['id_pedido'] ?>">
                          <input type="hidden" name="estado" value="cancelado">
                          <button class="btn-sm btn-muted">Cancelar</button>
                        </form>
                      <?php else: ?>
                   
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($rows)): ?>
                <tr><td colspan="7" class="muted">Sin resultados.</td></tr>
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
      </div>
    </main>
  </div>
</body>
</html>
