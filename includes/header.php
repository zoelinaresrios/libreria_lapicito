<?php if (session_status() === PHP_SESSION_NONE) session_start(); ?>
<!doctype html>
<html lang="es">
<head>
<<<<<<< HEAD
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($page_title ?? 'Los Lapicitos — Admin') ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/8.0.1/normalize.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/skeleton/2.0.4/skeleton.min.css">
<link rel="stylesheet" href="css/style.css">
<style>
  body{background:#f7f8ff}
  .topbar{padding:12px 0;border-bottom:1px solid #e7e9fb;background:#fff;margin-bottom:18px}
  .menu{list-style:none;padding-left:0;margin:0}
  .menu li a{display:block;padding:8px 10px;border-radius:6px;color:#222;text-decoration:none}
  .menu li a:hover{background:#f1f3ff}
  .card{background:#fff;border:1px solid #e7e9fb;border-radius:10px;padding:12px;margin-bottom:12px}
  .kpi{display:flex;justify-content:space-between;align-items:center}
  .kpi .big{font-size:26px;font-weight:700}
  .badge{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid #e0e0e0;font-size:12px}
  .badge.ok{background:#e6ffec;border-color:#b3f0c2}
  .badge.no{background:#ffe6e6;border-color:#ffc2c2}
  .muted{color:#666}
  .table-wrap{overflow:auto}
</style>
=======
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($page_title ?? 'Los Lapicitos — Admin') ?></title>

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Tu estilo -->
  <link href="/admin/css/style.css" rel="stylesheet">
>>>>>>> 4b042df03e95b0a0e0fac717d150c6628a483783
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
