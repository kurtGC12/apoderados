<?php
// apoderados/_diag_panel.php
header('Content-Type: text/plain; charset=UTF-8');
error_reporting(E_ALL);
ini_set('display_errors','1');

echo "== DIAGNOSTICO PANEL APODERADO ==\n";

function ok($k,$v){ echo str_pad($k,28,'.')." ".$v."\n"; }
function ex($k,$b){ ok($k, $b ? 'OK' : 'FALTA'); }

ok('PHP version', PHP_VERSION);

$base = __DIR__;
$act  = realpath($base . '/../actividades');
ok('apoderados path', $base);
ok('actividades path', $act ?: 'NO ENCONTRADO');

$act_panel = $base . '/../actividades/panel_apoderado_actividades.php';
$act_help  = $base . '/../actividades/actividad_helpers.php';
ex('archivo actividades/panel', is_file($act_panel));
ex('archivo actividades/helpers', is_file($act_help));

$db = $base . '/../includes/db.php';
ex('includes/db.php', is_file($db));
if (is_file($db)) {
  require $db;
  if (!isset($conn) || !$conn) { ok('DB conn', 'NO'); }
  else {
    ok('DB conn', 'OK');
    $rs = $conn->query("SELECT 1");
    ok('DB SELECT 1', $rs ? 'OK' : ('ERR: '.$conn->error));

    // ¿existen tablas de actividades?
    foreach (['actividades','actividad_participantes','actividad_pagos_transferencia'] as $t) {
      $esc = $conn->real_escape_string($t);
      $rs  = $conn->query("SHOW TABLES LIKE '{$esc}'");
      ok("tabla {$t}", ($rs && $rs->num_rows>0) ? 'OK' : 'NO');
    }
  }
}

// prueba include protegido (sin ejecutar lógica del panel grande)
echo "\n== PROBANDO INCLUDE ACTIVIDADES ==\n";
if (is_file($act_panel)) {
  // variables mínimas esperadas por el bloque
  $alumno_id = 0; $escuela_id = null; $categoria_id = null;
  ob_start();
  try {
    include $act_panel;
    $out = ob_get_clean();
    echo "include actividades -> OK (generó HTML de longitud ".strlen($out).")\n";
  } catch (Throwable $e) {
    ob_end_clean();
    echo "include actividades -> FATAL: ".$e->getMessage()." in ".$e->getFile().":".$e->getLine()."\n";
  } catch (Exception $e) {
    ob_end_clean();
    echo "include actividades -> FATAL: ".$e->getMessage()." in ".$e->getFile().":".$e->getLine()."\n";
  }
} else {
  echo "include actividades -> archivo no existe\n";
}

echo "\nListo.\n";
