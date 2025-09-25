<?php
// /admin/proveedores/editar.php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';

$HAS_ACL = file_exists(__DIR__ . '/../includes/acl.php');
if ($HAS_ACL) { require_once __DIR__ . '/../includes/acl.php'; }
else {
  if (session_status()===PHP_SESSION_NONE) session_start();
  if (!function_exists('can')) { function can($k){ return true; } }
  if (!function_exists('require_perm')) { function require_perm($k){ return true; } }
}
require_perm('proveedores.editar');

if (session_status()===PHP_SESSION_NONE) session_start();
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
$csrf=$_SESSION['csrf'];

$id=(int)($_GET['id']??0);
if($id<=0){ header('Location: /admin/proveedores/'); exit; }

$prov=$conexion->prepare("SELECT * FROM proveedor WHERE id_proveedor=?");
$prov->bind_param('i',$id); $prov->execute(); $row=$prov->get_result()->fetch_assoc(); $prov->close();
if(!$row){ $_SESSION['flash_err']='Proveedor inexistente.'; header('Location: /admin/proveedores/'); exit; }

$err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(($_POST['csrf']??'')!==$csrf){ $err='CSRF inválido'; }
  else{
    $nombre  = trim($_POST['nombre']??'');
    $email   = trim($_POST['email']??'');
    $tel     = trim($_POST['telefono']??'');
    $dir     = trim($_POST['direccion']??'');
    $contacto= trim($_POST['contacto_referencia']??'');

    if($nombre===''){ $err='El nombre es obligatorio.'; }
    if(!$err){
      $st=$conexion->prepare("UPDATE proveedor
                                 SET nombre=?, email=?, telefono=?, direccion=?, contacto_referencia=?
                               WHERE id_proveedor=?");
      $st->bind_param('sssssi',$nombre,$email,$tel,$dir,$contacto,$id);
      $st->execute(); $st->close();
      $_SESSION['flash_ok']='Proveedor actualizado.';
      header('Location: /admin/proveedores/'); exit;
    }
  }
}
?>
<!doctype html><html lang="es"><head>
<meta charset="utf-8"><title>Editar proveedor</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/vendor/normalize.css">
<link rel="stylesheet" href="/vendor/skeleton.css">
<link rel="stylesheet" href="/css/style.css?v=13">
<link rel="stylesheet" href="/css/toast.css?v=1">
</head>
<body>
<div class="barra"></div>
<div class="prod-shell">
  <aside class="prod-side">
    <ul class="prod-nav">
      <li><a href="/admin/proveedores/">← Volver</a></li>
    </ul>
  </aside>

  <main class="prod-main">
    <div class="inv-title">Editar Proveedor</div>

    <div class="prod-card">
      <?php if($err): ?><div class="alert error"><?=h($err)?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=$csrf?>">
        <div class="row">
          <div class="six columns">
            <label>Nombre *</label>
            <input type="text" name="nombre" required value="<?=h($row['nombre'])?>">
          </div>
          <div class="six columns">
            <label>Contacto / Referente</label>
            <input type="text" name="contacto_referencia" value="<?=h($row['contacto_referencia'])?>">
          </div>
        </div>
        <div class="row">
          <div class="six columns">
            <label>Email</label>
            <input type="email" name="email" value="<?=h($row['email'])?>">
          </div>
          <div class="six columns">
            <label>Teléfono</label>
            <input type="text" name="telefono" value="<?=h($row['telefono'])?>">
          </div>
        </div>
        <label>Dirección</label>
        <input type="text" name="direccion" value="<?=h($row['direccion'])?>">
        <div class="prod-actions">
          <butto
