<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');
if (function_exists('mb_internal_encoding')) mb_internal_encoding('UTF-8');

require_once __DIR__ . '/../includes/auth_apoderado.php';
require_once __DIR__ . '/../includes/db.php';

@mysqli_set_charset($conn, 'utf8mb4');
if (!isset($conn) || !($conn instanceof mysqli)) { die('Error DB'); }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fecha_cl($f){ return !empty($f) ? date('d-m-Y', strtotime($f)) : '—'; }

function ficha_table_exists(mysqli $conn, string $table): bool {
  static $cache = [];
  if (array_key_exists($table, $cache)) return $cache[$table];
  $t = $conn->real_escape_string($table);
  $q = @$conn->query("SHOW TABLES LIKE '{$t}'");
  return $cache[$table] = ($q && $q->num_rows > 0);
}

function ficha_column_exists(mysqli $conn, string $table, string $column): bool {
  static $cache = [];
  $key = $table . '.' . $column;
  if (array_key_exists($key, $cache)) return $cache[$key];
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($column);
  $q = @$conn->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
  return $cache[$key] = ($q && $q->num_rows > 0);
}

function ficha_dia_vencimiento_cuota(mysqli $conn, int $escuela_id): int {
  $dia = 5;
  if (!ficha_table_exists($conn, 'configuracion_matricula')) return $dia;

  $anio = (int)date('Y');
  $tieneEscuela = ficha_column_exists($conn, 'configuracion_matricula', 'escuela_id');

  if ($tieneEscuela && $escuela_id > 0) {
    $sql = "
      SELECT dia_vencimiento
      FROM configuracion_matricula
      WHERE anio = ? AND escuela_id = ?
      ORDER BY id DESC
      LIMIT 1
    ";
    if ($st = $conn->prepare($sql)) {
      $st->bind_param('ii', $anio, $escuela_id);
      $st->execute();
      $st->bind_result($diaDb);
      if ($st->fetch() && (int)$diaDb > 0) $dia = (int)$diaDb;
      $st->close();
    }
    if ($dia > 0 && $dia !== 5) return min(31, max(1, $dia));
  }

  if ($tieneEscuela) {
    $sql = "
      SELECT dia_vencimiento
      FROM configuracion_matricula
      WHERE anio = ? AND (escuela_id IS NULL OR escuela_id = 0)
      ORDER BY id DESC
      LIMIT 1
    ";
  } else {
    $sql = "
      SELECT dia_vencimiento
      FROM configuracion_matricula
      WHERE anio = ?
      ORDER BY id DESC
      LIMIT 1
    ";
  }

  if ($st = $conn->prepare($sql)) {
    $st->bind_param('i', $anio);
    $st->execute();
    $st->bind_result($diaDb);
    if ($st->fetch() && (int)$diaDb > 0) $dia = (int)$diaDb;
    $st->close();
  }

  if ($tieneEscuela && $escuela_id > 0 && $dia === 5) {
    $sql = "
      SELECT dia_vencimiento
      FROM configuracion_matricula
      WHERE escuela_id = ?
      ORDER BY anio DESC, id DESC
      LIMIT 1
    ";
    if ($st = $conn->prepare($sql)) {
      $st->bind_param('i', $escuela_id);
      $st->execute();
      $st->bind_result($diaDb);
      if ($st->fetch() && (int)$diaDb > 0) $dia = (int)$diaDb;
      $st->close();
    }
  }

  if ($dia === 5) {
    if ($q = @$conn->query("SELECT dia_vencimiento FROM configuracion_matricula ORDER BY anio DESC, id DESC LIMIT 1")) {
      if ($row = $q->fetch_assoc()) {
        if ((int)($row['dia_vencimiento'] ?? 0) > 0) $dia = (int)$row['dia_vencimiento'];
      }
    }
  }

  return min(31, max(1, $dia));
}

function ficha_link_embed(string $url, bool $isEmbed): string {
  if (!$isEmbed) return $url;
  if (strpos($url, 'embed=1') !== false) return $url;
  return $url . (strpos($url, '?') === false ? '?' : '&') . 'embed=1&modal=1';
}

function ficha_redirect_self(array $extra = []): void {
  $base = strtok($_SERVER['REQUEST_URI'] ?? 'ficha_matricula.php', '?');
  $keep = [];
  if (isset($_GET['embed'])) $keep['embed'] = $_GET['embed'];
  if (isset($_GET['modal'])) $keep['modal'] = $_GET['modal'];
  $qs = http_build_query(array_merge($keep, $extra));
  header('Location: ' . $base . ($qs ? ('?' . $qs) : ''));
  exit;
}

function ficha_photo_column(mysqli $conn, bool $createIfMissing = false): string {
  $posibles = ['foto_alumno', 'foto_jugador', 'foto', 'imagen_alumno', 'imagen', 'avatar'];
  foreach ($posibles as $col) {
    if (ficha_column_exists($conn, 'alumnos', $col)) return $col;
  }
  if ($createIfMissing) {
    @$conn->query("ALTER TABLE alumnos ADD COLUMN foto_alumno VARCHAR(255) NULL DEFAULT NULL");
    if (ficha_column_exists($conn, 'alumnos', 'foto_alumno')) return 'foto_alumno';
  }
  return '';
}

function ficha_photo_src(string $path): string {
  $path = trim($path);
  if ($path === '') return '';
  if (preg_match('~^https?://~i', $path)) return $path;
  if (strpos($path, '/') === 0) return $path;
  return '../' . ltrim($path, '/');
}

function ficha_image_resource(string $tmp, string $mime) {
  if ($mime === 'image/jpeg') return @imagecreatefromjpeg($tmp);
  if ($mime === 'image/png') return @imagecreatefrompng($tmp);
  if ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) return @imagecreatefromwebp($tmp);
  return false;
}

