<?php
// includes/header.php
require_once __DIR__.'/auth.php';
$u = current_user();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Panel Admin — Los Lapicitos</title>
  <link rel="stylesheet" href="/los-lapicitos/admin/assets/css/admin.css">
</head>
<body>
<header class="topbar">
  <div class="brand">
    <img src="/los-lapicitos/admin/assets/img/logo.svg" alt="Los Lapicitos" class="logo">
    <span>Panel Administrativo</span>
  </div>
  <nav class="topnav">
    <?php if($u): ?>
      <span class="user">👤 <?=htmlspecialchars($u['nombre'])?> (<?= (int)$u['id_rol'] === 1 ? 'Admin' : ((int)$u['id_rol']===2?'Supervisor':'Empleado')?>)</span>
      <a href="/los-lapicitos/admin/logout.php" class="btn">Salir</a>
    <?php endif; ?>
  </nav>
</header>

<aside class="sidebar">
  <a href="/los-lapicitos/admin/index.php">Dashboard</a>
  <h4>Gestión</h4>
  <a href="/los-lapicitos/admin/usuarios/index.php">Usuarios</a>
  <a href="/los-lapicitos/admin/productos/index.php">Productos</a>
  <a href="/los-lapicitos/admin/categorias/index.php">Categorías</a>
  <a href="/los-lapicitos/admin/proveedores/index.php">Proveedores</a>
  <a href="/los-lapicitos/admin/inventario/index.php">Inventario</a>
  <a href="/los-lapicitos/admin/pedidos/index.php">Pedidos</a>
  <a href="/los-lapicitos/admin/alertas/index.php">Alertas</a>
  <a href="/los-lapicitos/admin/reportes/index.php">Reportes</a>
  <a href="/los-lapicitos/admin/sucursales/index.php">Sucursales</a>
  <a href="/los-lapicitos/admin/ajustes/index.php">Ajustes</a>
</aside>

<main class="content">
