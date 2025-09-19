<?php if (session_status() === PHP_SESSION_NONE) session_start(); ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($page_title ?? 'Los Lapicitos — Admin') ?></title>

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Tu estilo -->
  <link href="/admin/css/style.css" rel="stylesheet">
</head>
<body class="min-vh-100">

  <div class="container">
    <!-- Topbar -->
    <div class="row align-items-center topbar">
      <div class="col-12 col-md-9">
        <h5 class="m-0">Los Lapicitos — Panel Administrativo</h5>
        <span class="text-muted">Dashboard</span>
      </div>
      <div class="col-12 col-md-3 text-md-end mt-2 mt-md-0">
        <a class="btn btn-outline-secondary btn-sm" href="/admin/logout.php">Salir</a>
      </div>
    </div>

    <!-- Card -->
    <div class="card kpi-card mb-3">
      <div class="card-body d-flex justify-content-between align-items-center">
        <div>
          <div class="fw-semibold">Ventas de hoy</div>
          <small class="text-muted">Resumen rápido</small>
        </div>
        <div class="big">$ 12.350</div>
      </div>
    </div>

    <!-- Badges -->
    <p class="mb-3">
      <span class="badge rounded-pill badge-ok">OK</span>
      <span class="badge rounded-pill badge-no">Sin stock</span>
    </p>

    <!-- Tabla -->
    <div class="table-responsive">
      <table class="table align-middle table-sm">
        <thead>
          <tr>
            <th>Producto</th><th>Stock</th><th>Precio</th>
          </tr>
        </thead>
        <tbody>
          <tr><td>Ejemplo A</td><td>12</td><td>$1.500</td></tr>
          <tr><td>Ejemplo B</td><td>0</td><td>$2.300</td></tr>
        </tbody>
      </table>
    </div>

  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