function ficha_apply_exif_orientation($src, string $tmp, string $mime) {
  // Los celulares suelen guardar la foto horizontal y registrar la orientación en EXIF.
  // Si no corregimos esto antes de recortar, la foto puede verse girada en la ficha.
  if ($mime !== 'image/jpeg') return $src;
  if (!function_exists('exif_read_data')) return $src;

  $exif = @exif_read_data($tmp);
  if (!is_array($exif) || empty($exif['Orientation'])) return $src;

  $orientation = (int)$exif['Orientation'];
  $rotated = false;

  switch ($orientation) {
    case 2:
      if (function_exists('imageflip')) imageflip($src, IMG_FLIP_HORIZONTAL);
      break;
    case 3:
      $rotated = imagerotate($src, 180, 0);
      break;
    case 4:
      if (function_exists('imageflip')) imageflip($src, IMG_FLIP_VERTICAL);
      break;
    case 5:
      if (function_exists('imageflip')) imageflip($src, IMG_FLIP_VERTICAL);
      $rotated = imagerotate($src, -90, 0);
      break;
    case 6:
      $rotated = imagerotate($src, -90, 0);
      break;
    case 7:
      if (function_exists('imageflip')) imageflip($src, IMG_FLIP_HORIZONTAL);
      $rotated = imagerotate($src, -90, 0);
      break;
    case 8:
      $rotated = imagerotate($src, 90, 0);
      break;
  }

  if ($rotated) {
    imagedestroy($src);
    return $rotated;
  }

  return $src;
}

function ficha_resize_and_save_photo(string $tmp, string $destBaseAbs, string $mime, int $target = 720): array {
  if (!function_exists('imagecreatetruecolor')) {
    return [false, '', 'El servidor no tiene GD habilitado para ajustar imágenes.'];
  }

  $src = ficha_image_resource($tmp, $mime);
  if (!$src) return [false, '', 'No se pudo procesar la imagen seleccionada.'];

  $src = ficha_apply_exif_orientation($src, $tmp, $mime);

  $w = imagesx($src);
  $h = imagesy($src);
  if ($w <= 0 || $h <= 0) {
    imagedestroy($src);
    return [false, '', 'La imagen no tiene dimensiones válidas.'];
  }

  $side = min($w, $h);
  $sx = (int)(($w - $side) / 2);
  $sy = (int)(($h - $side) / 2);

  $dst = imagecreatetruecolor($target, $target);
  imagealphablending($dst, true);
  imagesavealpha($dst, true);
  $white = imagecolorallocate($dst, 255, 255, 255);
  imagefilledrectangle($dst, 0, 0, $target, $target, $white);
  imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $target, $target, $side, $side);

  if (function_exists('imagewebp')) {
    $ok = imagewebp($dst, $destBaseAbs . '.webp', 88);
    $ext = 'webp';
  } else {
    $ok = imagejpeg($dst, $destBaseAbs . '.jpg', 90);
    $ext = 'jpg';
  }

  imagedestroy($src);
  imagedestroy($dst);

  return $ok ? [true, $ext, ''] : [false, '', 'No se pudo guardar la foto ajustada.'];
}

function ficha_handle_photo_upload(mysqli $conn, int $alumnoId): void {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
  if (!isset($_POST['accion_foto_alumno'])) return;

  $csrf = (string)($_POST['csrf_token'] ?? '');
  if ($csrf === '' || $csrf !== session_id()) ficha_redirect_self(['error' => 'Sesión no válida para subir la foto.']);

  if (empty($_FILES['foto_alumno']) || !is_array($_FILES['foto_alumno'])) {
    ficha_redirect_self(['error' => 'No se recibió ninguna foto.']);
  }

  $file = $_FILES['foto_alumno'];
  if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    ficha_redirect_self(['error' => 'No se pudo subir la foto. Intenta nuevamente.']);
  }

  if ((int)($file['size'] ?? 0) > 6 * 1024 * 1024) {
    ficha_redirect_self(['error' => 'La foto supera el máximo permitido de 6 MB.']);
  }

  $tmp = (string)($file['tmp_name'] ?? '');
  $info = @getimagesize($tmp);
  $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
  $permitidos = ['image/jpeg', 'image/png', 'image/webp'];

  if (!in_array($mime, $permitidos, true)) {
    ficha_redirect_self(['error' => 'Formato no permitido. Usa JPG, PNG o WEBP.']);
  }

  $colFoto = ficha_photo_column($conn, true);
  if ($colFoto === '') {
    ficha_redirect_self(['error' => 'No se pudo preparar la columna foto_alumno en la tabla alumnos.']);
  }

  $rootAbs = realpath(__DIR__ . '/..');
  if (!$rootAbs) ficha_redirect_self(['error' => 'No se pudo resolver la ruta principal del sistema.']);

  $uploadRelDir = 'foto_alumnos';
  $uploadAbsDir = $rootAbs . '/' . $uploadRelDir;

  if (!is_dir($uploadAbsDir)) @mkdir($uploadAbsDir, 0775, true);
  if (!is_dir($uploadAbsDir) || !is_writable($uploadAbsDir)) {
    ficha_redirect_self(['error' => 'La carpeta foto_alumnos no existe o no tiene permisos de escritura.']);
  }

  $baseName = 'alumno_' . $alumnoId . '_' . date('YmdHis');
  [$ok, $ext, $msg] = ficha_resize_and_save_photo($tmp, $uploadAbsDir . '/' . $baseName, $mime, 720);

  if (!$ok) ficha_redirect_self(['error' => $msg ?: 'No se pudo ajustar la foto.']);

  $pathRel = $uploadRelDir . '/' . $baseName . '.' . $ext;
  $sql = "UPDATE alumnos SET `$colFoto` = ? WHERE id = ? LIMIT 1";
  $st = $conn->prepare($sql);
  if (!$st) ficha_redirect_self(['error' => 'No se pudo preparar el guardado de la foto.']);

  $st->bind_param('si', $pathRel, $alumnoId);
  $saved = $st->execute();
  $st->close();

  if (!$saved) ficha_redirect_self(['error' => 'No se pudo actualizar la foto del alumno.']);
  ficha_redirect_self(['ok_foto' => '1']);
}

function inicial_alumno(string $nombre): string {
  $nombre = trim($nombre);
  if ($nombre === '') return 'A';
  return function_exists('mb_substr') ? mb_strtoupper(mb_substr($nombre, 0, 1, 'UTF-8'), 'UTF-8') : strtoupper(substr($nombre, 0, 1));
}

if (!isset($_SESSION['alumno_rut'])) {
  header('Location: login.php');
  exit;
}

