<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');
if (function_exists('mb_internal_encoding')) mb_internal_encoding('UTF-8');

require_once __DIR__ . '/../includes/auth_apoderado.php';
require_once __DIR__ . '/../includes/db.php';

@mysqli_set_charset($conn, 'utf8mb4');
if (!isset($conn) || !($conn instanceof mysqli)) { die('Error DB'); }

function limpiar($v, $max = 0) {
  $v = trim((string)$v);
  if ($max > 0 && function_exists('mb_substr')) return mb_substr($v, 0, $max, 'UTF-8');
  if ($max > 0) return substr($v, 0, $max);
  return $v;
}

function validar_fecha($f) {
  $f = trim((string)$f);
  if ($f === '') return null;
  return preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) ? $f : null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: ficha_matricula.php?error=' . urlencode('Solicitud no válida'));
  exit;
}

if (!isset($_SESSION['alumno_rut'])) {
  header('Location: login.php');
  exit;
}

if (($_POST['csrf_token'] ?? '') !== session_id()) {
  header('Location: ficha_matricula.php?error=' . urlencode('Sesión no válida. Intenta nuevamente.'));
  exit;
}

$alumno_rut_sesion = (string)$_SESSION['alumno_rut'];
$alumno_id = 0;

$stmt = $conn->prepare('SELECT id FROM alumnos WHERE rut = ? LIMIT 1');
if (!$stmt) { die('No se pudo validar el alumno'); }
$stmt->bind_param('s', $alumno_rut_sesion);
$stmt->execute();
$stmt->bind_result($alumno_id_db);
if ($stmt->fetch()) { $alumno_id = (int)$alumno_id_db; }
$stmt->close();

if ($alumno_id <= 0) {
  header('Location: login.php');
  exit;
}

// Datos básicos editables de la tabla alumnos
$nombre = limpiar($_POST['nombre'] ?? '', 160);
$rut = limpiar($_POST['rut'] ?? '', 20);
$fecha_nacimiento = validar_fecha($_POST['fecha_nacimiento'] ?? '');
$talla_uniforme = limpiar($_POST['talla_uniforme'] ?? '', 20);
$nombre_apoderado = limpiar($_POST['nombre_apoderado'] ?? '', 160);
$rut_apoderado = limpiar($_POST['rut_apoderado'] ?? '', 20);
$correo = limpiar($_POST['correo'] ?? '', 160);
$celular_apoderado = limpiar($_POST['celular_apoderado'] ?? '', 30);
$firma_apoderado = limpiar($_POST['firma_apoderado'] ?? '', 160);

// Datos complementarios de alumno_fichas
$direccion = limpiar($_POST['direccion'] ?? '', 180);
$ciudad = limpiar($_POST['ciudad'] ?? '', 100);
$tipo_jugador = limpiar($_POST['tipo_jugador'] ?? 'Jugador', 20);
if (!in_array($tipo_jugador, ['Jugador', 'Arquero'], true)) $tipo_jugador = 'Jugador';

$prevision_salud = limpiar($_POST['prevision_salud'] ?? '', 120);
$lesiones = limpiar($_POST['lesiones'] ?? '');
$alergia_enfermedad = limpiar($_POST['alergia_enfermedad'] ?? '');

$emergencia_nombre = limpiar($_POST['emergencia_nombre'] ?? '', 140);
$emergencia_celular = limpiar($_POST['emergencia_celular'] ?? '', 30);
$emergencia_parentesco = limpiar($_POST['emergencia_parentesco'] ?? '', 80);

$contacto2_nombre = limpiar($_POST['contacto2_nombre'] ?? '', 140);
$contacto2_celular = limpiar($_POST['contacto2_celular'] ?? '', 30);
$contacto2_parentesco = limpiar($_POST['contacto2_parentesco'] ?? '', 80);

$compromiso_aceptado = isset($_POST['compromiso_aceptado']) ? 1 : 0;
$fecha_firma = validar_fecha($_POST['fecha_firma'] ?? '');
if ($fecha_firma === null) $fecha_firma = date('Y-m-d');

if ($nombre === '' || $rut === '' || $nombre_apoderado === '' || $celular_apoderado === '' || $direccion === '' || $ciudad === '' || $emergencia_nombre === '' || $emergencia_celular === '' || $emergencia_parentesco === '' || $firma_apoderado === '' || !$compromiso_aceptado) {
  header('Location: ficha_matricula.php?error=' . urlencode('Completa los datos obligatorios y acepta el compromiso.'));
  exit;
}

