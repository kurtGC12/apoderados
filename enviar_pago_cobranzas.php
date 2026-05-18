<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../includes/db.php';

/* ================= CONFIGURACIÓN ESCUELA ================= */
$config_escuela = ['correo_comprobantes' => ''];

$qCfg = $conn->query("
  SELECT correo_comprobantes
  FROM configuracion_escuela
  WHERE id = 1
  LIMIT 1
");
if ($qCfg && $qCfg->num_rows > 0) {
  $config_escuela = $qCfg->fetch_assoc();
}

/* ================= HELPERS ================= */
function mes_espanol($fecha) {
  if (!$fecha) return '';
  $meses = [1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
  $ts = strtotime($fecha);
  if (!$ts) return '';
  return $meses[(int)date('n',$ts)].' '.date('Y',$ts);
}

function volver_panel($ok, $msg) {
  header("Location: panel_apoderado.php?ok=".($ok?'1':'0')."&msg=".urlencode($msg));
  exit;
}

function asunto_seguro_ascii($s) {
  $s = str_replace(["–","—","−"], "-", (string)$s);
  if (function_exists('iconv')) {
    $tmp = @iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s);
    if ($tmp !== false) $s = $tmp;
  }
  return trim(preg_replace('/[^\x20-\x7E]/','',$s)) ?: 'Escuela';
}

/* ================= VALIDACIONES ================= */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  volver_panel(false,'Método no permitido');
}

$alumno_id = (int)($_POST['alumno_id'] ?? 0);
$cuotas_ids = trim($_POST['cuotas_ids'] ?? '');
$actividades_ids = trim($_POST['actividades_ids'] ?? '');

$cuotas = $cuotas_ids ? array_map('intval', explode(',',$cuotas_ids)) : [];
$actividades = $actividades_ids ? array_map('intval', explode(',',$actividades_ids)) : [];

if (!$alumno_id || (!$cuotas && !$actividades)) {
  volver_panel(false,'No se seleccionaron cuotas ni actividades');
}

/* ================= SUBIDA ARCHIVO ================= */
$uploadDir = __DIR__ . '/../uploads/comprobantes/';
if (!is_dir($uploadDir)) mkdir($uploadDir,0755,true);

$archivo = '';
if (!empty($_FILES['comprobante']['name'])) {
  $ext = strtolower(pathinfo($_FILES['comprobante']['name'],PATHINFO_EXTENSION));
  if (!in_array($ext,['pdf','jpg','jpeg','png','webp'],true)) {
    volver_panel(false,'Formato de archivo no permitido');
  }
  $archivo = uniqid('voucher_').'.'.$ext;
  if (!move_uploaded_file($_FILES['comprobante']['tmp_name'],$uploadDir.$archivo)) {
    volver_panel(false,'No se pudo subir el comprobante');
  }
}

/* ================= TRANSACCIÓN ================= */
$conn->autocommit(false);
$error = false;

