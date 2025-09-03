<?php
include(__DIR__ . '/../../includes/db.php');
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/acl.php';

if (function_exists('is_logged') && !is_logged()) {
  header('Location: /libreria_lapicito/admin/login.php'); exit;
}
require_perm('productos.eliminar');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (session_status()===PHP_SESSION_NONE) session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo "Método no permitido"; exit;
}

$id   = (int)($_POST['id'] ?? 0);
$csrf = $_POST['csrf'] ?? '';

if (!$id || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
  http_response_code(400); echo "Solicitud inválida"; exit;
}

try {
  $conexion->begin_transaction();

  $st=$conexion->prepare("DELETE FROM producto WHERE id_producto=? LIMIT 1");
  $st->bind_param('i',$id);
  $st->execute();
  $ok = ($st->affected_rows===1);
  $st->close();

  if ($ok) {
    $conexion->commit();
    header('Location: /libreria_lapicito/admin/productos/?msg=Producto eliminado');
    exit;
  } else {
    $conexion->rollback();
    header('Location: /libreria_lapicito/admin/productos/?msg=Producto no encontrado');
    exit;
  }

} catch (mysqli_sql_exception $e) {
  if ((int)$e->getCode()===1451) {
    try {
      $hasArchivado = false;
      $res = $conexion->query("SHOW COLUMNS FROM producto LIKE 'archivado'");
      if ($res && $res->num_rows > 0) $hasArchivado = true;

      if ($hasArchivado) {
        $st=$conexion->prepare("UPDATE producto SET archivado=1, activo=0, actualizado_en=NOW() WHERE id_producto=?");
      } else {
        $st=$conexion->prepare("UPDATE producto SET activo=0, actualizado_en=NOW() WHERE id_producto=?");
      }
      $st->bind_param('i',$id);
      $st->execute();
      $st->close();

      $conexion->commit();
      header('Location: /libreria_lapicito/admin/productos/?msg=Producto archivado (tenía referencias)');
      exit;

    } catch (Throwable $e2) {
      $conexion->rollback();
      http_response_code(500);
      echo "No se pudo archivar: ".$e2->getMessage(); exit;
    }
  }
  $conexion->rollback();
  http_response_code(500);
  echo "Error al eliminar: ".$e->getMessage();
  exit;
}