$alumno_rut = (string)$_SESSION['alumno_rut'];
$alumno = null;

$sqlAlumno = "
  SELECT
    a.id,
    a.nombre,
    a.rut,
    a.fecha_ingreso,
    a.fecha_nacimiento,
    a.categoria,
    a.escuela_id,
    a.nombre_apoderado,
    a.rut_apoderado,
    a.correo,
    a.celular_apoderado,
    a.talla_uniforme,
    e.nombre AS escuela_nombre
  FROM alumnos a
  LEFT JOIN escuelas e ON e.id = a.escuela_id
  WHERE a.rut = ?
  LIMIT 1
";

$stmt = $conn->prepare($sqlAlumno);
if (!$stmt) { die('No se pudo preparar la consulta del alumno'); }
$stmt->bind_param('s', $alumno_rut);
$stmt->execute();
$stmt->bind_result(
  $id,
  $nombre,
  $rut,
  $fecha_ingreso,
  $fecha_nacimiento,
  $categoria,
  $escuela_id,
  $nombre_apoderado,
  $rut_apoderado,
  $correo,
  $celular_apoderado,
  $talla_uniforme,
  $escuela_nombre
);
if ($stmt->fetch()) {
  $alumno = [
    'id' => $id,
    'nombre' => $nombre,
    'rut' => $rut,
    'fecha_ingreso' => $fecha_ingreso,
    'fecha_nacimiento' => $fecha_nacimiento,
    'categoria' => $categoria,
    'escuela_id' => $escuela_id,
    'nombre_apoderado' => $nombre_apoderado,
    'rut_apoderado' => $rut_apoderado,
    'correo' => $correo,
    'celular_apoderado' => $celular_apoderado,
    'talla_uniforme' => $talla_uniforme,
    'escuela_nombre' => $escuela_nombre,
  ];
}
$stmt->close();

if (!$alumno) {
  http_response_code(403);
  exit('Alumno no válido');
}

ficha_handle_photo_upload($conn, (int)$alumno['id']);

$fotoAlumno = '';
$fotoColumna = ficha_photo_column($conn, false);
if ($fotoColumna !== '') {
  $sqlFoto = "SELECT `$fotoColumna` FROM alumnos WHERE id = ? LIMIT 1";
  if ($stFoto = $conn->prepare($sqlFoto)) {
    $stFoto->bind_param('i', $alumno['id']);
    $stFoto->execute();
    $stFoto->bind_result($fotoDb);
    if ($stFoto->fetch()) $fotoAlumno = trim((string)$fotoDb);
    $stFoto->close();
  }
}
$fotoAlumnoSrc = ficha_photo_src($fotoAlumno);

$dia_vencimiento_cuota = ficha_dia_vencimiento_cuota($conn, (int)$alumno['escuela_id']);
$dia_vencimiento_cuota_txt = str_pad((string)$dia_vencimiento_cuota, 2, '0', STR_PAD_LEFT);

$ficha = [
  'direccion' => '',
  'ciudad' => '',
  'tipo_jugador' => 'Jugador',
  'prevision_salud' => '',
  'lesiones' => '',
  'alergia_enfermedad' => '',
  'emergencia_nombre' => '',
  'emergencia_celular' => '',
  'emergencia_parentesco' => '',
  'contacto2_nombre' => '',
  'contacto2_celular' => '',
  'contacto2_parentesco' => '',
  'compromiso_aceptado' => 0,
  'fecha_firma' => date('Y-m-d'),
  'updated_at' => null,
];

$sqlFicha = "
  SELECT
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
    updated_at
  FROM alumno_fichas
  WHERE alumno_id = ?
  LIMIT 1
";
$stmt = $conn->prepare($sqlFicha);
if ($stmt) {
  $stmt->bind_param('i', $alumno['id']);
  $stmt->execute();
  $stmt->bind_result(
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
    $updated_at
  );
  if ($stmt->fetch()) {
    $ficha = [
      'direccion' => $direccion,
      'ciudad' => $ciudad,
      'tipo_jugador' => $tipo_jugador ?: 'Jugador',
      'prevision_salud' => $prevision_salud,
      'lesiones' => $lesiones,
      'alergia_enfermedad' => $alergia_enfermedad,
      'emergencia_nombre' => $emergencia_nombre,
      'emergencia_celular' => $emergencia_celular,
      'emergencia_parentesco' => $emergencia_parentesco,
      'contacto2_nombre' => $contacto2_nombre,
      'contacto2_celular' => $contacto2_celular,
      'contacto2_parentesco' => $contacto2_parentesco,
      'compromiso_aceptado' => (int)$compromiso_aceptado,
      'fecha_firma' => $fecha_firma ?: date('Y-m-d'),
      'updated_at' => $updated_at,
    ];
  }
  $stmt->close();
}