/* ===== CUOTAS ===== */
if ($cuotas) {
  $st = $conn->prepare("
    INSERT INTO pagos_transferencia
    (alumno_id, cuota_id, archivo, fecha_envio, estado, metodo_pago)
    VALUES (?, ?, ?, NOW(), 'pendiente', 'transferencia')
  ");

  foreach ($cuotas as $cuota_id) {
    if (!$st->bind_param("iis",$alumno_id,$cuota_id,$archivo) || !$st->execute()) {
      $error = true; break;
    }
    $conn->query("UPDATE cuotas SET estado='pendiente_aprobacion' WHERE id=".(int)$cuota_id);
  }
  $st->close();
}

/* ===== ACTIVIDADES (CORREGIDO) ===== */
if (!$error && $actividades) {

  $stIns = $conn->prepare("
    INSERT INTO actividad_pagos_transferencia
(alumno_id, actividad_id, archivo, fecha_envio)
VALUES (?, ?, ?, NOW())

  ");

  $stAl = $conn->prepare("
    SELECT alumno_id
    FROM actividad_participantes
    WHERE actividad_id = ?
      AND alumno_id = ?
      AND activo = 1
    LIMIT 1
  ");

  foreach ($actividades as $actividad_id) {

    $alumno_act_id = 0;
    $stAl->bind_param("ii",$actividad_id,$alumno_id);
    $stAl->execute();
    $stAl->bind_result($alumno_act_id);
    $stAl->fetch();
    $stAl->free_result();

    if (!$alumno_act_id) {
      $error = true; break;
    }

    if (!$stIns->bind_param("iis",$alumno_act_id,$actividad_id,$archivo) || !$stIns->execute()) {
      $error = true; break;
    }
  }

  $stAl->close();
  $stIns->close();
}

/* ===== COMMIT / ROLLBACK ===== */
if ($error) {
  $conn->rollback();
  volver_panel(false,'Error al registrar el pago');
}
$conn->commit();

/* ================= DATOS CORREO ================= */
$datosCorreo = ['alumno'=>'','rut'=>'','escuela'=>'','categoria'=>''];

if ($st = $conn->prepare("
  SELECT a.nombre,a.rut,COALESCE(e.nombre,''),COALESCE(a.categoria,'')
  FROM alumnos a
  LEFT JOIN escuelas e ON e.id=a.escuela_id
  WHERE a.id=?
  LIMIT 1
")) {
  $st->bind_param("i",$alumno_id);
  $st->execute();
  $st->bind_result($datosCorreo['alumno'],$datosCorreo['rut'],$datosCorreo['escuela'],$datosCorreo['categoria']);
  $st->fetch();
  $st->close();
}

/* ===== DETALLE CUOTAS ===== */
$detalleCuotasHtml = '';
if ($cuotas) {
  $ph = implode(',',array_fill(0,count($cuotas),'?'));
  $types = str_repeat('i',count($cuotas)+1);
  $params = array_merge([$alumno_id],$cuotas);

  $sql = "
    SELECT numero_cuota, fecha_vencimiento, valor
    FROM cuotas
    WHERE alumno_id = ?
      AND id IN ($ph)
    ORDER BY fecha_vencimiento, numero_cuota
  ";

  if ($st = $conn->prepare($sql)) {
    $bind = [$types];
    foreach ($params as $k=>$v) $bind[]=&$params[$k];
    call_user_func_array([$st,'bind_param'],$bind);

    if ($st->execute()) {
      $st->bind_result($n,$fv,$v);
      $detalleCuotasHtml.="<ul>";
      while ($st->fetch()) {
        $detalleCuotasHtml.="<li>Cuota {$n} - ".mes_espanol($fv)." ($".number_format($v,0,',','.').")</li>";
      }
      $detalleCuotasHtml.="</ul>";
    }
    $st->close();
  }
}

/* ================= ENVÍO CORREO (NO CRÍTICO) ================= */
$mailFile = __DIR__ . '/../includes/mail/enviar_mail.php';
if (file_exists($mailFile) && !empty($config_escuela['correo_comprobantes'])) {
  include_once $mailFile;
  if (function_exists('enviarCorreo')) {
    $asunto = "Nuevo comprobante - ".asunto_seguro_ascii($datosCorreo['escuela']);
    $mensaje = "
      <h2>Nuevo comprobante de transferencia</h2>
      <p><strong>Alumno:</strong> {$datosCorreo['alumno']}</p>
      <p><strong>RUT:</strong> {$datosCorreo['rut']}</p>
      <p><strong>Escuela:</strong> {$datosCorreo['escuela']}</p>
      <p><strong>Categoría:</strong> {$datosCorreo['categoria']}</p>
      <hr>
      ".($detalleCuotasHtml ?: "<p>—</p>")."
      <hr>
      <p><strong>Fecha envío:</strong> ".date('d-m-Y H:i')."</p>
    ";
    @enviarCorreo(
      $config_escuela['correo_comprobantes'],
      $asunto,
      $mensaje,
      $archivo ? $uploadDir.$archivo : null
    );
  }
}

volver_panel(true,'Pago registrado correctamente');
