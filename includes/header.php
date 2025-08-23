<?php if (session_status() === PHP_SESSION_NONE) session_start(); ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($page_title ?? 'Los Lapicitos — Admin') ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/8.0.1/normalize.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/skeleton/2.0.4/skeleton.min.css">
<link rel="stylesheet" href="/libreria_lapicito/admin/css/style.css">
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
</head>
<body>
<div class="container">
  <div class="row topbar">
    <div class="nine columns">
      <h5 style="margin:0">Los Lapicitos — Panel Administrativo</h5>
      <span class="muted">Dashboard</span>
    </div>
    <div class="three columns" style="text-align:right">
      <a class="button button-outline" href="/libreria_lapicito/admin/logout.php">Salir</a>
    </div>
  </div>