$completa = !empty($ficha['compromiso_aceptado']);
$isEmbed = (isset($_GET['embed']) && $_GET['embed'] == '1') || (isset($_GET['modal']) && $_GET['modal'] == '1');
$inicialAlumno = inicial_alumno((string)$alumno['nombre']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Ficha de Matrícula</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="../assets/css/sistema.css?v=31" rel="stylesheet">
<style>
:root{
  --pf-bg:#eef8f7;
  --pf-card:#ffffff;
  --pf-soft:#f6fffd;
  --pf-line:rgba(15,118,110,.14);
  --pf-text:#0f172a;
  --pf-muted:#64748b;
  --pf-teal:#0f766e;
  --pf-teal-dark:#115e59;
  --pf-teal-2:#14b8a6;
  --pf-cyan:#0891b2;
  --pf-shadow:0 18px 42px rgba(15,23,42,.08);
}

*{font-family:'Manrope',system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}

html,body{min-height:100%;}
body.ficha-body{
  margin:0;
  background:
    radial-gradient(circle at top left, rgba(20,184,166,.16), transparent 34%),
    radial-gradient(circle at top right, rgba(8,145,178,.11), transparent 32%),
    linear-gradient(180deg,#f8fffd 0%,var(--pf-bg) 100%);
  color:var(--pf-text);
  font-size:12.5px;
}

.ficha-page{
  width:min(1040px,calc(100% - 18px));
  margin:0 auto;
  padding:8px 0 18px;
}

.ficha-shell{
  border:1px solid rgba(15,118,110,.13);
  border-radius:22px;
  background:rgba(255,255,255,.72);
  box-shadow:var(--pf-shadow);
  backdrop-filter:blur(14px);
  overflow:hidden;
}

/* HERO compacto */
.ficha-hero{
  display:grid;
  grid-template-columns:minmax(0,1fr) 132px;
  align-items:start;
  gap:12px;
  padding:12px 14px;
  background:
    linear-gradient(135deg,rgba(15,118,110,.12),rgba(8,145,178,.07)),
    #ffffff;
  border-bottom:1px solid rgba(15,118,110,.12);
}

.ficha-kicker{
  display:inline-flex;
  align-items:center;
  gap:6px;
  padding:4px 8px;
  border-radius:999px;
  background:rgba(20,184,166,.13);
  color:#0f766e;
  font-size:10px;
  font-weight:850;
  margin-bottom:5px;
}

.ficha-title h1{
  margin:0;
  color:#0f172a;
  font-size:clamp(18px,2vw,23px);
  line-height:1.05;
  font-weight:850;
  letter-spacing:-.045em;
}

.ficha-title p{
  margin:4px 0 0;
  color:#64748b;
  font-size:11.3px;
  font-weight:650;
}

.ficha-pills{
  display:flex;
  flex-wrap:wrap;
  gap:5px;
  margin-top:9px;
}

.ficha-pills span{
  display:inline-flex;
  align-items:center;
  gap:5px;
  min-height:24px;
  padding:4px 7px;
  border-radius:999px;
  background:#fff;
  border:1px solid rgba(15,118,110,.12);
  color:#334155;
  font-size:10.5px;
  font-weight:820;
  box-shadow:0 7px 14px rgba(15,23,42,.035);
}

.ficha-pills i{color:#0f766e;font-size:10px;}

.ficha-photo-panel{
  justify-self:end;
  width:132px;
  padding:7px;
  border:1px solid rgba(15,118,110,.15);
  border-radius:18px;
  background:rgba(255,255,255,.82);
  box-shadow:0 10px 22px rgba(15,23,42,.06);
}

.ficha-photo-preview{
  width:78px;
  height:78px;
  margin:0 auto 6px;
  border-radius:18px;
  overflow:hidden;
  border:3px solid #fff;
  background:linear-gradient(135deg,#0f766e,#14b8a6);
  box-shadow:0 12px 24px rgba(15,118,110,.18);
  display:flex;
  align-items:center;
  justify-content:center;
  color:#fff;
  font-size:24px;
  font-weight:850;
  letter-spacing:-.05em;
}

.ficha-photo-preview img{width:100%;height:100%;object-fit:cover;display:block;}
.ficha-photo-form{display:grid;gap:5px;}

.ficha-photo-upload,
.ficha-photo-save{
  min-height:29px;
  border-radius:11px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:5px;
  font-size:10.5px;
  font-weight:900;
  cursor:pointer;
  border:0!important;
  text-decoration:none!important;
  white-space:nowrap;
}

.ficha-photo-upload{
  color:#fff;
  background:linear-gradient(135deg,#0891b2,#14b8a6);
  box-shadow:0 8px 16px rgba(8,145,178,.19);
}

.ficha-photo-save{
  color:#fff!important;
  background:linear-gradient(135deg,#0f766e,#14b8a6)!important;
  box-shadow:0 8px 16px rgba(15,118,110,.19)!important;
}

.ficha-photo-save:disabled{opacity:.45;cursor:not-allowed;filter:grayscale(.3);}
.ficha-photo-form small{display:block;text-align:center;color:#64748b;font-size:9px;font-weight:750;line-height:1.15;}

/* Barra superior de estado */
.ficha-statusbar{
  display:grid;
  grid-template-columns:minmax(0,1fr) auto;
  align-items:center;
  gap:10px;
  padding:8px 14px;
  border-bottom:1px solid rgba(15,118,110,.10);
  background:rgba(255,255,255,.62);
}

.ficha-progress-inline{display:flex;align-items:center;gap:9px;min-width:0;}
.ficha-progress-title{display:inline-flex;align-items:center;gap:6px;color:#0f172a;font-size:11.5px;font-weight:850;white-space:nowrap;}
.ficha-progress-value{color:#0f766e;font-size:11px;font-weight:900;white-space:nowrap;}
.ficha-progress-track{height:7px;flex:1;min-width:80px;border-radius:999px;background:rgba(148,163,184,.18);overflow:hidden;}
.ficha-progress-fill{height:100%;width:0%;border-radius:999px;background:linear-gradient(135deg,#0f766e,#14b8a6);transition:width .2s ease;}

.ficha-actions{display:flex;align-items:center;justify-content:flex-end;gap:7px;flex-wrap:wrap;}
.badge-status{display:inline-flex;align-items:center;gap:5px;min-height:27px;padding:0 9px;border-radius:999px;font-size:10.5px;font-weight:900;}
.badge-status.ok{background:rgba(204,251,241,.78);color:#0f766e;border:1px solid rgba(20,184,166,.25);}
.badge-status.pending{background:rgba(255,251,235,.92);color:#92400e;border:1px solid rgba(251,191,36,.30);}
.ficha-btn{min-height:31px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:6px 10px;text-decoration:none;font-size:10.8px;font-weight:900;color:#fff!important;background:linear-gradient(135deg,#0891b2,#14b8a6);border:0;box-shadow:0 9px 18px rgba(8,145,178,.18);}
.ficha-btn:hover{color:#fff!important;background:linear-gradient(135deg,#0e7490,#0f766e);transform:translateY(-1px);}

.ficha-alert{margin:10px 14px 0;border:0!important;border-radius:15px!important;font-size:11.5px;font-weight:800;box-shadow:0 9px 20px rgba(15,23,42,.055);}
.alert-success{color:#0f766e!important;background:rgba(236,253,245,.95)!important;}
.alert-danger{color:#be123c!important;background:rgba(255,241,242,.95)!important;}

/* Formulario por tarjetas */
.ficha-card{
  margin:12px 14px 14px;
  background:transparent;
  border:0;
}

.ficha-grid{
  display:grid;
  grid-template-columns:1fr;
  gap:10px;
}

.ficha-two{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:10px;
}

.ficha-section{
  overflow:hidden;
  border:1px solid rgba(15,118,110,.13);
  border-radius:17px;
  background:rgba(255,255,255,.86);
  box-shadow:0 9px 22px rgba(15,23,42,.045);
}

.ficha-section-head{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:8px;
  padding:9px 11px;
  background:linear-gradient(135deg,#ffffff,#f5fffd);
  border-bottom:1px solid rgba(15,118,110,.10);
}

.section-title{
  display:flex;
  align-items:center;
  gap:7px;
  margin:0;
  color:#0f172a;
  font-size:12.2px;
  font-weight:900;
  letter-spacing:-.02em;
}

.section-title i{
  width:25px;
  height:25px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  border-radius:9px;
  background:rgba(20,184,166,.12);
  color:#0f766e;
  font-size:11px;
  flex:0 0 auto;
}

.ficha-section-tag{
  display:inline-flex;
  align-items:center;
  gap:5px;
  padding:4px 7px;
  border-radius:999px;
  background:#ecfeff;
  color:#0e7490;
  font-size:9.5px;
  font-weight:900;
  white-space:nowrap;
}

.ficha-section-body{padding:10px 11px;}
.row.g-2{--bs-gutter-x:.5rem;--bs-gutter-y:.5rem;}

.form-label{
  margin-bottom:3px;
  color:#334155;
  font-size:9.5px;
  font-weight:900;
  text-transform:uppercase;
  letter-spacing:.04em;
}

.form-control,.form-select,.readonly-box{
  min-height:32px;
  height:32px;
  border-radius:10px;
  border:1px solid #dbe7e5;
  background:#fbfefd;
  color:#0f172a;
  font-size:11.5px;
  font-weight:650;
  padding:5px 8px;
  box-shadow:none;
}

.form-control:focus,.form-select:focus{
  border-color:rgba(20,184,166,.72);
  box-shadow:0 0 0 .18rem rgba(20,184,166,.12);
  background:#fff;
}

textarea.form-control{min-height:74px;height:auto;resize:vertical;line-height:1.4;}
.readonly-box{display:flex;align-items:center;background:linear-gradient(180deg,#f8fffd,#f4fbfa);font-weight:750;word-break:break-word;}

.hint-edit{display:flex;align-items:flex-start;gap:6px;margin-top:7px;color:#64748b;font-size:10.5px;font-weight:700;}
.hint-edit i{color:#0f766e;margin-top:1px;}

.commitment{
  padding:10px;
  border:1px solid rgba(20,184,166,.20);
  border-radius:14px;
  background:linear-gradient(135deg,rgba(20,184,166,.10),rgba(8,145,178,.06)),#fff;
  color:#334155;
  font-size:11.2px;
  line-height:1.38;
}
.commitment strong{color:#0f172a;font-weight:850;}
.commitment ol{padding-left:17px;margin-bottom:0;}
.commitment li{margin-bottom:2px;}

.ficha-config-note{display:inline-flex;align-items:center;gap:6px;margin-top:8px;padding:5px 8px;border-radius:999px;color:#0f766e;background:rgba(204,251,241,.72);border:1px solid rgba(20,184,166,.24);font-size:10.5px;font-weight:850;}

.form-check{border-color:rgba(15,118,110,.16)!important;background:#f4fffd!important;padding:9px!important;border-radius:13px!important;min-height:32px;display:flex;align-items:center;gap:8px;}
.form-check-input{border-color:rgba(15,118,110,.30);cursor:pointer;margin:0!important;width:17px;height:17px;}
.form-check-input:checked{background-color:#0f766e;border-color:#0f766e;}
.form-check-label{color:#0f172a;font-size:11.5px;font-weight:850;}

.sticky-actions{
  position:sticky;
  bottom:0;
  z-index:8;
  padding:9px 10px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:8px;
  background:rgba(255,255,255,.94);
  backdrop-filter:blur(12px);
  border:1px solid rgba(15,118,110,.13);
  border-radius:16px;
  box-shadow:0 12px 26px rgba(15,23,42,.075);
  margin-top:10px;
}
.sticky-actions .small{color:#64748b!important;font-size:10.5px;font-weight:750;}
.btn-primary{min-height:34px;border-radius:12px;border:0!important;background:linear-gradient(135deg,#0f766e,#14b8a6)!important;color:#fff!important;font-size:11.5px;font-weight:900;padding:6px 12px!important;box-shadow:0 10px 20px rgba(15,118,110,.22)!important;}
.btn-primary:hover{background:linear-gradient(135deg,#115e59,#0f766e)!important;transform:translateY(-1px);}

.topbar,header.topbar,.theme-switcher,.theme-toggle,.theme-card,.theme-floating,.floating-theme,.theme-selector,.tema-switcher,.theme-control,#themeSwitcher,#theme-switcher,#theme-toggle,[data-theme-switcher],[class*="theme-switch"],[class*="themeSwitch"],[class*="tema"]{display:none!important;visibility:hidden!important;pointer-events:none!important;}

i.fa,i.fas,i.fa-solid,.fa,.fas,.fa-solid{font-family:"Font Awesome 6 Free"!important;font-weight:900!important;}
i.far,i.fa-regular,.far,.fa-regular{font-family:"Font Awesome 6 Free"!important;font-weight:400!important;}
i.fab,i.fa-brands,.fab,.fa-brands{font-family:"Font Awesome 6 Brands"!important;font-weight:400!important;}

<?php if (!empty($isEmbed)): ?>
html,body{background:transparent!important;min-height:auto!important;}
.ficha-page{max-width:100%!important;width:100%!important;padding:0!important;}
.ficha-shell{border-radius:0!important;border:0!important;box-shadow:none!important;}
<?php endif; ?>

@media(max-width:991px){
  .ficha-two{grid-template-columns:1fr;}
  .ficha-hero{grid-template-columns:1fr 124px;}
  .ficha-photo-panel{width:124px;}
}

@media(max-width:768px){
  .ficha-page{width:calc(100% - 10px);padding:5px 0 10px;}
  .ficha-shell{border-radius:18px;}
  .ficha-hero{grid-template-columns:1fr;gap:9px;padding:10px;}
  .ficha-title h1{font-size:18px;}
  .ficha-title p{font-size:11px;}
  .ficha-pills{display:grid;grid-template-columns:1fr 1fr;gap:5px;}
  .ficha-pills span{width:100%;font-size:9.8px;min-height:23px;}
  .ficha-photo-panel{justify-self:stretch;width:100%;display:grid;grid-template-columns:74px minmax(0,1fr);gap:9px;align-items:center;border-radius:15px;}
  .ficha-photo-preview{width:74px;height:74px;margin:0;border-radius:16px;font-size:22px;}
  .ficha-photo-form small{text-align:left;}
  .ficha-statusbar{grid-template-columns:1fr;align-items:stretch;padding:8px 10px;}
  .ficha-progress-inline{width:100%;}
  .ficha-actions{justify-content:stretch;display:grid;grid-template-columns:1fr 1fr;}
  .ficha-btn,.badge-status{width:100%;justify-content:center;}
  .ficha-card{margin:10px;}
  .ficha-section-body{padding:9px;}
  .sticky-actions{position:static;flex-direction:column;align-items:stretch;}
  .sticky-actions > div,.sticky-actions .btn,.sticky-actions .ficha-btn{width:100%;}
}

@media(max-width:440px){
  .ficha-pills{grid-template-columns:1fr;}
  .ficha-photo-panel{grid-template-columns:1fr;justify-items:center;text-align:center;}
  .ficha-photo-form{width:100%;}
  .ficha-photo-form small{text-align:center;}
  .ficha-actions{grid-template-columns:1fr;}
}
</style>
</head>
<body class="ficha-body modulo-embedded-clean no-header-page">
<div class="ficha-page">
<section class="ficha-shell">

  <div class="ficha-hero no-print">
    <div class="ficha-title">
      <span class="ficha-kicker"><i class="fa-solid fa-id-card"></i> Ficha de matrícula</span>
      <h1><?= h($alumno['nombre']) ?></h1>
      <p>Actualiza datos, foto, salud, contactos y compromiso.</p>
      <div class="ficha-pills">
        <span><i class="fa-solid fa-id-badge"></i> RUT <?= h($alumno['rut']) ?></span>
        <span><i class="fa-solid fa-location-dot"></i> <?= h($alumno['escuela_nombre']) ?></span>
        <span><i class="fa-solid fa-layer-group"></i> <?= h($alumno['categoria']) ?></span>
        <span><i class="fa-solid fa-shirt"></i> Talla <?= h($alumno['talla_uniforme'] ?: '—') ?></span>
      </div>
    </div>

    <div class="ficha-photo-panel">
      <div class="ficha-photo-preview" id="fotoPreviewBox">
        <?php if ($fotoAlumnoSrc !== ''): ?>
          <img id="fotoPreviewImg" src="<?= h($fotoAlumnoSrc) ?>" alt="Foto de <?= h($alumno['nombre']) ?>">
        <?php else: ?>
          <div class="ficha-photo-initials" id="fotoPreviewInitials"><?= h($inicialAlumno) ?></div>
          <img id="fotoPreviewImg" src="" alt="" style="display:none;">
        <?php endif; ?>
      </div>

      <form method="post" action="<?= h(ficha_link_embed($_SERVER['REQUEST_URI'] ?? 'ficha_matricula.php', $isEmbed)) ?>" enctype="multipart/form-data" class="ficha-photo-form">
        <input type="hidden" name="csrf_token" value="<?= h(session_id()) ?>">
        <input type="hidden" name="accion_foto_alumno" value="1">
        <label for="foto_alumno" class="ficha-photo-upload"><i class="fa-solid fa-camera"></i><span>Subir foto</span></label>
        <input type="file" id="foto_alumno" name="foto_alumno" accept="image/jpeg,image/png,image/webp" class="visually-hidden">
        <button type="submit" class="ficha-photo-save" id="btnGuardarFoto" disabled><i class="fa-solid fa-cloud-arrow-up"></i> Guardar</button>
        <small>JPG, PNG o WEBP · ajuste automático</small>
      </form>
    </div>
  </div>

  <div class="ficha-statusbar no-print">
    <div class="ficha-progress-inline">
      <div class="ficha-progress-title"><i class="fa-solid fa-list-check"></i> Avance</div>
      <div class="ficha-progress-track"><div class="ficha-progress-fill" id="fichaProgressFill"></div></div>
      <div class="ficha-progress-value" id="fichaProgressText">0%</div>
    </div>
    <div class="ficha-actions">
      <?php if ($completa): ?>
        <span class="badge-status ok"><i class="fa-solid fa-circle-check"></i> Completada</span>
      <?php else: ?>
        <span class="badge-status pending"><i class="fa-solid fa-circle-exclamation"></i> Pendiente</span>
      <?php endif; ?>
      <?php if (empty($isEmbed)): ?>
        <a href="panel_apoderado.php" class="ficha-btn"><i class="fa-solid fa-arrow-left"></i> Volver</a>
      <?php else: ?>
        <button type="button" class="ficha-btn" onclick="window.parent?.postMessage({type:'sport360-close-ficha-modal'}, '*')"><i class="fa-solid fa-xmark"></i> Cerrar</button>
      <?php endif; ?>
    </div>
  </div>

  <?php if (isset($_GET['ok']) && $_GET['ok'] == '1'): ?>
    <div class="alert alert-success alert-dismissible fade show ficha-alert" role="alert">
      ✅ Ficha guardada correctamente.
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['ok_foto']) && $_GET['ok_foto'] == '1'): ?>
    <div class="alert alert-success alert-dismissible fade show ficha-alert" role="alert">
      <i class="fa-solid fa-camera me-1"></i> Foto del alumno actualizada correctamente.
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show ficha-alert" role="alert">
      ⚠️ <?= h($_GET['error']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
  <?php endif; ?>

  <form method="post" action="<?= h(ficha_link_embed('guardar_ficha_matricula.php', $isEmbed)) ?>" class="ficha-card ficha-form" id="fichaForm">
    <input type="hidden" name="csrf_token" value="<?= h(session_id()) ?>">

    <div class="ficha-grid">

      <section class="ficha-section">
        <div class="ficha-section-head">
          <h2 class="section-title"><i class="fa-solid fa-user-graduate"></i> Datos del alumno</h2>
          <span class="ficha-section-tag"><i class="fa-solid fa-lock"></i> Escuela protegida</span>
        </div>
        <div class="ficha-section-body">
          <div class="row g-2">
            <div class="col-lg-4 col-md-6"><label class="form-label">Nombre y apellidos</label><input type="text" name="nombre" class="form-control" value="<?= h($alumno['nombre']) ?>" maxlength="160" required></div>
            <div class="col-lg-2 col-md-6"><label class="form-label">RUT alumno</label><input type="text" name="rut" class="form-control" value="<?= h($alumno['rut']) ?>" maxlength="20" required></div>
            <div class="col-lg-3 col-md-6"><label class="form-label">Fecha nacimiento</label><input type="date" name="fecha_nacimiento" class="form-control" value="<?= h($alumno['fecha_nacimiento']) ?>"></div>
            <div class="col-lg-3 col-md-6"><label class="form-label">Fecha matrícula</label><div class="readonly-box"><?= fecha_cl($alumno['fecha_ingreso']) ?></div></div>
            <div class="col-lg-4 col-md-6"><label class="form-label">Sede / Escuela</label><div class="readonly-box"><?= h($alumno['escuela_nombre']) ?></div></div>
            <div class="col-lg-2 col-md-3"><label class="form-label">Categoría</label><div class="readonly-box"><?= h($alumno['categoria']) ?></div></div>
            <div class="col-lg-2 col-md-3"><label class="form-label">Talla</label><input type="text" name="talla_uniforme" class="form-control" value="<?= h($alumno['talla_uniforme']) ?>" maxlength="20"></div>
            <div class="col-lg-2 col-md-6"><label class="form-label">Tipo</label><select name="tipo_jugador" class="form-select"><option value="Jugador" <?= ($ficha['tipo_jugador'] === 'Jugador') ? 'selected' : '' ?>>Jugador</option><option value="Arquero" <?= ($ficha['tipo_jugador'] === 'Arquero') ? 'selected' : '' ?>>Arquero</option></select></div>
            <div class="col-lg-2 col-md-6"><label class="form-label">Ciudad</label><input type="text" name="ciudad" class="form-control" value="<?= h($ficha['ciudad']) ?>" maxlength="100" required></div>
            <div class="col-lg-6 col-md-7"><label class="form-label">Dirección</label><input type="text" name="direccion" class="form-control" value="<?= h($ficha['direccion']) ?>" maxlength="180" required></div>
            <div class="col-lg-6 col-md-5"><label class="form-label">Previsión / Salud</label><input type="text" name="prevision_salud" class="form-control" value="<?= h($ficha['prevision_salud']) ?>" maxlength="120" placeholder="Ej: Fonasa, Isapre, seguro, etc."></div>
          </div>
          <div class="hint-edit"><i class="fa-solid fa-circle-info"></i> El apoderado puede actualizar identificación, contacto y salud. Escuela, categoría y fecha de matrícula quedan protegidas.</div>
        </div>
      </section>

      <div class="ficha-two">
        <section class="ficha-section">
          <div class="ficha-section-head">
            <h2 class="section-title"><i class="fa-solid fa-heart-pulse"></i> Salud</h2>
            <span class="ficha-section-tag">Médico</span>
          </div>
          <div class="ficha-section-body">
            <div class="row g-2">
              <div class="col-12"><label class="form-label">Lesiones y año</label><textarea name="lesiones" class="form-control" rows="3" placeholder="Ej: Esguince 2024, fractura 2023, sin lesiones relevantes."><?= h($ficha['lesiones']) ?></textarea></div>
              <div class="col-12"><label class="form-label">Alergia o enfermedad</label><textarea name="alergia_enfermedad" class="form-control" rows="3" placeholder="Ej: Asma, alergia a medicamentos, ninguna."><?= h($ficha['alergia_enfermedad']) ?></textarea></div>
            </div>
          </div>
        </section>

        <section class="ficha-section">
          <div class="ficha-section-head">
            <h2 class="section-title"><i class="fa-solid fa-user-shield"></i> Apoderado</h2>
            <span class="ficha-section-tag">Contacto</span>
          </div>
          <div class="ficha-section-body">
            <div class="row g-2">
              <div class="col-12"><label class="form-label">Nombre y apellidos</label><input type="text" name="nombre_apoderado" class="form-control" value="<?= h($alumno['nombre_apoderado']) ?>" maxlength="160" required></div>
              <div class="col-md-5"><label class="form-label">RUT apoderado</label><input type="text" name="rut_apoderado" class="form-control" value="<?= h($alumno['rut_apoderado']) ?>" maxlength="20"></div>
              <div class="col-md-7"><label class="form-label">Celular</label><input type="text" name="celular_apoderado" class="form-control" value="<?= h($alumno['celular_apoderado']) ?>" maxlength="30" required></div>
              <div class="col-12"><label class="form-label">Email</label><input type="email" name="correo" class="form-control" value="<?= h($alumno['correo']) ?>" maxlength="160"></div>
            </div>
          </div>
        </section>
      </div>

      <section class="ficha-section">
        <div class="ficha-section-head">
          <h2 class="section-title"><i class="fa-solid fa-triangle-exclamation"></i> Contactos de emergencia</h2>
          <span class="ficha-section-tag"><i class="fa-solid fa-phone"></i> Prioridad</span>
        </div>
        <div class="ficha-section-body">
          <div class="row g-2">
            <div class="col-lg-5 col-md-6"><label class="form-label">Contacto principal</label><input type="text" name="emergencia_nombre" class="form-control" value="<?= h($ficha['emergencia_nombre']) ?>" maxlength="140" required></div>
            <div class="col-lg-3 col-md-6"><label class="form-label">Celular</label><input type="text" name="emergencia_celular" class="form-control" value="<?= h($ficha['emergencia_celular']) ?>" maxlength="30" required></div>
            <div class="col-lg-4 col-md-12"><label class="form-label">Parentesco</label><input type="text" name="emergencia_parentesco" class="form-control" value="<?= h($ficha['emergencia_parentesco']) ?>" maxlength="80" required></div>
            <div class="col-lg-5 col-md-6"><label class="form-label">Segundo contacto</label><input type="text" name="contacto2_nombre" class="form-control" value="<?= h($ficha['contacto2_nombre']) ?>" maxlength="140"></div>
            <div class="col-lg-3 col-md-6"><label class="form-label">Celular</label><input type="text" name="contacto2_celular" class="form-control" value="<?= h($ficha['contacto2_celular']) ?>" maxlength="30"></div>
            <div class="col-lg-4 col-md-12"><label class="form-label">Parentesco</label><input type="text" name="contacto2_parentesco" class="form-control" value="<?= h($ficha['contacto2_parentesco']) ?>" maxlength="80"></div>
          </div>
        </div>
      </section>

      <section class="ficha-section">
        <div class="ficha-section-head">
          <h2 class="section-title"><i class="fa-solid fa-file-signature"></i> Compromiso del apoderado</h2>
          <span class="ficha-section-tag"><i class="fa-solid fa-calendar-day"></i> Día <?= h($dia_vencimiento_cuota_txt) ?></span>
        </div>
        <div class="ficha-section-body">
          <div class="row g-2 align-items-stretch">
            <div class="col-lg-7">
              <div class="commitment h-100">
                <p class="mb-2"><strong>Quien suscribe, se compromete formalmente a cumplir con el pago puntual de las cuotas mensuales y cualquier otra obligación financiera relacionada con los servicios ofrecidos por la escuela.</strong></p>
                <ol class="small mb-0">
                  <li>Las cuotas mensuales deberán ser abonadas antes del día <?= h($dia_vencimiento_cuota_txt) ?> de cada mes.</li>
                  <li>El incumplimiento podría generar recargos por mora y eventual suspensión temporal de entrenamientos hasta regularizar la situación.</li>
                  <li>El apoderado asumirá costos adicionales informados por actividades, torneos, transporte u otros conceptos.</li>
                </ol>
                <div class="ficha-config-note"><i class="fa-solid fa-gear"></i> Vencimiento configurado: <strong><?= h($dia_vencimiento_cuota_txt) ?></strong></div>
              </div>
            </div>
            <div class="col-lg-5">
              <div class="row g-2">
                <div class="col-12"><label class="form-label">Nombre apoderado / firma</label><input type="text" name="firma_apoderado" class="form-control" value="<?= h($alumno['nombre_apoderado']) ?>" maxlength="160" required></div>
                <div class="col-md-5"><label class="form-label">Fecha firma</label><input type="date" name="fecha_firma" class="form-control" value="<?= h($ficha['fecha_firma']) ?>" required></div>
                <div class="col-md-7"><label class="form-label">Estado</label><div class="form-check border bg-light"><input class="form-check-input" type="checkbox" name="compromiso_aceptado" value="1" id="compromiso" <?= !empty($ficha['compromiso_aceptado']) ? 'checked' : '' ?> required><label class="form-check-label" for="compromiso">Aceptado</label></div></div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <div class="sticky-actions">
        <?php if (empty($isEmbed)): ?><a href="panel_apoderado.php" class="ficha-btn"><i class="fa-solid fa-arrow-left"></i> Volver al portal</a><?php else: ?><button type="button" class="ficha-btn" onclick="window.parent?.postMessage({type:'sport360-close-ficha-modal'}, '*')"><i class="fa-solid fa-xmark"></i> Cerrar</button><?php endif; ?>
        <div class="d-flex gap-2 align-items-center flex-wrap">
          <?php if (!empty($ficha['updated_at'])): ?><span class="small text-muted">Última actualización: <?= h(date('d-m-Y H:i', strtotime($ficha['updated_at']))) ?></span><?php else: ?><span class="small text-muted">Ficha lista para completar y guardar.</span><?php endif; ?>
          <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i> Guardar ficha</button>
        </div>
      </div>

    </div>
  </form>
</section>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  const form = document.getElementById('fichaForm');
  const fill = document.getElementById('fichaProgressFill');
  const txt = document.getElementById('fichaProgressText');

  function isFilled(el) {
    if (!el || el.disabled) return true;
    if (el.type === 'checkbox') return el.checked;
    return String(el.value || '').trim() !== '';
  }

  function updateProgress() {
    if (!form || !fill || !txt) return;
    const required = Array.from(form.querySelectorAll('[required]'));
    if (!required.length) return;
    let ok = 0;
    required.forEach(el => { if (isFilled(el)) ok++; });
    const pct = Math.round((ok / required.length) * 100);
    fill.style.width = pct + '%';
    txt.textContent = pct + '%';
  }

  document.addEventListener('input', updateProgress);
  document.addEventListener('change', updateProgress);
  updateProgress();

  const fotoInput = document.getElementById('foto_alumno');
  const fotoImg = document.getElementById('fotoPreviewImg');
  const fotoInitials = document.getElementById('fotoPreviewInitials');
  const btnGuardarFoto = document.getElementById('btnGuardarFoto');

  if (fotoInput && fotoImg && btnGuardarFoto) {
    fotoInput.addEventListener('change', function(){
      const file = this.files && this.files[0] ? this.files[0] : null;
      btnGuardarFoto.disabled = !file;
      if (!file) return;

      const allowed = ['image/jpeg', 'image/png', 'image/webp'];
      if (!allowed.includes(file.type)) {
        alert('Formato no permitido. Usa JPG, PNG o WEBP.');
        this.value = '';
        btnGuardarFoto.disabled = true;
        return;
      }

      if (file.size > 6 * 1024 * 1024) {
        alert('La foto no puede superar 6 MB.');
        this.value = '';
        btnGuardarFoto.disabled = true;
        return;
      }

      const reader = new FileReader();
      reader.onload = function(e){
        fotoImg.src = e.target.result;
        fotoImg.style.display = 'block';
        if (fotoInitials) fotoInitials.style.display = 'none';
      };
      reader.readAsDataURL(file);
    });
  }

  <?php if (isset($_GET['ok']) && $_GET['ok'] == '1'): ?>
  try { window.parent?.postMessage({type:'sport360-ficha-saved'}, '*'); } catch(e) {}
  <?php endif; ?>
})();
</script>
</body>
</html>