if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
  header('Location: ficha_matricula.php?error=' . urlencode('El correo del apoderado no tiene un formato válido.'));
  exit;
}

$observaciones = '';

try {
  if (method_exists($conn, 'begin_transaction')) $conn->begin_transaction();

  // Si el apoderado modifica el RUT del alumno, validar que no exista en otro alumno.
  if ($st = $conn->prepare('SELECT id FROM alumnos WHERE rut = ? AND id <> ? LIMIT 1')) {
    $st->bind_param('si', $rut, $alumno_id);
    $st->execute();
    $st->store_result();
    if ($st->num_rows > 0) {
      $st->close();
      throw new Exception('RUT duplicado');
    }
    $st->close();
  }

  $sqlAlu = "
    UPDATE alumnos
    SET
      nombre = ?,
      rut = ?,
      fecha_nacimiento = ?,
      talla_uniforme = ?,
      nombre_apoderado = ?,
      rut_apoderado = ?,
      correo = ?,
      celular_apoderado = ?
    WHERE id = ?
    LIMIT 1
  ";
  $stmtAlu = $conn->prepare($sqlAlu);
  if (!$stmtAlu) throw new Exception('No se pudo preparar actualización de alumno');
  $stmtAlu->bind_param(
    'ssssssssi',
    $nombre,
    $rut,
    $fecha_nacimiento,
    $talla_uniforme,
    $nombre_apoderado,
    $rut_apoderado,
    $correo,
    $celular_apoderado,
    $alumno_id
  );
  $stmtAlu->execute();
  $stmtAlu->close();

  $sql = "
    INSERT INTO alumno_fichas (
      alumno_id,
      direccion,
      ciudad,
      tipo_jugador,
      prevision_salud,
      lesiones,
      alergia_enfermedad,
      emergencia_nombre,
      emergencia_celular,
      emergencia_parentesco,
      contacto2_nombre,
      contacto2_celular,
      contacto2_parentesco,
      compromiso_aceptado,
      fecha_firma,
      observaciones
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      direccion = VALUES(direccion),
      ciudad = VALUES(ciudad),
      tipo_jugador = VALUES(tipo_jugador),
      prevision_salud = VALUES(prevision_salud),
      lesiones = VALUES(lesiones),
      alergia_enfermedad = VALUES(alergia_enfermedad),
      emergencia_nombre = VALUES(emergencia_nombre),
      emergencia_celular = VALUES(emergencia_celular),
      emergencia_parentesco = VALUES(emergencia_parentesco),
      contacto2_nombre = VALUES(contacto2_nombre),
      contacto2_celular = VALUES(contacto2_celular),
      contacto2_parentesco = VALUES(contacto2_parentesco),
      compromiso_aceptado = VALUES(compromiso_aceptado),
      fecha_firma = VALUES(fecha_firma),
      updated_at = CURRENT_TIMESTAMP
  ";

  $stmt = $conn->prepare($sql);
  if (!$stmt) throw new Exception('No se pudo preparar el guardado');

  $stmt->bind_param(
    'issssssssssssiss',
    $alumno_id,
    $direccion,
    $ciudad,
    $tipo_jugador,
    $prevision_salud,
    $lesiones,
    $alergia_enfermedad,
    $emergencia_nombre,
    $emergencia_celular,
    $emergencia_parentesco,
    $contacto2_nombre,
    $contacto2_celular,
    $contacto2_parentesco,
    $compromiso_aceptado,
    $fecha_firma,
    $observaciones
  );
  $stmt->execute();
  $stmt->close();

  $_SESSION['alumno_rut'] = $rut;
  $_SESSION['alumno_id'] = $alumno_id;

  if (method_exists($conn, 'commit')) $conn->commit();
} catch (Throwable $e) {
  if (method_exists($conn, 'rollback')) $conn->rollback();
  $msg = ($e->getMessage() === 'RUT duplicado')
    ? 'El RUT ingresado ya pertenece a otro alumno.'
    : 'No se pudo guardar la ficha';
  header('Location: ficha_matricula.php?error=' . urlencode($msg));
  exit;
}

header('Location: ficha_matricula.php?ok=1');
exit;
