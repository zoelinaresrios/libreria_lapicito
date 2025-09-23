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
require_perm('subcategorias.crear');

if (session_status()===PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$idCatDefault = (int)($_GET['cat'] ?? 0);

// Catálogo 
$cats=[]; $r=$conexion->query("SELECT id_categoria, nombre FROM categoria ORDER BY nombre");
while($row=$r->fetch_assoc()) $cats[]=$row;

$nombre=trim($_POST['nombre'] ?? '');
$idCat =(int)($_POST['id_categoria'] ?? $idCatDefault);

$errors=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) $errors[]='Token inválido.';
  if($idCat<=0) $errors[]='Seleccioná una categoría.';
  if($nombre==='') $errors[]='El nombre es obligatorio.';
  if(mb_strlen($nombre)<3) $errors[]='Mínimo 3 caracteres.';
  if(mb_strlen($nombre)>120) $errors[]='Máximo 120 caracteres.';

  
  if(!$errors){
    $st=$conexion->prepare("SELECT 1 FROM subcategoria WHERE id_categoria=? AND nombre=? LIMIT 1");
    $st->bind_param('is',$idCat,$nombre);
    $st->execute();
    if($st->get_result()->fetch_row()) $errors[]='Ya existe esa subcategoría en la categoría seleccionada.';
    $st->close();
  }

  if(!$errors){
    $st=$conexion->prepare("INSERT INTO subcategoria(id_categoria,nombre) VALUES(?,?)");
    $st->bind_param('is',$idCat,$nombre);
    $st->execute(); $st->close();
    $_SESSION['flash_ok']='Subcategoría creada.';
    header('Location: /admin/subcategorias/?cat='.$idCat); exit;
  }
}
?>
<!doctype html><html lang="es"><head>
<meta charset="utf-8"><title>Nueva subcategoría — Los Lapicitos</title>
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
      <li><a href="/admin/categorias/">Categorías</a></li>
      <li><a class="active" href="/admin/subcategorias/">Subcategorías</a></li>
    </ul>
  </aside>

  <main class="prod-main">
    <div class="inv-title">Nueva subcategoría</div>

    <div class="prod-card">
      <?php if($errors): ?><div class="alert-error"><?php foreach($errors as $e) echo '<div>'.h($e).'</div>'; ?></div><?php endif; ?>

      <form method="post" class="form-vert">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <label>Categoría</label>
        <select name="id_categoria" required>
          <option value="0">Seleccionar…</option>
          <?php foreach($cats as $c): ?>
            <option value="<?= (int)$c['id_categoria'] ?>" <?= $idCat===(int)$c['id_categoria']?'selected':'' ?>>
              <?= h($c['nombre']) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <label>Nombre</label>
        <input class="u-full-width" type="text" name="nombre" maxlength="120" value="<?= h($nombre) ?>" required>

        <div class="form-actions">
          <a class="btn-sm btn-muted" href="/admin/subcategorias/?cat=<?= (int)$idCat ?>">Cancelar</a>
          <button class="btn-filter" type="submit">Crear</button>
        </div>
      </form>
    </div>
  </main>
</div>
</body></html>
