<?php
include(__DIR__ . '/../includes/db.php');
$HAS_AUTH = file_exists(__DIR__ . '/../includes/auth.php');
if ($HAS_AUTH) require_once __DIR__ . '/../includes/auth.php';

$HAS_ACL = file_exists(__DIR__ . '/../includes/acl.php');
if ($HAS_ACL) {
  require_once __DIR__ . '/../includes/acl.php';
} else {
  
  if (session_status()===PHP_SESSION_NONE) session_start();
  if (!function_exists('can')) { function can($k){ return true; } }
  if (!function_exists('require_perm')) { function require_perm($k){ return true; } }
}
if ($HAS_AUTH && function_exists('is_logged') && !is_logged()) {
  header('Location: /admin/login.php'); exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (session_status()===PHP_SESSION_NONE) session_start();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$warns = [];

/* Filtros de fecha (para Top y ventas recientes)  */
$hoy = date('Y-m-d');
$desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-30 days'));
$hasta = $_GET['hasta'] ?? $hoy;
if (strtotime($hasta) < strtotime($desde)) { $tmp=$desde; $desde=$hasta; $hasta=$tmp; }


function q_one(mysqli $cn, string $sql, string $types = '', array $params = []) {
  global $warns;
  try {
    $st = $cn->prepare($sql);
    if ($types) $st->bind_param($types, ...$params);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    return $r ?: [];
  } catch (Throwable $e) {
    $warns[] = '⚠️ '.h($e->getMessage());
    return [];
  }
}
function q_all(mysqli $cn, string $sql, string $types = '', array $params = []) {
  global $warns;
  try {
    $st = $cn->prepare($sql);
    if ($types) $st->bind_param($types, ...$params);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
  } catch (Throwable $e) {
    $warns[] = '⚠️ '.h($e->getMessage());
    return [];
  }
}


$total_productos = (int)(q_one($conexion,"SELECT COUNT(*) n FROM producto")['n'] ?? 0);

// Total categorías / subcategorías / proveedores
$total_categorias    = (int)(q_one($conexion,"SELECT COUNT(*) n FROM categoria")['n']    ?? 0);
$total_subcategorias = (int)(q_one($conexion,"SELECT COUNT(*) n FROM subcategoria")['n'] ?? 0);
$total_proveedores   = (int)(q_one($conexion,"SELECT COUNT(*) n FROM proveedor")['n']    ?? 0);

// Stock total
$stock_total = (int)(q_one($conexion,"SELECT COALESCE(SUM(stock_actual),0) n FROM inventario")['n'] ?? 0);

// Productos sin stock 
$prod_sin_stock = (int)(q_one($conexion,"
  SELECT COUNT(*) n FROM (
    SELECT p.id_producto
    FROM producto p
    LEFT JOIN inventario i ON i.id_producto=p.id_producto
    GROUP BY p.id_producto
    HAVING COALESCE(SUM(i.stock_actual),0) <= 0
  ) t
")['n'] ?? 0);

// Bajo stock
$alertas_bajo = (int)(q_one($conexion,"
  SELECT COUNT(*) n FROM (
    SELECT p.id_producto
    FROM producto p
    LEFT JOIN inventario i ON i.id_producto=p.id_producto
    GROUP BY p.id_producto
    HAVING COALESCE(SUM(i.stock_actual),0) <= COALESCE(MIN(i.stock_minimo),0)
  ) t
")['n'] ?? 0);

// Ventas de hoy
$ventas_hoy = (float)(q_one($conexion,"
  SELECT COALESCE(SUM(v.total),0) total
  FROM venta v
  WHERE DATE(v.fecha_hora)=CURDATE()
")['total'] ?? 0.0);

/*Ventas por mes */
$ventas_mes = q_all($conexion,"
  SELECT DATE_FORMAT(v.fecha_hora,'%Y-%m') ym,
         DATE_FORMAT(v.fecha_hora,'%b %Y') etiqueta,
         SUM(v.total) total
  FROM venta v
  WHERE v.fecha_hora >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
  GROUP BY ym, etiqueta
  ORDER BY ym
");

/*  Top productos */
$top_prod = q_all($conexion,"
  SELECT p.id_producto, p.nombre,
         SUM(vd.cantidad) unidades,
         SUM(vd.cantidad * vd.precio_unitario) total
  FROM venta_detalle vd
  JOIN venta v      ON v.id_venta = vd.id_venta
  JOIN producto p   ON p.id_producto = vd.id_producto
  WHERE v.fecha_hora >= ? AND v.fecha_hora < DATE_ADD(?, INTERVAL 1 DAY)
  GROUP BY p.id_producto, p.nombre
  ORDER BY unidades DESC
  LIMIT 10
",'ss',[$desde,$hasta]);

/* Ventas recientes */
$ventas_recientes = q_all($conexion,"
  SELECT v.id_venta, v.fecha_hora, v.total
  FROM venta v
  ORDER BY v.fecha_hora DESC
  LIMIT 10
");

/*Stock por categoría*/
$stock_cat = q_all($conexion,"
  SELECT c.nombre categoria, COALESCE(SUM(i.stock_actual),0) cant
  FROM producto p
  LEFT JOIN subcategoria sc ON sc.id_subcategoria=p.id_subcategoria
  LEFT JOIN categoria c     ON c.id_categoria=sc.id_categoria
  LEFT JOIN inventario i    ON i.id_producto=p.id_producto
  GROUP BY c.id_categoria, c.nombre
  ORDER BY cant DESC, categoria ASC
  LIMIT 8
");

/* Lista bajo stock */
$low_list = q_all($conexion,"
  SELECT p.id_producto, p.nombre,
         COALESCE(SUM(i.stock_actual),0) st,
         COALESCE(MIN(i.stock_minimo),0) smin
  FROM producto p
  LEFT JOIN inventario i ON i.id_producto=p.id_producto
  GROUP BY p.id_producto, p.nombre
  HAVING COALESCE(SUM(i.stock_actual),0) <= COALESCE(MIN(i.stock_minimo),0)
  ORDER BY st ASC, p.nombre ASC
  LIMIT 10
");

/* Pedidos pendientes */
$ped_pend = q_all($conexion,"
  SELECT p.id_pedido, p.id_proveedor, ep.nombre_estado AS estado,
         pr.nombre AS proveedor
  FROM pedido p
  LEFT JOIN estado_pedido ep ON ep.id_estado_pedido=p.id_estado_pedido
  LEFT JOIN proveedor pr     ON pr.id_proveedor=p.id_proveedor
  WHERE p.id_estado_pedido = 1
  ORDER BY p.id_pedido DESC
  LIMIT 10
");

/*  Movimientos recientes*/
$mov_rec = q_all($conexion,"
  SELECT m.id_movimiento, tm.nombre_tipo AS tipo,
         SUM(md.cantidad) as unidades
  FROM movimiento m
  LEFT JOIN tipo_movimiento tm ON tm.id_tipo_movimiento=m.id_tipo_movimiento
  LEFT JOIN movimiento_detalle md ON md.id_movimiento=m.id_movimiento
  GROUP BY m.id_movimiento, tm.nombre_tipo
  ORDER BY m.id_movimiento DESC
  LIMIT 10
");

/*  Alertas  */
$alertas_tbl = q_all($conexion,"
  SELECT a.id_alerta, ta.nombre_tipo AS tipo, p.nombre AS producto, a.atendida
  FROM alerta a
  LEFT JOIN tipo_alerta ta ON ta.id_tipo_alerta=a.id_tipo_alerta
  LEFT JOIN producto p     ON p.id_producto=a.id_producto
  ORDER BY a.id_alerta DESC
  LIMIT 10
");
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Dashboard — Los Lapicitos</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
   <link rel="stylesheet" href="/vendor/normalize.css?v=2">
<link rel="stylesheet" href="/vendor/skeleton.css?v=3">
<link rel="stylesheet" href="/css/style.css?v=13">
<link rel="shortcut icon" href="img/logo.jpg" type="image/x-icon">

</head>
<body>

  
  <div class="barra"></div>

  <div class="prod-shell">
    <aside class="prod-side">
      <ul class="prod-nav">
        <li><a class="active" href="/admin/index.php">inicio</a></li>
       
        <?php if (can('productos.ver')): ?>
        <li><a href="/admin/productos/">Productos</a></li>
        <?php endif; ?>
        <li><a href="/admin/categorias/">categorias</a></li>
        <?php if (can('inventario.ver')): ?>
           <li><a href="/admin/subcategorias/">subcategorias</a></li>
        <li><a href="/admin/inventario/">Inventario</a></li>
        <?php endif; ?>
        <?php if (can('pedidos.aprobar')): ?>
        <li><a href="/admin/pedidos/">Pedidos</a></li>
        <?php endif; ?>
        <?php if (can('alertas.ver')): ?>
        <li><a href="/admin/alertas/">Alertas</a></li>
        <?php endif; ?>
        <?php if (can('reportes.detallados') || can('reportes.simple')): ?>
        <li><a href="/admin/reportes/">Reportes</a></li>
        <?php endif; ?>
         <?php if (can('ventas.rapidas')): ?>
        <li><a href="/admin/ventas/">Ventas</a></li>
        <?php endif; ?>
        <?php if (can('usuarios.gestionar') || can('usuarios.crear_empleado')): ?>
        <li><a href="/admin/usuarios/">Usuarios</a></li>
        <?php endif; ?>
        <?php if (can('usuarios.gestionar')): ?>
        <li><a href="/admin/roles/">Roles y permisos</a></li>
        <?php endif; ?>
        <li><a href="/admin/ajustes/">Ajustes</a></li>
        <li><a href="/admin/logout.php">Salir</a></li>
      </ul>
    </aside>

    
    <main class="prod-main">
      <div class="inv-title">Panel administrativo</div>

      <?php if ($warns): ?>
        <div class="lp-card" style="border:1px solid #f5d08a;background:#fff9e8;padding:10px;border-radius:10px;margin-bottom:10px">
          <?php foreach($warns as $w) echo '<div>'.$w.'</div>'; ?>
        </div>
      <?php endif; ?>

 
      <div class="row">
        <div class="three columns">
          <div class="prod-card">
            <div class="prod-head"><h5>Productos</h5></div>
            <div style="font-size:26px;font-weight:700"><?= number_format($total_productos,0,',','.') ?></div>
            <div class="muted">Sin stock: <?= number_format($prod_sin_stock,0,',','.') ?></div>
          </div>
        </div>
        <div class="three columns">
          <div class="prod-card">
            <div class="prod-head"><h5>Categorías</h5></div>
            <div style="font-size:26px;font-weight:700"><?= number_format($total_categorias,0,',','.') ?></div>
            <div class="muted">Subcategorías: <?= number_format($total_subcategorias,0,',','.') ?></div>
          </div>
        </div>
        <div class="three columns">
          <div class="prod-card">
            <div class="prod-head"><h5>Proveedores</h5></div>
            <div style="font-size:26px;font-weight:700"><?= number_format($total_proveedores,0,',','.') ?></div>
            <div class="muted">Stock total: <?= number_format($stock_total,0,',','.') ?></div>
          </div>
        </div>
        <div class="three columns">
          <div class="prod-card">
            <div class="prod-head"><h5>Ventas hoy</h5></div>
            <div style="font-size:26px;font-weight:700">$ <?= number_format($ventas_hoy,2,',','.') ?></div>
            <div class="muted" style="color:#b94a48">Bajo stock: <?= number_format($alertas_bajo,0,',','.') ?></div>
          </div>
        </div>
      </div>

    <form method="get" class="prod-filters range">
  <span class="dr-label">Rango</span>
  <div class="dr-fields">
    <input type="date" name="desde" value="<?= h($desde) ?>" aria-label="Desde">
    <span class="dr-sep">→</span>
    <input type="date" name="hasta" value="<?= h($hasta) ?>" aria-label="Hasta">
  </div>
 <button class="btn" type="submit">aplicar</button>
  <a class="btn outline dr-clear" href="/admin/index.php">Limpiar</a>
</form>


      <div class="row">
        
        <div class="six columns">
          <div class="prod-card">
            <div class="prod-head"><h5>Ventas por mes (últimos 12)</h5></div>
            <div class="table-wrap">
              <table class="u-full-width">
                <thead><tr><th>Mes</th><th>Total $</th></tr></thead>
                <tbody>
                  <?php if ($ventas_mes): foreach($ventas_mes as $r): ?>
                    <tr><td><?= h($r['etiqueta']) ?></td><td>$ <?= number_format((float)$r['total'],2,',','.') ?></td></tr>
                  <?php endforeach; else: ?>
                    <tr><td colspan="2" class="muted">Sin datos.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

       
        <div class="six columns">
          <div class="prod-card">
            <div class="prod-head"><h5>Top productos (<?= h($desde) ?> → <?= h($hasta) ?>)</h5></div>
            <div class="table-wrap">
              <table class="u-full-width">
                <thead><tr><th>Producto</th><th style="width:120px">Unid.</th><th style="width:140px">Ingresos $</th></tr></thead>
                <tbody>
                  <?php if ($top_prod): foreach($top_prod as $r): ?>
                    <tr>
                      <td><?= h($r['nombre']) ?></td>
                      <td><?= number_format((int)$r['unidades'],0,',','.') ?></td>
                      <td>$ <?= number_format((float)$r['total'],2,',','.') ?></td>
                    </tr>
                  <?php endforeach; else: ?>
                    <tr><td colspan="3" class="muted">Sin ventas en el rango.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div class="row">
      
        <div class="six columns">
          <div class="prod-card">
            <div class="prod-head"><h5>Ventas recientes</h5></div>
            <div class="table-wrap">
              <table class="u-full-width">
                <thead><tr><th>#</th><th>Fecha/Hora</th><th>Total $</th></tr></thead>
                <tbody>
                  <?php if ($ventas_recientes): foreach($ventas_recientes as $v): ?>
                    <tr>
                      <td>#<?= (int)$v['id_venta'] ?></td>
                      <td><?= h($v['fecha_hora'] ?? '—') ?></td>
                      <td>$ <?= number_format((float)($v['total'] ?? 0),2,',','.') ?></td>
                    </tr>
                  <?php endforeach; else: ?>
                    <tr><td colspan="3" class="muted">Sin ventas registradas.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="six columns">
          <div class="prod-card">
            <div class="prod-head"><h5>Stock por categoría</h5></div>
            <div class="table-wrap">
              <table class="u-full-width">
                <thead><tr><th>Categoría</th><th style="width:140px">Stock total</th></tr></thead>
                <tbody>
                  <?php if ($stock_cat): foreach($stock_cat as $r): ?>
                    <tr><td><?= h($r['categoria'] ?? '—') ?></td><td><?= number_format((int)$r['cant'],0,',','.') ?></td></tr>
                  <?php endforeach; else: ?>
                    <tr><td colspan="2" class="muted">Sin datos.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div class="row">
    
        <div class="six columns">
          <div class="prod-card">
            <div class="prod-head"><h5>Alertas: bajo stock</h5></div>
            <div class="table-wrap">
              <table class="u-full-width">
                <thead><tr><th>Producto</th><th style="width:120px">Stock</th><th style="width:140px">Mínimo</th></tr></thead>
                <tbody>
                  <?php if ($low_list): foreach($low_list as $r):
                    $st  = (int)$r['st']; $min = (int)$r['smin']; ?>
                    <tr>
                      <td><?= h($r['nombre']) ?></td>
                      <td><span class="badge no"><?= $st ?></span></td>
                      <td><?= $min ?></td>
                    </tr>
                  <?php endforeach; else: ?>
                    <tr><td colspan="3" class="muted">Sin alertas.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

           <div class="six columns">
          <div class="prod-card">
            <div class="prod-head">
              <h5>Pedidos pendientes</h5>
              <?php if (can('pedidos.aprobar')): ?>
              <div><a class="btn-sm" href="/pedidos/">Ir a pedidos</a></div>
              <?php endif; ?>
            </div>
            <div class="table-wrap">
              <table class="u-full-width">
                <thead><tr><th>#</th><th>Proveedor</th><th>Estado</th></tr></thead>
                <tbody>
                  <?php if ($ped_pend): foreach($ped_pend as $p): ?>
                    <tr>
                      <td>#<?= (int)$p['id_pedido'] ?></td>
                      <td><?= h($p['proveedor'] ?? '—') ?></td>
                      <td><?= h($p['estado'] ?? 'Pendiente') ?></td>
                    </tr>
                  <?php endforeach; else: ?>
                    <tr><td colspan="3" class="muted">No hay pedidos pendientes.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div class="row">
       
        <div class="six columns">
          <div class="prod-card">
            <div class="prod-head"><h5>Movimientos de stock (recientes)</h5></div>
            <div class="table-wrap">
              <table class="u-full-width">
                <thead><tr><th>#</th><th>Tipo</th><th style="width:120px">Unidades</th></tr></thead>
                <tbody>
                  <?php if ($mov_rec): foreach($mov_rec as $m): ?>
                    <tr>
                      <td>#<?= (int)$m['id_movimiento'] ?></td>
                      <td><?= h($m['tipo'] ?? '—') ?></td>
                      <td><?= number_format((int)($m['unidades'] ?? 0),0,',','.') ?></td>
                    </tr>
                  <?php endforeach; else: ?>
                    <tr><td colspan="3" class="muted">Sin movimientos.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="six columns">
          <div class="prod-card">
            <div class="prod-head"><h5>Alertas (tabla)</h5></div>
            <div class="table-wrap">
              <table class="u-full-width">
                <thead><tr><th>#</th><th>Tipo</th><th>Producto</th><th style="width:120px">Atendida</th></tr></thead>
                <tbody>
                  <?php if ($alertas_tbl): foreach($alertas_tbl as $a): ?>
                    <tr>
                      <td>#<?= (int)$a['id_alerta'] ?></td>
                      <td><?= h($a['tipo'] ?? '—') ?></td>
                      <td><?= h($a['producto'] ?? '—') ?></td>
                      <td><?= isset($a['atendida']) ? ( ((int)$a['atendida']) ? 'Sí' : 'No' ) : '—' ?></td>
                    </tr>
                  <?php endforeach; else: ?>
                    <tr><td colspan="4" class="muted">Sin alertas registradas.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      
      <div class="prod-card">
        <div class="prod-head"><h5>Acciones rápidas</h5></div>
        <div class="row">
          <?php if (can('productos.crear')): ?>
          <div class="four columns"><a class="btn" href="/admin/productos/crear.php">+ Nuevo producto</a></div>
          <?php endif; ?>
          <?php if (can('inventario.ingresar')): ?>
          <div class="four columns"><a class="btn" href="/admin/inventario/ingresar.php">+ Ingreso de stock</a></div>
          <?php endif; ?>
          <?php if (can('ventas.rapidas')): ?>
          <div class="four columns"><a class="btn" href="/admin/ventas/rapida.php"> Venta rápida</a></div>
          <?php endif; ?>
        </div>
      </div>

    </main>
  </div>
</body>
</html>
