<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/../includes/db.php';

try {
  $cn = db();
  echo "<h3>Conexión OK a la base: <code>".DB_NAME."</code></h3>";


  $base = $cn->query("SELECT DATABASE() AS db")->fetch_assoc()['db'] ?? '(desconocida)';
  echo "<p>DATABASE(): <b>{$base}</b></p>";

  
  $res = $cn->query("SHOW TABLES LIKE 'usuario'");
  if ($res->num_rows === 0) {
    echo "<p><b>Falta la tabla <code>usuario</code></b>. Creá el esquema con el SQL de abajo.</p>";
    exit;
  }

  
  $cols = $cn->query("SHOW COLUMNS FROM usuario")->fetch_all(MYSQLI_ASSOC);
  echo "<pre>"; print_r($cols); echo "</pre>";

} catch (Throwable $e) {
  echo "<h3>ERROR</h3><pre>".$e->getMessage()."</pre>";
}
