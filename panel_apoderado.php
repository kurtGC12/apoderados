<?php
session_start();

if (
    isset($_GET['wp']) &&
    $_GET['wp'] === 'ok' &&
    empty($_SESSION['rol']) &&
    isset($_GET['alumno_id'])
) {
    $_SESSION['rol'] = 'apoderado';
    $_SESSION['alumno_id'] = $_GET['alumno_id'];
}
header('Content-Type: text/html; charset=UTF-8');
if (function_exists('mb_internal_encoding')) mb_internal_encoding('UTF-8');

require_once __DIR__ . '/../includes/auth_apoderado.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/webpay_config_helper.php';

$config_escuela = [
  'banco' => '', 'tipo_cuenta' => '', 'numero_cuenta' => '',
  'rut_titular' => '', 'correo_comprobantes' => '',
];
$qCfg = $conn->query("SELECT banco, tipo_cuenta, numero_cuenta, rut_titular, correo_comprobantes FROM configuracion WHERE id = 1 LIMIT 1");
if ($qCfg && $qCfg->num_rows > 0) { $config_escuela = $qCfg->fetch_assoc(); }

$isEmbed = isset($_GET['embed']) && $_GET['embed'] == '1';
$webpay_volvio = isset($_GET['webpay']) && $_GET['webpay'] === 'ok';
if ($webpay_volvio) {
  $_POST['cuotas_ids']      = $_SESSION['webpay_cuotas_ids']      ?? '';
  $_POST['actividades_ids'] = $_SESSION['webpay_actividades_ids'] ?? '';
}

if (!isset($_SESSION['alumno_rut'])) {
  if (isset($_GET['webpay']) || isset($_GET['wp'])) { } else {
    header("Location: login.php"); exit;
  }
}

@mysqli_set_charset($conn, 'utf8mb4');
if (!isset($conn) || !($conn instanceof mysqli)) { die("Error DB"); }

$webpay_activo = webpay_cfg_is_active($conn);

function clp($n){ return '$'.number_format((int)$n,0,',','.'); }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function ap_link_embed(string $url, bool $isEmbed): string {
  if (!$isEmbed) return $url;
  if (strpos($url, 'embed=1') !== false) return $url;
  return $url . (strpos($url, '?') === false ? '?' : '&') . 'embed=1';
}


$contrato_file = '';
$files = glob(__DIR__ . '/docs/*.pdf');
if (!empty($files)) { $contrato_file = 'docs/' . basename($files[0]); }

$alumno_rut = (string)$_SESSION['alumno_rut'];
$alumno_id = 0; $alumno_nombre = ''; $alumno_categoria = '';
$contrato_aceptado = 0; $contrato_fecha = null;

if ($st = $conn->prepare("SELECT id, nombre, categoria, contrato_aceptado, contrato_fecha FROM alumnos WHERE rut = ? LIMIT 1")) {
  $st->bind_param("s", $alumno_rut);
  $st->execute();
  $st->bind_result($alumno_id, $alumno_nombre, $alumno_categoria, $contrato_aceptado, $contrato_fecha);
  $st->fetch(); $st->close();
}
if (!$alumno_id) { http_response_code(403); exit("Alumno no válido"); }

// Estado de ficha de matrícula complementaria del apoderado
$ficha_completa = 0;
$ficha_actualizada = null;
if ($st = $conn->prepare("SELECT compromiso_aceptado, updated_at FROM alumno_fichas WHERE alumno_id = ? LIMIT 1")) {
  $st->bind_param("i", $alumno_id);
  $st->execute();
  $st->bind_result($ficha_completa_db, $ficha_actualizada_db);
  if ($st->fetch()) {
    $ficha_completa = (int)$ficha_completa_db;
    $ficha_actualizada = $ficha_actualizada_db;
  }
  $st->close();
}

$contrato_fecha_txt = '';
if (!empty($contrato_fecha)) { $contrato_fecha_txt = date('d-m-Y H:i', strtotime($contrato_fecha)); }

$pendientes = []; $pagadas = [];
$sqlCuotas = "
  SELECT c.id, c.numero_cuota, c.valor, c.fecha_vencimiento, c.estado, c.fecha_pago, c.metodo_pago,
         (SELECT pt.estado FROM pagos_transferencia pt WHERE pt.cuota_id = c.id AND pt.alumno_id = c.alumno_id ORDER BY pt.id DESC LIMIT 1) AS transf_estado
  FROM cuotas c WHERE c.alumno_id = ?
  ORDER BY c.fecha_vencimiento ASC, c.numero_cuota ASC
";
if ($st = $conn->prepare($sqlCuotas)) {
  $st->bind_param("i", $alumno_id);
  $st->execute();
  $st->bind_result($cid,$num,$valor,$vence,$estado,$fecha_pago,$metodo,$transf_estado);
  while ($st->fetch()) {
    $estado_norm = trim((string)$estado);
    $transf_norm = strtolower(trim((string)$transf_estado));
    if (strtolower($estado_norm) === 'pagada') { $estado_visual = 'Pagada'; }
    elseif ($transf_norm === 'pendiente' || $transf_norm === 'pendiente_aprobacion') { $estado_visual = 'Pendiente de Aprobación'; }
    else { $estado_visual = ($estado_norm !== '' ? $estado_norm : 'Pendiente'); }
    $fila = [
      'id' => (int)$cid, 'n' => (int)$num, 'valor' => (int)$valor,
      'vence' => ($vence ? date('d-m-Y', strtotime($vence)) : ''),
      'estado' => $estado_visual, 'metodo' => (string)$metodo,
      'fecha_pago' => $fecha_pago,
      'disabled' => (strtolower($estado_visual) === 'pagada' || strtolower($estado_visual) === 'pendiente de aprobación')
    ];
    if (strtolower($estado_visual) === 'pagada') $pagadas[] = $fila;
    else $pendientes[] = $fila;
  }
  $st->close();
}

$actividades = [];
$sqlAct = "
  SELECT a.id, a.nombre, a.monto, a.fecha_cierre,
         COALESCE(e.nombre,'') AS ambito,
         pa.id AS pago_act_id, pa.metodo_pago AS pago_metodo, pa.fecha_pago AS pago_fecha,
         (SELECT apt2.estado FROM actividad_pagos_transferencia apt2
          WHERE apt2.actividad_id = a.id AND apt2.alumno_id = ap.alumno_id
          ORDER BY apt2.id DESC LIMIT 1) AS transf_estado
  FROM actividades a
  INNER JOIN actividad_participantes ap ON ap.actividad_id = a.id AND ap.alumno_id = ? AND ap.activo = 1
  LEFT JOIN escuelas e ON e.id = a.escuela_id
  LEFT JOIN pagos_actividades pa ON pa.actividad_id = a.id AND pa.alumno_id = ap.alumno_id
  WHERE a.activo = 1 ORDER BY a.fecha_cierre ASC
";
if ($st = $conn->prepare($sqlAct)) {
  $st->bind_param("i", $alumno_id);
  $st->execute();
  $st->bind_result($aid,$anombre,$amonto,$acierre,$ambito,$pago_act_id,$pago_metodo,$pago_fecha,$act_transf_estado);
  while ($st->fetch()) {
    $is_pagada = !empty($pago_act_id);
    $transf_norm = strtolower(trim((string)$act_transf_estado));
    if ($is_pagada) { $estado_act = 'Pagada'; }
    elseif ($transf_norm === 'pendiente') { $estado_act = 'Pendiente de Aprobación'; }
    else { $estado_act = 'Pendiente'; }
    $actividades[] = [
      'id' => (int)$aid, 'nombre' => (string)$anombre, 'monto' => (int)$amonto,
      'ambito' => (string)$ambito,
      'vence' => ($acierre ? date('d-m-Y', strtotime($acierre)) : ''),
      'estado' => $estado_act, 'pago_metodo' => (string)$pago_metodo,
      'pago_fecha' => $pago_fecha,
      'disabled' => in_array($estado_act, ['Pagada', 'Pendiente de Aprobación'], true)
    ];
  }
  $st->close();
}

$total_pend = 0;
foreach ($pendientes as $c) { if (!$c['disabled']) $total_pend += $c['valor']; }
$total_pag = array_sum(array_column($pagadas,'valor'));

// Mapas JS para el resumen del modal
$cuotas_map_js = [];
foreach ($pendientes as $c) {
  $cuotas_map_js[(string)$c['id']] = [
    'label' => ((int)$c['n'] === 0 ? 'Matrícula' : 'Cuota '.(int)$c['n']),
    'sub'   => 'Vence ' . $c['vence'],
    'valor' => (int)$c['valor'],
  ];
}
$actividades_map_js = [];
foreach ($actividades as $a) {
  $actividades_map_js[(string)$a['id']] = [
    'label' => $a['nombre'],
    'sub'   => 'Vence ' . $a['vence'],
    'valor' => (int)$a['monto'],
  ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Panel del Apoderado</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

<!-- CSS global del sistema: no se carga CSS adicional del panel -->
<link href="../assets/css/sistema.css?v=1" rel="stylesheet">

<style>
/* ============================================================
   Panel Apoderado · version moderna sin header
============================================================ */

body.apo-body {
  background: transparent;
  color: var(--text, #102a43);
  font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  font-size: 13px;
  margin: 0;
}

.apo-page { width: 100%; max-width: 100%; margin: 0; }

.apo-shell-card {
  display: block;
  width: 100%;
  padding: 12px;
  border: 1px solid rgba(15,118,110,.14);
  border-radius: 20px;
  background:
    linear-gradient(180deg, rgba(255,255,255,.84), rgba(255,255,255,.58)),
    radial-gradient(760px 260px at 12% 0%, rgba(204,248,241,.34), transparent 72%);
  box-shadow:
    0 16px 42px rgba(16,42,67,.06),
    inset 0 1px 0 rgba(255,255,255,.72);
}

.apo-mini-toolbar,
.apo-summary-card,
.apo-card,
.apo-quick-nav,
.apo-selection-bar {
  border: 1px solid rgba(15,118,110,.16) !important;
  background:
    linear-gradient(180deg, rgba(255,255,255,.86), rgba(255,255,255,.62)),
    radial-gradient(560px 210px at 8% 0%, rgba(204,251,241,.26), transparent 72%) !important;
  box-shadow: 0 10px 28px rgba(16,42,67,.055) !important;
}

.apo-mini-toolbar {
  display: flex; align-items: center; justify-content: space-between;
  gap: 11px; flex-wrap: wrap; margin: 0 0 10px;
  padding: 10px 12px; border-radius: 18px;
}

.apo-student { display: flex; align-items: center; gap: 10px; min-width: 260px; }

.apo-student-icon {
  width: 38px; height: 38px; border-radius: 14px;
  display: inline-flex; align-items: center; justify-content: center;
  color: #fff;
  background: linear-gradient(135deg, #0f766e, #10b981);
  box-shadow: 0 8px 18px rgba(15,118,110,.16);
  flex-shrink: 0;
}

.apo-student strong {
  display: block; color: #102a43; font-size: .94rem;
  font-weight: 760; line-height: 1.08; letter-spacing: -.02em;
}

.apo-student small {
  display: block; margin-top: 2px; color: #64748b;
  font-size: 10.8px; font-weight: 550;
}

.apo-mini-actions {
  display: flex; align-items: center; justify-content: flex-end;
  gap: 7px; flex-wrap: wrap;
}

.apo-mini-btn,
.btn-modern,
.btn-modal-cancel,
.btn-modal-primary,
.btn-save {
  min-height: 34px !important; padding: 0 11px !important;
  border-radius: 12px !important;
  display: inline-flex !important; align-items: center !important;
  justify-content: center !important; gap: 6px !important;
  font-size: 11.5px !important; font-weight: 720 !important;
  text-decoration: none !important;
  border: 1px solid rgba(15,118,110,.20) !important;
  background: rgba(255,255,255,.78) !important;
  color: #315b7a !important;
  box-shadow: 0 8px 18px rgba(16,42,67,.045) !important;
  transition: transform .15s ease, box-shadow .15s ease, background .15s ease, color .15s ease !important;
  cursor: pointer; white-space: nowrap;
}

.apo-mini-btn:hover,
.btn-modern:hover,
.btn-modal-cancel:hover {
  transform: translateY(-1px);
  box-shadow: 0 12px 22px rgba(16,42,67,.09) !important;
  background: rgba(204,251,241,.58) !important;
  color: #0f766e !important;
}

.apo-mini-btn.primary,
.btn-primary-modern,
.btn-modal-primary,
.btn-save {
  color: #fff !important; border: 0 !important;
  background: linear-gradient(135deg, #0f766e, #10b981) !important;
  box-shadow: 0 10px 22px rgba(15,118,110,.18) !important;
}

.apo-mini-btn.primary:hover,
.btn-primary-modern:hover,
.btn-modal-primary:hover,
.btn-save:hover {
  color: #fff !important;
  background: linear-gradient(135deg, #0b5f59, #059669) !important;
}

.apo-mini-btn.danger {
  color: #b42318 !important;
  background: rgba(255,241,242,.72) !important;
  border-color: rgba(244,63,94,.22) !important;
}

.apo-mini-btn.danger:hover {
  color: #991b1b !important;
  background: rgba(254,226,226,.88) !important;
}

.apo-quick-nav {
  position: sticky; top: 0; z-index: 40;
  display: flex; align-items: center; gap: 7px; flex-wrap: wrap;
  margin: 0 0 10px; padding: 7px;
  border-radius: 16px; backdrop-filter: blur(12px);
}

.apo-quick-nav a {
  min-height: 30px; padding: 0 10px; border-radius: 999px;
  display: inline-flex; align-items: center; gap: 6px;
  color: #315b7a; background: rgba(255,255,255,.72);
  border: 1px solid rgba(15,118,110,.13);
  font-size: 11px; font-weight: 720; text-decoration: none;
}

.apo-quick-nav a:hover { color: #0f766e; background: rgba(204,251,241,.58); }

.alert {
  border-radius: 15px !important;
  border: 1px solid rgba(20,184,166,.24) !important;
  box-shadow: 0 8px 20px rgba(16,42,67,.045);
  font-size: 12px; font-weight: 700;
}

.apo-summary-card,
.card-lite {
  border-radius: 18px !important;
  border: 1px solid rgba(15,118,110,.16) !important;
  background: rgba(255,255,255,.78) !important;
  box-shadow: 0 8px 20px rgba(16,42,67,.045) !important;
}

.apo-summary-card { padding: 12px !important; }

.module-card-icon {
  width: 34px !important; height: 34px !important; min-width: 34px !important;
  border-radius: 13px !important;
  display: inline-flex !important; align-items: center !important; justify-content: center !important;
  color: #0f766e !important;
  background: rgba(204,251,241,.70) !important;
  border: 1px solid rgba(20,184,166,.22) !important;
  box-shadow: inset 0 1px 0 rgba(255,255,255,.86) !important;
}

.section-title-text {
  color: #102a43 !important; font-size: .90rem !important;
  font-weight: 800 !important; letter-spacing: -.02em !important;
}

.small-muted {
  color: #64748b !important; font-size: 11.2px !important; font-weight: 550 !important;
}

.pill {
  min-height: 24px !important; padding: 0 8px !important;
  border-radius: 999px !important;
  display: inline-flex !important; align-items: center !important; gap: 5px !important;
  font-size: 10.4px !important; font-weight: 780 !important;
  border: 1px solid transparent;
}

.pill-success { color: #0f766e !important; background: rgba(204,251,241,.72) !important; border-color: rgba(20,184,166,.24) !important; }
.pill-warning { color: #92400e !important; background: rgba(254,243,199,.76) !important; border-color: rgba(251,191,36,.28) !important; }
.pill-danger  { color: #be123c !important; background: rgba(255,228,230,.76) !important; border-color: rgba(251,113,133,.28) !important; }
.pill-info    { color: #0369a1 !important; background: rgba(224,242,254,.78) !important; border-color: rgba(14,165,233,.24) !important; }

.apo-card { border-radius: 18px !important; overflow: hidden !important; margin-bottom: 12px !important; }

.apo-section-head {
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; flex-wrap: wrap;
  padding: 12px !important;
  border-bottom: 1px solid rgba(15,118,110,.12) !important;
  background: linear-gradient(135deg, rgba(240,253,250,.76), rgba(255,255,255,.86));
}

.table-responsive { border-radius: 0 0 18px 18px; scrollbar-width: thin; }

.table { margin-bottom: 0 !important; font-size: 11.5px !important; }

.table thead th {
  background: rgba(236,253,245,.96) !important;
  color: #416984 !important;
  border: 0 !important;
  border-bottom: 1px solid rgba(15,118,110,.18) !important;
  padding: 9px 8px !important;
  font-size: 9.3px !important; letter-spacing: .055em !important;
  text-transform: uppercase !important; white-space: nowrap !important;
  vertical-align: middle !important; font-weight: 800 !important;
}

.table tbody td {
  padding: 8px !important; height: 42px !important;
  color: #102a43 !important;
  border-bottom: 1px solid rgba(15,118,110,.10) !important;
  vertical-align: middle !important;
  background: rgba(255,255,255,.72) !important;
}

.table tbody tr:hover td { background: rgba(236,253,245,.48) !important; }

.form-check-input { border-color: rgba(15,118,110,.30) !important; cursor: pointer; }
.form-check-input:checked { background-color: #0f766e !important; border-color: #0f766e !important; }
.form-check-input:disabled { opacity: .38; cursor: not-allowed; }

.kpi-value, #totalItems {
  color: #0f766e !important;
  font-size: 1.25rem !important;
  font-weight: 850 !important;
  font-variant-numeric: tabular-nums;
}

/* ===========================================================
   ★★★ MODAL DE PAGO REDISEÑADO ★★★
   =========================================================== */

#modalPago .modal-dialog { max-width: 720px; }

#modalPago .modal-content {
  border: 1px solid rgba(15,118,110,.16) !important;
  border-radius: 22px !important;
  overflow: hidden !important;
  box-shadow: 0 30px 80px rgba(16,42,67,.22) !important;
}

#modalPago .modal-header {
  background: linear-gradient(135deg, #0f766e, #10b981) !important;
  border-bottom: 0 !important;
  padding: 16px 20px !important;
  color: #fff !important;
}

#modalPago .modal-header .modal-title {
  color: #fff !important;
  font-weight: 850 !important;
  font-size: 1rem !important;
  display: flex; align-items: center; gap: 8px;
}

#modalPago .modal-header .btn-close {
  filter: brightness(0) invert(1);
  opacity: .85;
}

#modalPago .modal-body {
  padding: 0 !important;
  background: linear-gradient(180deg, rgba(248,253,251,1), rgba(255,255,255,1));
}

#modalPago .modal-footer {
  border-top: 1px solid rgba(15,118,110,.12) !important;
  background: rgba(248,250,252,.92) !important;
  padding: 12px 16px !important;
}

/* === Stepper visual === */
.pay-stepper {
  display: flex; align-items: center; justify-content: center;
  gap: 8px; padding: 14px 20px 10px;
  background: linear-gradient(180deg, rgba(236,253,245,.55), rgba(255,255,255,0));
}

.pay-step {
  display: flex; align-items: center; gap: 8px;
  padding: 6px 12px 6px 8px;
  border-radius: 999px;
  background: rgba(255,255,255,.85);
  border: 1px solid rgba(15,118,110,.14);
  font-size: 11px; font-weight: 720;
  color: #64748b;
  transition: all .25s ease;
}

.pay-step .pay-step-num {
  width: 22px; height: 22px; border-radius: 999px;
  display: inline-flex; align-items: center; justify-content: center;
  background: rgba(100,116,139,.15);
  color: #64748b;
  font-weight: 850;
  font-size: 11px;
  transition: all .25s ease;
}

.pay-step.is-active {
  background: linear-gradient(135deg, #0f766e, #10b981);
  border-color: transparent;
  color: #fff;
  box-shadow: 0 8px 18px rgba(15,118,110,.25);
}

.pay-step.is-active .pay-step-num {
  background: rgba(255,255,255,.28);
  color: #fff;
}

.pay-step.is-done {
  background: rgba(204,251,241,.85);
  border-color: rgba(20,184,166,.32);
  color: #0f766e;
}

.pay-step.is-done .pay-step-num {
  background: #0f766e;
  color: #fff;
}

.pay-step-arrow {
  color: rgba(15,118,110,.3);
  font-size: 10px;
}

/* === Resumen del pago dentro del modal === */
.pay-summary {
  margin: 0 16px 12px;
  padding: 12px 14px;
  border-radius: 16px;
  background: linear-gradient(135deg, rgba(204,251,241,.55), rgba(255,255,255,.95));
  border: 1px solid rgba(20,184,166,.22);
}

.pay-summary-head {
  display: flex; align-items: center; justify-content: space-between;
  gap: 10px; margin-bottom: 8px;
}

.pay-summary-label {
  font-size: 10.5px;
  font-weight: 780;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: .06em;
}

.pay-summary-total {
  color: #0f766e;
  font-weight: 900;
  font-size: 1.4rem;
  font-variant-numeric: tabular-nums;
  letter-spacing: -.02em;
}

.pay-summary-list {
  list-style: none;
  margin: 0; padding: 0;
  max-height: 110px;
  overflow-y: auto;
}

.pay-summary-list li {
  display: flex; align-items: center; justify-content: space-between;
  gap: 10px;
  padding: 6px 0;
  border-top: 1px dashed rgba(15,118,110,.18);
  font-size: 11.5px;
}

.pay-summary-list li:first-child { border-top: 0; }

.pay-summary-list .it-name {
  color: #102a43; font-weight: 720;
  display: flex; flex-direction: column;
}

.pay-summary-list .it-sub {
  color: #64748b; font-size: 10.2px; font-weight: 550;
}

.pay-summary-list .it-val {
  color: #0f766e; font-weight: 800;
  font-variant-numeric: tabular-nums;
}

/* === Cards de método de pago (★ EL DESTAQUE FUERTE) === */
.pay-method-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  padding: 4px 16px 16px;
}

/* Cuando Webpay está desactivado, Transferencia ocupa todo el ancho del modal */
.pay-method-grid.pay-method-grid-single {
  grid-template-columns: 1fr !important;
}

.pay-method-grid.pay-method-grid-single .metodo-card {
  width: 100%;
  min-height: 150px;
}

.metodo-card {
  position: relative;
  display: block;
  padding: 16px;
  border-radius: 16px;
  background: rgba(255,255,255,.92);
  border: 2px solid rgba(15,118,110,.14);
  cursor: pointer;
  transition: all .22s cubic-bezier(.4,.0,.2,1);
  overflow: hidden;
  text-align: left;
}

.metodo-card::before {
  content: '';
  position: absolute;
  inset: 0;
  border-radius: 14px;
  background: linear-gradient(135deg, rgba(15,118,110,.05), rgba(16,185,129,.02));
  opacity: 0;
  transition: opacity .22s ease;
  pointer-events: none;
}

.metodo-card:hover {
  transform: translateY(-2px);
  border-color: rgba(15,118,110,.32);
  box-shadow: 0 16px 32px rgba(16,42,67,.10);
}

.metodo-card:hover::before { opacity: 1; }

/* ★★★ ESTADO SELECCIONADO — muy destacado ★★★ */
.metodo-card.is-selected {
  border-color: #0f766e !important;
  background: linear-gradient(135deg, rgba(204,251,241,.65), rgba(255,255,255,.98)) !important;
  transform: translateY(-3px);
  box-shadow:
    0 0 0 4px rgba(15,118,110,.14),
    0 18px 36px rgba(15,118,110,.20) !important;
}

.metodo-card.is-selected::before {
  opacity: 1;
  background: linear-gradient(135deg, rgba(15,118,110,.10), rgba(16,185,129,.05));
}

/* Check de selección en la esquina */
.metodo-check {
  position: absolute;
  top: 10px;
  right: 10px;
  width: 26px; height: 26px;
  border-radius: 999px;
  background: rgba(255,255,255,.95);
  border: 2px solid rgba(15,118,110,.20);
  display: flex; align-items: center; justify-content: center;
  font-size: 12px;
  color: transparent;
  transition: all .22s ease;
  z-index: 2;
}

.metodo-card.is-selected .metodo-check {
  background: #0f766e;
  border-color: #0f766e;
  color: #fff;
  transform: scale(1.05);
  box-shadow: 0 4px 12px rgba(15,118,110,.32);
}

.metodo-icon-big {
  width: 48px; height: 48px;
  border-radius: 14px;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 1.3rem;
  background: linear-gradient(135deg, rgba(204,251,241,.72), rgba(236,253,245,.92));
  color: #0f766e;
  border: 1px solid rgba(20,184,166,.22);
  margin-bottom: 10px;
  transition: all .25s ease;
}

.metodo-card.is-selected .metodo-icon-big {
  background: linear-gradient(135deg, #0f766e, #10b981);
  color: #fff;
  border-color: transparent;
  transform: scale(1.06);
}

.metodo-name {
  font-size: .92rem;
  font-weight: 850;
  color: #102a43;
  letter-spacing: -.015em;
  margin-bottom: 4px;
  display: block;
}

.metodo-desc {
  font-size: 11.2px;
  font-weight: 550;
  color: #64748b;
  line-height: 1.35;
  margin-bottom: 10px;
}

.metodo-tags {
  display: flex; flex-wrap: wrap; gap: 4px;
}

.metodo-tag {
  font-size: 9.5px;
  font-weight: 780;
  padding: 3px 7px;
  border-radius: 999px;
  background: rgba(15,118,110,.08);
  color: #0f766e;
  border: 1px solid rgba(15,118,110,.14);
  display: inline-flex; align-items: center; gap: 3px;
}

.metodo-card.is-selected .metodo-tag {
  background: rgba(255,255,255,.85);
  border-color: rgba(15,118,110,.25);
}

/* === Paso 2 contenedores === */
.pay-step-body {
  padding: 4px 16px 16px;
}

.pay-step-body.d-none { display: none !important; }

/* === Webpay confirmación === */
.webpay-confirm {
  text-align: center;
  padding: 18px 14px;
  border-radius: 16px;
  background: linear-gradient(180deg, rgba(255,255,255,.9), rgba(248,253,251,.95));
  border: 1px solid rgba(15,118,110,.14);
}

.webpay-confirm .wp-icon {
  width: 64px; height: 64px;
  border-radius: 18px;
  margin: 0 auto 10px;
  display: flex; align-items: center; justify-content: center;
  background: linear-gradient(135deg, #0f766e, #10b981);
  color: #fff;
  font-size: 1.6rem;
  box-shadow: 0 14px 30px rgba(15,118,110,.25);
}

.webpay-confirm h6 {
  font-weight: 850; color: #102a43;
  margin-bottom: 4px; font-size: .96rem;
}

.webpay-confirm .wp-desc {
  font-size: 12px; color: #475569; margin-bottom: 10px;
}

.webpay-brands {
  display: flex; gap: 5px; justify-content: center; flex-wrap: wrap;
  margin-bottom: 10px;
}

.webpay-brands .wp-brand {
  padding: 4px 9px;
  border-radius: 6px;
  background: #fff;
  border: 1px solid rgba(15,118,110,.16);
  font-size: 10px; font-weight: 800; color: #475569;
  letter-spacing: .03em;
}

.webpay-secure {
  font-size: 10.5px; color: #64748b; font-weight: 600;
  display: inline-flex; align-items: center; gap: 5px;
  padding: 5px 10px;
  border-radius: 999px;
  background: rgba(204,251,241,.55);
  border: 1px solid rgba(20,184,166,.22);
}

/* === Transferencia: datos bancarios === */
.bank-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  margin-bottom: 10px;
}

.bank-cell {
  padding: 9px 11px;
  border-radius: 12px;
  background: #fff;
  border: 1px solid rgba(15,118,110,.16);
  display: flex; align-items: center; justify-content: space-between;
  gap: 8px;
  transition: all .15s ease;
}

.bank-cell:hover {
  border-color: rgba(15,118,110,.30);
  background: rgba(236,253,245,.48);
}

.bank-cell-info { min-width: 0; flex: 1; }

.bank-cell-label {
  font-size: 9.5px;
  font-weight: 780;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: .06em;
  margin-bottom: 2px;
}

.bank-cell-value {
  font-size: 12.5px;
  font-weight: 800;
  color: #102a43;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.bank-cell-copy {
  width: 28px; height: 28px;
  border-radius: 8px;
  border: 1px solid rgba(15,118,110,.20);
  background: #fff;
  color: #0f766e;
  cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  transition: all .15s ease;
  font-size: 11px;
}

.bank-cell-copy:hover {
  background: #0f766e; color: #fff; border-color: #0f766e;
}

.bank-cell-copy.is-copied {
  background: #10b981; color: #fff; border-color: #10b981;
}

.bank-copy-all {
  width: 100%;
  margin-top: 4px;
  padding: 9px;
  border-radius: 12px;
  background: #fff;
  border: 1px dashed rgba(15,118,110,.28);
  color: #0f766e;
  font-weight: 780;
  font-size: 11.5px;
  cursor: pointer;
  display: flex; align-items: center; justify-content: center; gap: 6px;
  transition: all .15s ease;
}

.bank-copy-all:hover { background: rgba(204,251,241,.55); border-style: solid; }

/* === Pasos numerados de la transferencia === */
.transf-steps {
  display: grid; gap: 8px;
  margin-bottom: 12px;
}

.transf-step {
  display: flex; gap: 10px; align-items: flex-start;
  padding: 9px 11px;
  border-radius: 12px;
  background: rgba(255,255,255,.7);
  border: 1px solid rgba(15,118,110,.12);
}

.transf-step-num {
  width: 22px; height: 22px;
  border-radius: 999px;
  background: #0f766e; color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; font-weight: 850;
  flex-shrink: 0; margin-top: 1px;
}

.transf-step-text {
  font-size: 11.5px; color: #102a43; font-weight: 600;
  line-height: 1.35;
}

.transf-step-text strong { color: #0f766e; }

/* === Subtítulo dentro de bloques === */
.pay-block-title {
  display: flex; align-items: center; gap: 7px;
  font-size: 12px; font-weight: 850; color: #102a43;
  margin-bottom: 9px;
  letter-spacing: -.01em;
}

.pay-block-title i { color: #0f766e; }

/* === Uploader rediseñado === */
#uploader {
  display: block;
  padding: 18px 14px;
  border-radius: 14px;
  border: 2px dashed rgba(15,118,110,.30);
  background: rgba(248,253,251,.88);
  text-align: center;
  cursor: pointer;
  transition: all .2s ease;
  margin: 0;
}

#uploader:hover, #uploader.is-drag {
  border-color: #0f766e;
  background: rgba(204,251,241,.4);
  transform: translateY(-1px);
}

#uploader .up-icon {
  width: 44px; height: 44px;
  border-radius: 14px;
  margin: 0 auto 8px;
  display: flex; align-items: center; justify-content: center;
  background: linear-gradient(135deg, rgba(204,251,241,.72), rgba(236,253,245,.92));
  color: #0f766e;
  font-size: 1.2rem;
  border: 1px solid rgba(20,184,166,.22);
}

#uploader .up-title {
  font-weight: 850; color: #102a43; font-size: 12.5px;
  margin-bottom: 3px;
}

#uploader .up-sub {
  font-size: 10.8px; color: #64748b; font-weight: 600;
}

#filePreview {
  margin-top: 10px;
  padding: 10px 12px;
  border-radius: 12px;
  background: linear-gradient(135deg, rgba(204,251,241,.55), #fff);
  border: 1px solid rgba(20,184,166,.28);
  display: flex; align-items: center; gap: 10px;
}

#filePreview .fp-icon {
  width: 36px; height: 36px;
  border-radius: 10px;
  background: #0f766e; color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: 1rem;
  flex-shrink: 0;
}

#filePreview .fp-info { flex: 1; min-width: 0; }
#filePreview #fpName {
  display: block; font-weight: 800; color: #102a43;
  font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
#filePreview #fpSize {
  display: block; font-size: 10.5px; color: #64748b; font-weight: 600;
}

#filePreview #fpRemove {
  width: 30px; height: 30px;
  border-radius: 8px;
  background: #fff;
  border: 1px solid rgba(244,63,94,.30);
  color: #be123c;
  cursor: pointer;
  flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
}

#filePreview #fpRemove:hover { background: #fee2e2; }

/* === Botones del footer del modal === */
#modalPago .modal-footer .btn-modal-primary,
#modalPago .modal-footer .btn-modal-cancel,
#modalPago .modal-footer .btn-save {
  min-height: 38px !important;
  padding: 0 14px !important;
  font-size: 12px !important;
}

#modalPago .modal-footer .btn-modal-primary:disabled,
#modalPago .modal-footer .btn-save:disabled {
  opacity: .55;
  cursor: not-allowed;
  transform: none !important;
}

/* === Footer del modal en UNA sola fila === */
#modalPago .modal-footer.pay-footer {
  display: flex !important;
  flex-direction: row !important;
  align-items: center !important;
  justify-content: space-between !important;
  gap: 8px !important;
  flex-wrap: nowrap !important;
  padding: 12px 16px !important;
}

#modalPago .pay-footer-right {
  display: flex !important;
  align-items: center !important;
  gap: 8px !important;
  flex-wrap: nowrap !important;
  margin-left: auto;
}

/* d-none debe ganar sobre los display: inline-flex !important de los botones */
#modalPago .pay-footer .d-none,
#modalPago .pay-footer-right .d-none {
  display: none !important;
}

@media (max-width: 480px) {
  #modalPago .modal-footer.pay-footer {
    padding: 10px 12px !important;
    gap: 6px !important;
  }
  #modalPago .pay-footer .btn-modal-primary,
  #modalPago .pay-footer .btn-modal-cancel,
  #modalPago .pay-footer .btn-save {
    padding: 0 10px !important;
    font-size: 11.5px !important;
    min-height: 36px !important;
  }
  /* En pantallas muy estrechas ocultamos el icono del botón principal para que entre el texto */
  #modalPago #btnConfirmarTransf .fa-paper-plane,
  #modalPago #btnIrWebpay .fa-credit-card {
    display: none !important;
  }
}

/* === Loader overlay === */
.pay-loader {
  position: absolute;
  inset: 0;
  background: rgba(255,255,255,.92);
  backdrop-filter: blur(4px);
  display: none;
  flex-direction: column;
  align-items: center; justify-content: center;
  gap: 12px;
  z-index: 10;
  border-radius: 22px;
}

.pay-loader.is-visible { display: flex; }

.pay-loader .spinner {
  width: 44px; height: 44px;
  border: 3px solid rgba(15,118,110,.18);
  border-top-color: #0f766e;
  border-radius: 999px;
  animation: paySpin .8s linear infinite;
}

@keyframes paySpin {
  to { transform: rotate(360deg); }
}

.pay-loader-text {
  font-weight: 800; color: #102a43; font-size: 13px;
}

.pay-loader-sub {
  font-size: 11px; color: #64748b; font-weight: 600;
}

/* === Otros (mantenidos) === */
.modal-content { /* baseline para los demás modales */
  border: 1px solid rgba(15,118,110,.16) !important;
  border-radius: 20px !important;
  overflow: hidden !important;
  box-shadow: 0 24px 70px rgba(16,42,67,.18) !important;
}

.modal-header {
  background: linear-gradient(135deg, rgba(204,251,241,.72), rgba(255,255,255,.94)) !important;
  border-bottom: 1px solid rgba(15,118,110,.14) !important;
}

.modal-title {
  color: #102a43 !important;
  font-weight: 850 !important;
  font-size: .96rem !important;
}

.modal-footer {
  border-top: 1px solid rgba(15,118,110,.12) !important;
  background: rgba(248,250,252,.78) !important;
}

.confirm-card {
  border: 1px solid rgba(15,118,110,.18);
  border-radius: 16px;
  padding: 11px;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: rgba(255,255,255,.78);
}

.confirm-card.confirmed {
  background: rgba(204,251,241,.70);
  border-color: rgba(20,184,166,.24);
}

.toast-saas {
  position: fixed;
  left: 50%; bottom: 18px;
  transform: translateX(-50%);
  z-index: 99999;
  min-height: 36px;
  padding: 8px 13px;
  border-radius: 999px;
  color: #fff;
  background: linear-gradient(135deg, #0f766e, #10b981);
  box-shadow: 0 14px 30px rgba(15,118,110,.22);
  font-size: 12px;
  font-weight: 780;
}

/* =====================================================
   Botón "Pagar seleccionada(s)" con contador y total
   cuando hay items marcados.
   ===================================================== */
#btnPagar.btn-has-selection,
#btnPagarAct.btn-has-selection {
  padding: 0 6px 0 6px !important;
  min-height: 36px !important;
  gap: 8px !important;
  background: linear-gradient(135deg, #0b5f59, #0f766e) !important;
  box-shadow: 0 10px 24px rgba(15,118,110,.28) !important;
  animation: btnSelectionPulse .35s ease;
}

@keyframes btnSelectionPulse {
  0%   { transform: scale(.96); }
  60%  { transform: scale(1.03); }
  100% { transform: scale(1); }
}

#btnPagar.btn-has-selection .sel-badge,
#btnPagarAct.btn-has-selection .sel-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 22px;
  height: 22px;
  padding: 0 6px;
  border-radius: 999px;
  background: #fff;
  color: #0f766e;
  font-weight: 900;
  font-size: 11px;
  font-variant-numeric: tabular-nums;
  box-shadow: 0 2px 6px rgba(0,0,0,.12);
}

#btnPagar.btn-has-selection .sel-label,
#btnPagarAct.btn-has-selection .sel-label {
  font-weight: 700;
}

#btnPagar.btn-has-selection .sel-total,
#btnPagarAct.btn-has-selection .sel-total {
  display: inline-flex;
  align-items: center;
  padding: 0 9px;
  height: 26px;
  border-radius: 999px;
  background: rgba(255,255,255,.20);
  color: #fff;
  font-weight: 800;
  font-size: 11.5px;
  font-variant-numeric: tabular-nums;
  letter-spacing: -.01em;
  border: 1px solid rgba(255,255,255,.18);
}

@media (max-width: 480px) {
  #btnPagar.btn-has-selection .sel-total,
  #btnPagarAct.btn-has-selection .sel-total {
    padding: 0 7px;
    font-size: 11px;
  }
}

.theme-switcher,.theme-toggle,.theme-card,.theme-floating,.floating-theme,
.theme-selector,.tema-switcher,.theme-control,
#themeSwitcher,#theme-switcher,#theme-toggle,
[data-theme-switcher],[class*="theme-switch"],[class*="themeSwitch"],[class*="tema"] {
  display: none !important; visibility: hidden !important; pointer-events: none !important;
}

<?php if (!empty($isEmbed)): ?>
html, body { background: transparent !important; }
.apo-page { padding-top: 8px !important; padding-left: 4px !important; padding-right: 4px !important; margin-top: 0 !important; }
<?php endif; ?>

@media (max-width: 992px) {
  .apo-student, .apo-mini-actions, .apo-mini-btn { width: 100%; }
  .apo-mini-actions { justify-content: flex-start; }
  .apo-summary-card > .row > [class*="col-"] > div {
    border: 1px solid rgba(15,118,110,.14);
    border-radius: 16px;
    padding: 10px;
    background: rgba(255,255,255,.70);
  }
}

@media (max-width: 768px) {
  .apo-page { padding: 6px !important; }
  .apo-shell-card { padding: 10px; border-radius: 18px; }
  .apo-quick-nav { position: static; }
  .apo-quick-nav a { flex: 1 1 calc(50% - 5px); justify-content: center; }
  .apo-section-head .btn-modern, #btnPagar, #btnPagarAct { width: 100%; }
  .apo-selection-bar { left: 8px; right: 8px; justify-content: space-between; flex-wrap: wrap; }
  .apo-selection-bar .apo-mini-btn.primary { flex: 1; }

  /* Modal en mobile */
  .pay-method-grid { grid-template-columns: 1fr; }
  .bank-grid { grid-template-columns: 1fr; }
  .pay-stepper { padding: 10px 12px 6px; gap: 4px; }
  .pay-step { padding: 4px 9px 4px 5px; font-size: 10px; }
  .pay-step .pay-step-num { width: 18px; height: 18px; font-size: 10px; }
}

@media print {
  .no-print, .apo-mini-toolbar, .apo-quick-nav, .apo-selection-bar,
  .btn-modern, .btnPayOne, .modal { display: none !important; }
  .apo-shell-card, .apo-card, .card-lite {
    box-shadow: none !important; border-color: #ddd !important; background: #fff !important;
  }
}
</style>


<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">


<style>
/* ============================================================
   Panel Apoderado · ajuste final cards + fuente web clara
============================================================ */

body.apo-body, body.apo-body * {
  font-family: 'Manrope', 'Inter', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif !important;
}

.topbar, header.topbar, .theme-switcher, .theme-toggle, .theme-card, .theme-floating,
.floating-theme, .theme-selector, .tema-switcher, .theme-control,
#themeSwitcher, #theme-switcher, #theme-toggle,
[data-theme-switcher], [class*="theme-switch"], [class*="themeSwitch"], [class*="tema"] {
  display: none !important; visibility: hidden !important; pointer-events: none !important;
}

.apo-page { max-width: 1260px !important; margin: 0 auto !important; padding-top: 10px !important; }
.apo-shell-card { padding: 14px !important; border-radius: 22px !important; }

.apo-mini-toolbar {
  border-radius: 18px !important; margin: 0 0 12px !important;
  padding: 12px 14px !important;
  background:
    linear-gradient(180deg, rgba(255,255,255,.92), rgba(255,255,255,.72)),
    radial-gradient(560px 180px at 10% 0%, rgba(204,251,241,.40), transparent 72%) !important;
  border: 1px solid rgba(15,118,110,.16) !important;
  box-shadow: 0 10px 26px rgba(16,42,67,.06) !important;
}

.apo-student-icon { width: 42px !important; height: 42px !important; border-radius: 15px !important; font-size: 1rem !important; }
.apo-student strong { font-size: .96rem !important; font-weight: 700 !important; color: #0f2f45 !important; }
.apo-student small { font-size: .73rem !important; font-weight: 500 !important; color: #5f7f99 !important; }

.apo-summary-card { padding: 12px !important; border-radius: 20px !important; }
.apo-summary-card > .row { --bs-gutter-x: 10px !important; --bs-gutter-y: 10px !important; }

.apo-summary-card > .row > [class*="col-"] > div {
  min-height: 74px !important;
  border: 1px solid rgba(15,118,110,.13) !important;
  border-radius: 17px !important;
  padding: 12px !important;
  background: linear-gradient(180deg, rgba(255,255,255,.92), rgba(255,255,255,.78)) !important;
  box-shadow: 0 7px 18px rgba(16,42,67,.04) !important;
}

.apo-summary-card .module-card-icon { width: 36px !important; height: 36px !important; min-width: 36px !important; }

.section-title-text { font-size: .88rem !important; font-weight: 700 !important; color: #0f2f45 !important; letter-spacing: -.015em !important; }
.small-muted { font-size: .70rem !important; font-weight: 500 !important; color: #63809a !important; }
.fw-bold, strong { font-weight: 700 !important; }

.apo-card {
  border-radius: 20px !important;
  background: linear-gradient(180deg, rgba(255,255,255,.90), rgba(255,255,255,.72)) !important;
  border: 1px solid rgba(15,118,110,.16) !important;
  box-shadow: 0 10px 26px rgba(16,42,67,.055) !important;
  overflow: hidden !important;
}

.apo-section-head {
  padding: 12px 14px !important;
  background: linear-gradient(135deg, rgba(236,253,245,.72), rgba(255,255,255,.90)) !important;
}

.apo-section-head h5 i { color: #0f766e !important; }

.apo-section-head .btn-modern, #btnPagar, #btnPagarAct {
  min-height: 34px !important; padding: 0 12px !important;
  border-radius: 12px !important; font-size: .72rem !important; font-weight: 700 !important;
}

.table { font-size: .76rem !important; }
.table thead th { font-size: .64rem !important; font-weight: 700 !important; color: #557590 !important; background: rgba(240,253,250,.96) !important; padding: 8px 10px !important; }
.table tbody td { font-size: .76rem !important; font-weight: 500 !important; padding: 9px 10px !important; height: 40px !important; }
.table tbody td strong { font-weight: 700 !important; color: #0f2f45 !important; }

.pill { font-size: .64rem !important; font-weight: 700 !important; min-height: 23px !important; padding: 0 8px !important; }
.pill-danger { color: #b42318 !important; background: rgba(255,241,242,.82) !important; border-color: rgba(251,113,133,.26) !important; }
.pill-warning { color: #92400e !important; background: rgba(255,251,235,.90) !important; border-color: rgba(251,191,36,.28) !important; }
.pill-success { color: #0f766e !important; background: rgba(236,253,245,.90) !important; border-color: rgba(20,184,166,.24) !important; }
.pill-info { color: #0369a1 !important; background: rgba(239,246,255,.92) !important; border-color: rgba(96,165,250,.24) !important; }

.apo-quick-nav { margin-bottom: 12px !important; border-radius: 18px !important; padding: 8px !important; background: rgba(255,255,255,.78) !important; }
.apo-quick-nav a { font-size: .70rem !important; font-weight: 650 !important; }

.d-md-none .card-lite { padding: 12px !important; border-radius: 16px !important; }

.modal-content { font-family: 'Manrope', 'Inter', system-ui, sans-serif !important; }
.modal-title { font-size: .92rem !important; font-weight: 700 !important; }

.kpi-value, #totalItems { font-size: 1.12rem !important; font-weight: 750 !important; }

.apo-mini-btn, .btn-modern, .btn-modal-cancel, .btn-modal-primary, .btn-save {
  font-size: .72rem !important; font-weight: 650 !important; letter-spacing: -.005em !important;
}

.apo-selection-bar { max-width: 560px !important; margin-left: auto !important; border-radius: 18px !important; background: rgba(255,255,255,.90) !important; }

@media (max-width: 992px) {
  .apo-page { max-width: 100% !important; padding-left: 8px !important; padding-right: 8px !important; }
  .apo-summary-card > .row > [class*="col-"] > div { min-height: auto !important; }
}

@media (max-width: 768px) {
  .apo-shell-card { padding: 10px !important; border-radius: 18px !important; }
  .apo-mini-toolbar, .apo-summary-card, .apo-card { border-radius: 17px !important; }
  .apo-student { min-width: 0 !important; }
  .apo-mini-actions { display: grid !important; grid-template-columns: 1fr 1fr 1fr !important; width: 100% !important; }
  .apo-mini-btn { width: 100% !important; }
  .section-title-text { font-size: .84rem !important; }
}
</style>


<style>
/* ============================================================
   MODAL DE ÉXITO POST-PAGO
============================================================ */
#modalExitoPago .modal-dialog { max-width: 460px; }

.exito-modal {
  position: relative;
  border: 0 !important;
  border-radius: 24px !important;
  overflow: hidden !important;
  box-shadow: 0 30px 90px rgba(15,118,110,.30) !important;
  background: linear-gradient(180deg, #fff 0%, #f8fdfb 100%) !important;
}

.exito-close {
  position: absolute;
  top: 14px; right: 14px;
  z-index: 5;
  width: 32px; height: 32px;
  border-radius: 999px;
  border: 0;
  background: rgba(15,118,110,.06);
  color: #64748b;
  cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  font-size: 14px;
  transition: all .15s ease;
}

.exito-close:hover {
  background: rgba(244,63,94,.12);
  color: #be123c;
}

.exito-body {
  padding: 36px 28px 22px !important;
  position: relative;
}

/* Icono central animado */
.exito-icon-wrap {
  position: relative;
  width: 96px; height: 96px;
  margin: 0 auto 18px;
  display: flex; align-items: center; justify-content: center;
}

.exito-ring {
  position: absolute;
  inset: 0;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(16,185,129,.18) 0%, rgba(16,185,129,0) 70%);
  animation: exitoPulse 2s ease-in-out infinite;
}

.exito-icon {
  position: relative;
  z-index: 2;
  width: 72px; height: 72px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.9rem;
  color: #fff;
  box-shadow: 0 14px 30px rgba(15,118,110,.30);
  animation: exitoPop .55s cubic-bezier(.34, 1.56, .64, 1);
}

.exito-icon-success .exito-icon {
  background: linear-gradient(135deg, #10b981, #059669);
}

.exito-icon-pending .exito-icon {
  background: linear-gradient(135deg, #0f766e, #14b8a6);
}

.exito-icon-pending .exito-ring {
  background: radial-gradient(circle, rgba(20,184,166,.22) 0%, rgba(20,184,166,0) 70%);
}

@keyframes exitoPop {
  0%   { transform: scale(0); opacity: 0; }
  60%  { transform: scale(1.12); opacity: 1; }
  100% { transform: scale(1); opacity: 1; }
}

@keyframes exitoPulse {
  0%, 100% { transform: scale(1);   opacity: .6; }
  50%      { transform: scale(1.18); opacity: 0; }
}

.exito-title {
  font-size: 1.25rem !important;
  font-weight: 850 !important;
  color: #0f2f45 !important;
  letter-spacing: -.02em !important;
  margin: 0 0 8px !important;
}

.exito-desc {
  font-size: 13px;
  color: #475569;
  line-height: 1.5;
  margin: 0 0 16px;
  font-weight: 500;
}

/* Detalles Webpay */
.exito-detail-grid {
  display: grid;
  gap: 6px;
  margin-bottom: 14px;
}

.exito-detail-item {
  display: flex; align-items: center;
  gap: 9px;
  padding: 9px 12px;
  border-radius: 12px;
  background: rgba(204,251,241,.40);
  border: 1px solid rgba(20,184,166,.20);
  font-size: 12px;
  font-weight: 700;
  color: #102a43;
  text-align: left;
}

.exito-detail-item i {
  color: #0f766e;
  font-size: 12px;
  width: 16px;
  text-align: center;
}

/* Tarjeta de status para transferencia */
.exito-status-card {
  background: linear-gradient(180deg, rgba(255,251,235,.55), rgba(255,255,255,.95));
  border: 1px solid rgba(251,191,36,.25);
  border-radius: 16px;
  padding: 14px 16px;
  margin-bottom: 14px;
  text-align: left;
}

.exito-status-row {
  display: flex;
  align-items: flex-start;
  gap: 11px;
  padding: 7px 0;
  position: relative;
}

.exito-status-row + .exito-status-row::before {
  content: '';
  position: absolute;
  left: 5px;
  top: -4px;
  width: 2px;
  height: 12px;
  background: rgba(15,118,110,.18);
}

.exito-status-dot {
  width: 12px; height: 12px;
  border-radius: 50%;
  background: rgba(100,116,139,.20);
  border: 2px solid rgba(100,116,139,.30);
  flex-shrink: 0;
  margin-top: 2px;
  position: relative;
  z-index: 2;
}

.exito-status-dot.is-done {
  background: #10b981;
  border-color: #10b981;
  box-shadow: 0 0 0 3px rgba(16,185,129,.18);
}

.exito-status-dot.is-active {
  background: #f59e0b;
  border-color: #f59e0b;
  box-shadow: 0 0 0 3px rgba(245,158,11,.22);
  animation: exitoDotPulse 1.5s ease-in-out infinite;
}

@keyframes exitoDotPulse {
  0%, 100% { box-shadow: 0 0 0 3px rgba(245,158,11,.22); }
  50%      { box-shadow: 0 0 0 6px rgba(245,158,11,.10); }
}

.exito-status-text {
  display: flex; flex-direction: column;
  font-size: 12.5px;
}

.exito-status-text strong {
  color: #0f2f45;
  font-weight: 750;
  line-height: 1.25;
}

.exito-status-text small {
  color: #64748b;
  font-size: 11px;
  font-weight: 550;
  margin-top: 1px;
}

/* Tip al pie */
.exito-tip {
  font-size: 11.5px;
  color: #64748b;
  font-weight: 600;
  background: rgba(248,250,252,.85);
  border: 1px dashed rgba(15,118,110,.16);
  border-radius: 12px;
  padding: 9px 12px;
  text-align: center;
  line-height: 1.4;
}

.exito-tip i {
  color: #0f766e;
  margin-right: 4px;
}

.exito-tip strong {
  color: #92400e;
  font-weight: 750;
}

/* Footer del modal */
.exito-footer {
  border-top: 0 !important;
  background: transparent !important;
  padding: 0 28px 22px !important;
  justify-content: center !important;
}

.exito-btn-ok {
  min-width: 140px;
  min-height: 42px !important;
  font-size: 13px !important;
  font-weight: 750 !important;
  padding: 0 22px !important;
  border-radius: 14px !important;
}

@media (max-width: 480px) {
  .exito-body { padding: 28px 18px 16px !important; }
  .exito-icon-wrap { width: 80px; height: 80px; margin-bottom: 14px; }
  .exito-icon { width: 60px; height: 60px; font-size: 1.5rem; }
  .exito-title { font-size: 1.1rem !important; }
  .exito-footer { padding: 0 18px 18px !important; }
  .exito-btn-ok { width: 100%; }
}
</style>


<style>
/* ============================================================
   FontAwesome icon fixes
============================================================ */
i.fa, i.fas, i.fa-solid, i.far, i.fa-regular, i.fab, i.fa-brands,
.fa, .fas, .fa-solid, .far, .fa-regular, .fab, .fa-brands {
  display: inline-block !important;
  line-height: 1 !important;
  text-rendering: auto !important;
  -webkit-font-smoothing: antialiased !important;
  -moz-osx-font-smoothing: grayscale !important;
}

i.fa, i.fas, i.fa-solid, .fa, .fas, .fa-solid {
  font-family: "Font Awesome 6 Free" !important; font-weight: 900 !important;
}

i.far, i.fa-regular, .far, .fa-regular {
  font-family: "Font Awesome 6 Free" !important; font-weight: 400 !important;
}

i.fab, i.fa-brands, .fab, .fa-brands {
  font-family: "Font Awesome 6 Brands" !important; font-weight: 400 !important;
}

.apo-student-icon i, .module-card-icon i, .apo-mini-btn i,
.btn-modern i, .btn-modal-cancel i, .btn-modal-primary i,
.btn-save i, .section-title-text i, .pill i,
.small-muted i, .modal-title i, .confirm-card i, .toast-saas i {
  font-size: .92em !important; width: 1em !important;
  min-width: 1em !important; text-align: center !important;
}

.apo-student-icon i, .module-card-icon i { font-size: 1rem !important; }
.section-title-text i { color: #0f766e !important; margin-right: 2px !important; }
.apo-mini-btn i, .btn-modern i, .btn-modal-cancel i,
.btn-modal-primary i, .btn-save i { font-size: .86em !important; }
.pill i { font-size: .82em !important; }
</style>


<style>
/* ============================================================
   Panel Apoderado · ajuste responsive checkboxes en celular
   Respeta estructura y logica actual. Solo corrige tamaño/espacio
   de los checks en cards moviles de cuotas y actividades.
============================================================ */

@media (max-width: 767.98px) {

  /* Evita que el wrapper .form-check herede estilos grandes del sistema */
  #apo-cuotas .d-md-none .form-check,
  #apo-actividades .d-md-none .form-check {
    width: 26px !important;
    min-width: 26px !important;
    max-width: 26px !important;
    height: 26px !important;
    min-height: 26px !important;
    padding: 0 !important;
    margin: 2px 0 0 0 !important;
    border: 0 !important;
    border-radius: 0 !important;
    background: transparent !important;
    box-shadow: none !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex: 0 0 26px !important;
  }

  /* Checkbox real: tamaño normal y proporcionado */
  #apo-cuotas .d-md-none .form-check-input,
  #apo-actividades .d-md-none .form-check-input,
  #apo-cuotas .d-md-none input[type="checkbox"].form-check-input,
  #apo-actividades .d-md-none input[type="checkbox"].form-check-input {
    width: 18px !important;
    height: 18px !important;
    min-width: 18px !important;
    min-height: 18px !important;
    max-width: 18px !important;
    max-height: 18px !important;
    padding: 0 !important;
    margin: 0 !important;
    border-radius: 6px !important;
    border: 1.5px solid rgba(15,118,110,.28) !important;
    background-color: rgba(255,255,255,.92) !important;
    box-shadow: 0 2px 6px rgba(16,42,67,.055) !important;
    float: none !important;
    flex: 0 0 18px !important;
    transform: none !important;
    appearance: auto !important;
    -webkit-appearance: auto !important;
  }

  #apo-cuotas .d-md-none .form-check-input:checked,
  #apo-actividades .d-md-none .form-check-input:checked {
    background-color: #0f766e !important;
    border-color: #0f766e !important;
  }

  #apo-cuotas .d-md-none .form-check-input:disabled,
  #apo-actividades .d-md-none .form-check-input:disabled {
    opacity: .38 !important;
    background-color: rgba(241,245,249,.82) !important;
    border-color: rgba(148,163,184,.32) !important;
    box-shadow: none !important;
  }

  /* Card movil de cuotas: menos espacio a la izquierda */
  #apo-cuotas .d-md-none .card-lite > .d-flex,
  #apo-actividades .d-md-none .card-lite > .d-flex {
    gap: 10px !important;
    align-items: flex-start !important;
  }

  #apo-cuotas .d-md-none .card-lite,
  #apo-actividades .d-md-none .card-lite {
    padding: 12px !important;
    border-radius: 17px !important;
  }

  /* Evita que el contenido se empuje o se corte */
  #apo-cuotas .d-md-none .card-lite .flex-grow-1,
  #apo-actividades .d-md-none .card-lite .flex-grow-1 {
    min-width: 0 !important;
  }

  /* Estado y titulo mas ordenados en la card movil */
  #apo-cuotas .d-md-none .card-lite .pill,
  #apo-actividades .d-md-none .card-lite .pill {
    max-width: 100% !important;
    white-space: normal !important;
    line-height: 1.15 !important;
  }
}
</style>

</head>
<body class="apo-body modulo-embedded-clean no-header-page">

<div class="container-fluid page-wrap apo-page py-2 px-2 px-md-3">
  <section class="apo-shell-card">

    <div class="apo-mini-toolbar no-print">
      <div class="apo-student">
        <span class="apo-student-icon">
          <i class="fa-solid fa-user-shield"></i>
        </span>
        <div class="min-w-0">
          <strong><?= h($alumno_nombre) ?></strong>
          <small>
            RUT <?= h($alumno_rut) ?>
            <?php if (!empty($alumno_categoria)): ?>
              · <?= h($alumno_categoria) ?>
            <?php endif; ?>
          </small>
        </div>
      </div>

      <div class="apo-mini-actions">
        <a href="<?= h(ap_link_embed('ficha_matricula.php', $isEmbed)) ?>" class="apo-mini-btn">
          <i class="fa-solid fa-id-card"></i> Ficha
        </a>
        <a href="<?= h(ap_link_embed('panel_apoderado.php', $isEmbed)) ?>" class="apo-mini-btn">
          <i class="fa-solid fa-rotate"></i> Actualizar
        </a>
        <a href="<?= h(ap_link_embed('logout.php', $isEmbed)) ?>" class="apo-mini-btn danger">
          <i class="fa-solid fa-right-from-bracket"></i> Salir
        </a>
      </div>
    </div>

    <?php
      $pago_exito_tipo = '';
      if (isset($_GET['webpay']) && $_GET['webpay'] === 'ok') {
        $pago_exito_tipo = 'webpay';
      } elseif (isset($_GET['pago']) && $_GET['pago'] === 'ok') {
        // 'pago=ok' lo usa el flujo de transferencia tras enviar comprobante
        $pago_exito_tipo = 'transferencia';
      }
    ?>

    <?php if (isset($_GET['ficha']) && $_GET['ficha'] === 'ok'): ?>
      <div class="alert alert-success mb-3">
        Ficha de matricula actualizada correctamente.
      </div>
    <?php endif; ?>

    <div class="apo-quick-nav no-print">
      <a href="#apo-resumen"><i class="fa-solid fa-gauge-high"></i> Resumen</a>
      <a href="#apo-cuotas"><i class="fa-regular fa-clock"></i> Cuotas</a>
      <a href="#apo-actividades"><i class="fa-solid fa-bolt"></i> Actividades</a>
      <a href="uniformes.php"><i class="fa-solid fa-shirt"></i> Uniformes</a>
      <a href="#apo-historial"><i class="fa-solid fa-circle-check"></i> Historial</a>
    </div>

    <!-- RESUMEN SUPERIOR -->
    <div class="card-lite mb-3 apo-summary-card" id="apo-resumen">
      <div class="row g-3 align-items-stretch">

        <div class="col-12 col-lg-4">
          <div class="h-100 d-flex align-items-center gap-3">
            <div class="module-card-icon flex-shrink-0">
              <i class="fa-solid fa-id-card"></i>
            </div>
            <div class="min-w-0 flex-grow-1">
              <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                <h2 class="section-title-text mb-0">Ficha de matricula</h2>
                <?php if ($ficha_completa): ?>
                  <span class="pill pill-success text-nowrap"><i class="fa-solid fa-circle-check"></i> OK</span>
                <?php else: ?>
                  <span class="pill pill-warning text-nowrap"><i class="fa-solid fa-circle-exclamation"></i> Pendiente</span>
                <?php endif; ?>
              </div>
              <div class="small-muted">
                Datos, salud y emergencia
                <?php if (!empty($ficha_actualizada)): ?>
                  · Act. <?= h(date('d-m-Y H:i', strtotime($ficha_actualizada))) ?>
                <?php endif; ?>
              </div>
            </div>
            <a href="<?= h(ap_link_embed('ficha_matricula.php', $isEmbed)) ?>" class="btn-modern btn-primary-modern justify-content-center text-nowrap flex-shrink-0">
              <i class="fa-solid fa-pen-to-square"></i>
              <?= $ficha_completa ? 'Ficha' : 'Completar' ?>
            </a>
          </div>
        </div>

        <div class="col-12 col-lg-4">
          <?php if ($contrato_file): ?>
            <div class="h-100 d-flex align-items-center gap-3 px-3 py-2 border rounded-4 bg-white">
              <input class="form-check-input d-none" type="checkbox" id="checkContrato" <?= $contrato_aceptado ? 'checked' : '' ?> disabled>
              <div class="module-card-icon flex-shrink-0">
                <i class="fa-regular fa-file-lines"></i>
              </div>
              <div class="min-w-0 flex-grow-1">
                <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                  <span class="fw-semibold">Contrato</span>
                  <?php if ($contrato_aceptado): ?>
                    <span class="pill pill-success text-nowrap"><i class="fa-solid fa-circle-check"></i> Aceptado</span>
                  <?php else: ?>
                    <span class="pill pill-warning text-nowrap"><i class="fa-solid fa-circle-exclamation"></i> Pendiente</span>
                  <?php endif; ?>
                </div>
                <?php if ($contrato_aceptado && $contrato_fecha_txt): ?>
                  <div class="small-muted"><i class="fa-regular fa-clock"></i> <?= h($contrato_fecha_txt) ?></div>
                <?php endif; ?>
              </div>
              <a href="<?= $contrato_file ?>" target="_blank" class="btn-modern justify-content-center text-nowrap flex-shrink-0">
                <i class="fa-regular fa-file-pdf"></i> Ver
              </a>
            </div>
          <?php endif; ?>
        </div>

        <div class="col-12 col-lg-4">
          <div class="h-100 d-flex align-items-center justify-content-lg-end gap-2 flex-wrap">
            <span class="pill pill-warning text-nowrap"><i class="fa-solid fa-clock"></i> Pend. <strong><?= count($pendientes) ?></strong></span>
            <span class="pill pill-warning text-nowrap"><strong><?= clp($total_pend) ?></strong></span>
            <span class="pill pill-success text-nowrap"><i class="fa-solid fa-circle-check"></i> Pag. <strong><?= count($pagadas) ?></strong></span>
            <span class="pill pill-success text-nowrap"><strong><?= clp($total_pag) ?></strong></span>
            <a href="<?= h(ap_link_embed('panel_apoderado.php', $isEmbed)) ?>" class="btn-modern justify-content-center text-nowrap">
              <i class="fa-solid fa-rotate"></i> Actualizar
            </a>
          </div>
        </div>

      </div>
    </div>

    <!-- CUOTAS -->
    <div class="card mb-4 apo-card" id="apo-cuotas">
      <div class="card-body p-0">
        <div class="apo-section-head">
          <h5 class="section-title-text mb-0"><i class="fa-regular fa-clock"></i> Cuotas</h5>
          <div class="d-grid d-sm-flex gap-2 flex-wrap w-100 w-md-auto">
            <button type="button" class="btn-modern btn-primary-modern justify-content-center" id="btnPagar">
              <i class="fa-solid fa-wallet"></i> Pagar seleccionada(s)
            </button>
          </div>
        </div>

        <div class="d-md-none p-3">
          <?php if (!$pendientes): ?>
            <div class="text-center py-4 small-muted">
              <i class="fa-solid fa-circle-check text-matricula me-1"></i>
              Sin cuotas pendientes
            </div>
          <?php endif; ?>

          <div class="d-grid gap-2">
            <?php foreach($pendientes as $c):
              $est = strtolower(trim($c['estado']));
              if (str_contains($est, 'aprob')) $pill = 'pill-warning';
              elseif ($est === 'pagada') $pill = 'pill-success';
              else $pill = 'pill-danger';
            ?>
              <div class="card-lite">
                <div class="d-flex align-items-start justify-content-between gap-3">
                  <div class="form-check m-0">
                    <input class="form-check-input chkCuota" type="checkbox"
                           name="cuotas[]" value="<?= $c['id'] ?>"
                           data-valor="<?= $c['valor'] ?>"
                           <?= $c['disabled'] ? 'disabled' : '' ?>>
                  </div>
                  <div class="flex-grow-1">
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                      <strong>Cuota <?= $c['n'] ?></strong>
                      <span class="pill <?= $pill ?>"><?= h($c['estado']) ?></span>
                    </div>
                    <?php if ((int)$c['n'] === 0): ?>
                      <span class="pill pill-info mb-2">Matricula</span>
                    <?php endif; ?>
                    <div class="small-muted">Vencimiento: <?= h($c['vence']) ?></div>
                    <div class="fw-bold mt-1"><?= clp($c['valor']) ?></div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="table-responsive d-none d-md-block">
          <table class="table table-hover align-middle mb-0">
            <thead>
              <tr>
                <th><input class="form-check-input" type="checkbox" id="chkAllCuotas"></th>
                <th>#</th>
                <th>Vencimiento</th>
                <th>Monto</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$pendientes): ?>
                <tr><td colspan="5" class="text-center py-5 small-muted">
                  <i class="fa-solid fa-circle-check text-matricula me-1"></i>
                  Sin cuotas pendientes
                </td></tr>
              <?php endif; ?>

              <?php foreach($pendientes as $c):
                $est = strtolower(trim($c['estado']));
                if (str_contains($est, 'aprob')) $pill = 'pill-warning';
                elseif ($est === 'pagada') $pill = 'pill-success';
                else $pill = 'pill-danger';
              ?>
                <tr>
                  <td>
                    <input class="form-check-input chkCuota" type="checkbox"
                           name="cuotas[]" value="<?= $c['id'] ?>"
                           data-valor="<?= $c['valor'] ?>"
                           <?= $c['disabled'] ? 'disabled' : '' ?>>
                  </td>
                  <td>
                    <strong><?= $c['n'] ?></strong>
                    <?php if ((int)$c['n'] === 0): ?>
                      <span class="pill pill-info ms-1">Matricula</span>
                    <?php endif; ?>
                  </td>
                  <td><?= h($c['vence']) ?></td>
                  <td><strong><?= clp($c['valor']) ?></strong></td>
                  <td><span class="pill <?= $pill ?>"><?= h($c['estado']) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="small-muted p-3 border-top">
          <i class="fa-solid fa-circle-info"></i>
          Selecciona una o más cuotas y elige tu forma de pago: Webpay o transferencia.
        </div>
      </div>
    </div>

    <!-- HISTORIAL -->
    <div class="card mb-4 apo-card" id="apo-historial">
      <div class="card-body p-0">
        <div class="apo-section-head">
          <h5 class="section-title-text mb-0"><i class="fa-solid fa-circle-check"></i> Historial de Pagos</h5>
        </div>
        <div class="d-md-none p-3">
          <?php if (!$pagadas): ?>
            <div class="text-center py-4 small-muted">
              <i class="fa-regular fa-folder-open me-1"></i>
              Sin pagos registrados
            </div>
          <?php endif; ?>
          <div class="d-grid gap-2">
            <?php $i=1; foreach($pagadas as $c): ?>
              <div class="card-lite">
                <div class="d-flex align-items-start justify-content-between gap-2 mb-1">
                  <strong>Pago <?= $i++ ?></strong>
                  <?php if ((int)$c['n'] === 0): ?><span class="pill pill-info">Matricula</span><?php endif; ?>
                </div>
                <div class="small-muted">Vencimiento: <?= h($c['vence']) ?></div>
                <div class="small-muted">Metodo: <?= h($c['metodo'] ?: 'Transferencia o Deposito') ?></div>
                <div class="small-muted">Fecha de pago: <?= $c['fecha_pago'] ? date('d-m-Y H:i', strtotime($c['fecha_pago'])) : '—' ?></div>
                <div class="fw-bold mt-1"><?= clp($c['valor']) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="table-responsive d-none d-md-block">
          <table class="table table-hover align-middle mb-0">
            <thead>
              <tr>
                <th>#</th>
                <th>Vencimiento</th>
                <th>Monto</th>
                <th>Metodo</th>
                <th>Fecha de pago</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$pagadas): ?>
                <tr><td colspan="5" class="text-center py-5 small-muted">
                  <i class="fa-regular fa-folder-open me-1"></i>
                  Sin pagos registrados
                </td></tr>
              <?php endif; ?>
              <?php $i=1; foreach($pagadas as $c): ?>
                <tr>
                  <td><strong><?= $i++ ?></strong></td>
                  <td><?= h($c['vence']) ?></td>
                  <td>
                    <strong><?= clp($c['valor']) ?></strong>
                    <?php if ((int)$c['n'] === 0): ?><span class="pill pill-info ms-1">Matricula</span><?php endif; ?>
                  </td>
                  <td><?= h($c['metodo'] ?: 'Transferencia o Deposito') ?></td>
                  <td><?= $c['fecha_pago'] ? date('d-m-Y H:i', strtotime($c['fecha_pago'])) : '—' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ACTIVIDADES -->
    <div class="card mb-4 apo-card" id="apo-actividades">
      <div class="card-body p-0">
        <div class="apo-section-head">
          <h5 class="section-title-text mb-0"><i class="fa-solid fa-bolt"></i> Actividades</h5>
          <button type="button" class="btn-modern btn-primary-modern justify-content-center" id="btnPagarAct">
            <i class="fa-solid fa-wallet"></i> Pagar seleccionadas
          </button>
        </div>
        <div class="d-md-none p-3">
          <?php if (!$actividades): ?>
            <div class="text-center py-4 small-muted">
              <i class="fa-regular fa-calendar-xmark me-1"></i>
              No hay actividades vigentes
            </div>
          <?php endif; ?>
          <div class="d-grid gap-2">
            <?php foreach($actividades as $a):
              $est = strtolower($a['estado']);
              if ($est === 'pagada') $pill = 'pill-success';
              elseif (str_contains($est, 'aprob')) $pill = 'pill-warning';
              else $pill = 'pill-danger';
            ?>
              <div class="card-lite">
                <div class="d-flex align-items-start gap-3">
                  <input class="form-check-input chkAct mt-1" type="checkbox"
                         name="actividades[]" value="<?= $a['id'] ?>"
                         data-valor="<?= $a['monto'] ?>"
                         <?= $a['disabled'] ? 'disabled' : '' ?>>
                  <div class="flex-grow-1">
                    <div class="d-flex align-items-start justify-content-between gap-2 mb-1">
                      <strong><?= h($a['nombre']) ?></strong>
                      <span class="pill <?= $pill ?>"><?= h($a['estado']) ?></span>
                    </div>
                    <div class="small-muted">
                      <?php if (strtolower($a['estado']) === 'pagada'): ?>
                        Pagado por <?= h($a['pago_metodo'] ?: '—') ?> · <?= $a['pago_fecha'] ? date('d-m-Y', strtotime($a['pago_fecha'])) : '—' ?>
                      <?php else: ?>
                        Sin pago registrado
                      <?php endif; ?>
                    </div>
                    <div class="small-muted">Vence: <?= h($a['vence']) ?></div>
                    <div class="fw-bold mt-1"><?= clp($a['monto']) ?></div>
                    <button type="button"
                            class="btn-modern btn-primary-modern btnPayOne justify-content-center w-100 mt-2"
                            data-tipo="actividad"
                            data-id="<?= $a['id'] ?>"
                            data-valor="<?= $a['monto'] ?>"
                            <?= $a['disabled'] ? 'disabled' : '' ?>>
                      <i class="fa-solid fa-wallet"></i> Pagar
                    </button>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="table-responsive d-none d-md-block">
          <table class="table table-hover align-middle mb-0">
            <thead>
              <tr>
                <th><input class="form-check-input" type="checkbox" id="chkAllAct"></th>
                <th>Actividad</th>
                <th>Monto</th>
                <th>Vence</th>
                <th>Estado</th>
                <th class="text-end">Acción</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$actividades): ?>
                <tr><td colspan="6" class="text-center py-5 small-muted">
                  <i class="fa-regular fa-calendar-xmark me-1"></i>
                  No hay actividades vigentes
                </td></tr>
              <?php endif; ?>
              <?php foreach($actividades as $a):
                $est = strtolower($a['estado']);
                if ($est === 'pagada') $pill = 'pill-success';
                elseif (str_contains($est, 'aprob')) $pill = 'pill-warning';
                else $pill = 'pill-danger';
              ?>
                <tr>
                  <td>
                    <input class="form-check-input chkAct" type="checkbox"
                           name="actividades[]" value="<?= $a['id'] ?>"
                           data-valor="<?= $a['monto'] ?>"
                           <?= $a['disabled'] ? 'disabled' : '' ?>>
                  </td>
                  <td>
                    <strong><?= h($a['nombre']) ?></strong>
                    <div class="small-muted">
                      <?php if (strtolower($a['estado']) === 'pagada'): ?>
                        Pagado por <?= h($a['pago_metodo'] ?: '—') ?> · <?= $a['pago_fecha'] ? date('d-m-Y', strtotime($a['pago_fecha'])) : '—' ?>
                      <?php else: ?>
                        Sin pago registrado
                      <?php endif; ?>
                    </div>
                  </td>
                  <td><strong><?= clp($a['monto']) ?></strong></td>
                  <td><?= h($a['vence']) ?></td>
                  <td><span class="pill <?= $pill ?>"><?= h($a['estado']) ?></span></td>
                  <td class="text-end">
                    <button type="button"
                            class="btn-modern btn-primary-modern btnPayOne"
                            data-tipo="actividad"
                            data-id="<?= $a['id'] ?>"
                            data-valor="<?= $a['monto'] ?>"
                            <?= $a['disabled'] ? 'disabled' : '' ?>>
                      <i class="fa-solid fa-wallet"></i> Pagar
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- UNIFORMES -->
    <div class="card mb-4 apo-card" id="apo-uniformes">
      <div class="card-body p-0">
        <div class="apo-section-head">
          <h5 class="section-title-text mb-0"><i class="fa-solid fa-shirt"></i> Uniformes</h5>
          <a href="uniformes.php" class="btn-modern btn-primary-modern justify-content-center">
            <i class="fa-solid fa-cart-shopping"></i> Comprar uniforme
          </a>
        </div>
        <div class="p-3 p-lg-4">
          <div class="card-lite mb-0">
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
              <div>
                <strong>Compra de uniformes independiente de cuotas</strong>
                <div class="small-muted mt-1">
                  Puedes comprar poleras, shorts, buzos u otros productos configurados por la escuela. Cada solicitud queda registrada como ingreso propio de la escuela y se revisa aparte de las cuotas mensuales.
                </div>
              </div>
              <a href="uniformes.php" class="btn-modern btn-primary-modern justify-content-center">
                <i class="fa-solid fa-shirt"></i> Ver uniformes disponibles
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>

  </section>
</div>


<!-- =============================================
     ★★★ MODAL DE PAGO REDISEÑADO ★★★
     Mantiene IDs y campos POST originales:
     - formPago, action enviar_pago_cobranzas.php
     - cuotas_ids, actividades_ids, alumno_id, metodo_pago, comprobante
     - btnPagar, btnPagarAct, btnPayOne disparadores
     ============================================= -->
<div class="modal fade" id="modalPago" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <form method="POST" action="enviar_pago_cobranzas.php" enctype="multipart/form-data" id="formPago">
      <div class="modal-content position-relative">

        <!-- Loader overlay -->
        <div class="pay-loader" id="payLoader">
          <div class="spinner"></div>
          <div class="pay-loader-text" id="payLoaderText">Procesando...</div>
          <div class="pay-loader-sub" id="payLoaderSub">Por favor no cierres esta ventana</div>
        </div>

        <div class="modal-header">
          <h6 class="modal-title">
            <i class="fa-solid fa-wallet"></i>
            <span id="payModalTitle">Realizar pago</span>
          </h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>

        <div class="modal-body">

          <!-- Stepper -->
          <div class="pay-stepper">
            <div class="pay-step is-active" id="payStep1">
              <span class="pay-step-num">1</span>
              <span>Método</span>
            </div>
            <i class="fa-solid fa-angle-right pay-step-arrow"></i>
            <div class="pay-step" id="payStep2">
              <span class="pay-step-num">2</span>
              <span id="payStep2Label">Confirmar</span>
            </div>
          </div>

          <!-- Resumen del pago -->
          <div class="pay-summary">
            <div class="pay-summary-head">
              <div>
                <div class="pay-summary-label">
                  <i class="fa-solid fa-receipt me-1"></i>
                  <span id="cntItemsTxt">0 item(s)</span>
                </div>
              </div>
              <div class="pay-summary-total" id="totalItems">$0</div>
            </div>
            <ul class="pay-summary-list" id="paySummaryList"></ul>
          </div>

          <!-- ============== PASO 1: ELEGIR MÉTODO ============== -->
          <div id="paso1">
            <div class="pay-method-grid <?= $webpay_activo ? '' : 'pay-method-grid-single' ?>">
              <?php if ($webpay_activo): ?>
              <button type="button" class="metodo-card" id="cardWebpay" data-metodo="Webpay">
                <span class="metodo-check"><i class="fa-solid fa-check"></i></span>
                <div class="metodo-icon-big">
                  <i class="fa-regular fa-credit-card"></i>
                </div>
                <span class="metodo-name">Webpay</span>
                <div class="metodo-desc">
                  Paga al instante con tu tarjeta de crédito o débito.
                </div>
                <div class="metodo-tags">
                  <span class="metodo-tag"><i class="fa-solid fa-bolt"></i> Inmediato</span>
                  <span class="metodo-tag"><i class="fa-solid fa-shield-halved"></i> Seguro</span>
                </div>
              </button>
              <?php endif; ?>

              <button type="button" class="metodo-card" id="cardTransf" data-metodo="Transferencia">
                <span class="metodo-check"><i class="fa-solid fa-check"></i></span>
                <div class="metodo-icon-big">
                  <i class="fa-solid fa-building-columns"></i>
                </div>
                <span class="metodo-name">Transferencia</span>
                <div class="metodo-desc">
                  Transfiere desde tu banco y adjunta el comprobante.
                </div>
                <div class="metodo-tags">
                  <span class="metodo-tag"><i class="fa-solid fa-clock"></i> Validación</span>
                  <span class="metodo-tag"><i class="fa-regular fa-file-image"></i> Adjuntar</span>
                </div>
              </button>
            </div>

            <input type="radio" name="metodo_pago" id="mpTransf" value="Transferencia" class="d-none">
            <?php if ($webpay_activo): ?>
            <input type="radio" name="metodo_pago" id="mpWebpay"  value="Webpay" class="d-none">
            <?php endif; ?>
          </div>

          <!-- ============== PASO 2 · WEBPAY ============== -->
          <?php if ($webpay_activo): ?>
          <div id="paso2Webpay" class="pay-step-body d-none">
            <div class="webpay-confirm">
              <div class="wp-icon">
                <i class="fa-regular fa-credit-card"></i>
              </div>
              <h6>Listo para pagar con Webpay</h6>
              <p class="wp-desc">
                Al continuar serás redirigido al sitio seguro de Transbank para completar el pago.
              </p>
              <div class="webpay-brands">
                <span class="wp-brand">VISA</span>
                <span class="wp-brand">MASTERCARD</span>
                <span class="wp-brand">REDCOMPRA</span>
                <span class="wp-brand">DÉBITO</span>
                <span class="wp-brand">CRÉDITO</span>
              </div>
              <div class="webpay-secure">
                <i class="fa-solid fa-lock"></i> Conexión cifrada · Procesado por Transbank
              </div>
            </div>
          </div>
          <?php endif; ?>

          <!-- ============== PASO 2 · TRANSFERENCIA ============== -->
          <div id="paso2Transf" class="pay-step-body d-none">

            <!-- Pasos guiados -->
            <div class="transf-steps">
              <div class="transf-step">
                <div class="transf-step-num">1</div>
                <div class="transf-step-text">
                  Transfiere <strong id="transfMonto">$0</strong> a la cuenta indicada abajo.
                </div>
              </div>
              <div class="transf-step">
                <div class="transf-step-num">2</div>
                <div class="transf-step-text">
                  Adjunta el <strong>comprobante</strong> de la transferencia.
                </div>
              </div>
              <div class="transf-step">
                <div class="transf-step-num">3</div>
                <div class="transf-step-text">
                  Envía el formulario. La escuela <strong>validará</strong> tu pago.
                </div>
              </div>
            </div>

            <!-- Datos bancarios -->
            <div class="pay-block-title">
              <i class="fa-solid fa-building-columns"></i> Datos para transferir
            </div>
            <div class="bank-grid">
              <div class="bank-cell">
                <div class="bank-cell-info">
                  <div class="bank-cell-label">Banco</div>
                  <div class="bank-cell-value"><?= h($config_escuela['banco'] ?: '—') ?></div>
                </div>
                <button type="button" class="bank-cell-copy btnCopiar" data-val="<?= h($config_escuela['banco']) ?>" title="Copiar">
                  <i class="fa-regular fa-copy"></i>
                </button>
              </div>
              <div class="bank-cell">
                <div class="bank-cell-info">
                  <div class="bank-cell-label"><?= h($config_escuela['tipo_cuenta'] ?: 'Cuenta') ?></div>
                  <div class="bank-cell-value"><?= h($config_escuela['numero_cuenta'] ?: '—') ?></div>
                </div>
                <button type="button" class="bank-cell-copy btnCopiar" data-val="<?= h($config_escuela['numero_cuenta']) ?>" title="Copiar">
                  <i class="fa-regular fa-copy"></i>
                </button>
              </div>
              <div class="bank-cell">
                <div class="bank-cell-info">
                  <div class="bank-cell-label">RUT titular</div>
                  <div class="bank-cell-value"><?= h($config_escuela['rut_titular'] ?: '—') ?></div>
                </div>
                <button type="button" class="bank-cell-copy btnCopiar" data-val="<?= h($config_escuela['rut_titular']) ?>" title="Copiar">
                  <i class="fa-regular fa-copy"></i>
                </button>
              </div>
              <div class="bank-cell">
                <div class="bank-cell-info">
                  <div class="bank-cell-label">Correo</div>
                  <div class="bank-cell-value"><?= h($config_escuela['correo_comprobantes'] ?: '—') ?></div>
                </div>
                <button type="button" class="bank-cell-copy btnCopiar" data-val="<?= h($config_escuela['correo_comprobantes']) ?>" title="Copiar">
                  <i class="fa-regular fa-copy"></i>
                </button>
              </div>
            </div>
            <button type="button" class="bank-copy-all" id="btnCopiarTodo">
              <i class="fa-regular fa-clipboard"></i> Copiar todos los datos
            </button>

            <!-- Adjuntar -->
            <div class="pay-block-title mt-3">
              <i class="fa-solid fa-cloud-arrow-up"></i> Adjunta el comprobante
            </div>
            <label id="uploader" for="inputComprobante">
              <div class="up-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
              <div class="up-title">Haz clic o arrastra tu archivo aquí</div>
              <div class="up-sub">PDF, JPG, PNG o WEBP · Máx. 5 MB</div>
              <input type="file" name="comprobante" id="inputComprobante" accept=".pdf,.jpg,.jpeg,.png,.webp" class="d-none">
            </label>

            <div id="filePreview" class="d-none">
              <div class="fp-icon"><i class="fa-regular fa-file-lines"></i></div>
              <div class="fp-info">
                <span id="fpName">—</span>
                <span id="fpSize">—</span>
              </div>
              <button type="button" id="fpRemove" title="Quitar archivo">
                <i class="fa-solid fa-xmark"></i>
              </button>
            </div>
          </div>

          <input type="hidden" name="cuotas_ids" id="cuotas_ids" value="">
          <input type="hidden" name="actividades_ids" id="actividades_ids" value="">
          <input type="hidden" name="alumno_id" value="<?= $alumno_id ?>">
        </div>

        <div class="modal-footer pay-footer">
          <button type="button" class="btn-modal-cancel" id="btnVolver" style="visibility:hidden;">
            <i class="fa-solid fa-arrow-left"></i> Volver
          </button>
          <div class="pay-footer-right">
            <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn-modal-primary" id="btnSiguiente" disabled>
              Continuar <i class="fa-solid fa-arrow-right"></i>
            </button>
            <button type="submit" class="btn-modal-primary d-none" id="btnConfirmarTransf" disabled>
              <i class="fa-regular fa-paper-plane"></i> Enviar comprobante
            </button>
            <?php if ($webpay_activo): ?>
            <button type="button" class="btn-save d-none" id="btnIrWebpay">
              <i class="fa-regular fa-credit-card"></i> Pagar con Webpay
            </button>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- =============================================
     MODAL DE ÉXITO POST-PAGO
     Se dispara automáticamente con ?pago=ok o ?webpay=ok
     ============================================= -->
<div class="modal fade" id="modalExitoPago" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content exito-modal">
      <button type="button" class="exito-close" data-bs-dismiss="modal" aria-label="Cerrar">
        <i class="fa-solid fa-xmark"></i>
      </button>

      <div class="modal-body text-center exito-body">

        <!-- Webpay: pago confirmado -->
        <div id="exitoWebpay" class="d-none">
          <div class="exito-icon-wrap exito-icon-success">
            <div class="exito-ring"></div>
            <div class="exito-icon">
              <i class="fa-solid fa-check"></i>
            </div>
          </div>
          <h5 class="exito-title">¡Pago confirmado!</h5>
          <p class="exito-desc">
            Tu pago con Webpay fue procesado correctamente y tus cuotas ya quedaron al día.
          </p>
          <div class="exito-detail-grid">
            <div class="exito-detail-item">
              <i class="fa-regular fa-credit-card"></i>
              <span>Pagado con Webpay</span>
            </div>
            <div class="exito-detail-item">
              <i class="fa-solid fa-circle-check"></i>
              <span>Acreditado al instante</span>
            </div>
            <div class="exito-detail-item">
              <i class="fa-solid fa-shield-halved"></i>
              <span>Procesado por Transbank</span>
            </div>
          </div>
          <div class="exito-tip">
            <i class="fa-regular fa-envelope"></i>
            Recibirás el comprobante en tu correo registrado.
          </div>
        </div>

        <!-- Transferencia: comprobante enviado -->
        <div id="exitoTransf" class="d-none">
          <div class="exito-icon-wrap exito-icon-pending">
            <div class="exito-ring"></div>
            <div class="exito-icon">
              <i class="fa-solid fa-paper-plane"></i>
            </div>
          </div>
          <h5 class="exito-title">¡Comprobante enviado!</h5>
          <p class="exito-desc">
            Recibimos tu comprobante de transferencia. Ahora será revisado por la escuela
            y se actualizará el estado de tu pago.
          </p>
          <div class="exito-status-card">
            <div class="exito-status-row">
              <span class="exito-status-dot is-done"></span>
              <span class="exito-status-text">
                <strong>Comprobante recibido</strong>
                <small>Subido correctamente</small>
              </span>
            </div>
            <div class="exito-status-row">
              <span class="exito-status-dot is-active"></span>
              <span class="exito-status-text">
                <strong>En revisión</strong>
                <small>La escuela validará el pago</small>
              </span>
            </div>
            <div class="exito-status-row">
              <span class="exito-status-dot"></span>
              <span class="exito-status-text">
                <strong>Pago confirmado</strong>
                <small>Cuotas marcadas como pagadas</small>
              </span>
            </div>
          </div>
          <div class="exito-tip">
            <i class="fa-regular fa-clock"></i>
            Mientras tanto, las cuotas aparecerán como <strong>"Pendiente de Aprobación"</strong>.
          </div>
        </div>

      </div>

      <div class="modal-footer exito-footer">
        <button type="button" class="btn-modal-primary exito-btn-ok" data-bs-dismiss="modal">
          <i class="fa-solid fa-check"></i> Entendido
        </button>
      </div>
    </div>
  </div>
</div>

<!-- MODAL CONTRATO -->
<div class="modal fade" id="modalContrato" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-regular fa-file-lines"></i> Terminos y Condiciones</h5>
      </div>
      <div class="modal-body text-center">
        <i class="fa-regular fa-file-lines mb-2" style="font-size:2.5rem;"></i>
        <h6 class="fw-bold">Terminos y Condiciones</h6>
        <p class="small-muted mb-3">Para continuar, debes revisar y aceptar el contrato.</p>
        <a href="<?= $contrato_file ?>" target="_blank" class="btn-modern mb-3">
          <i class="fa-regular fa-file-pdf"></i> Ver contrato
        </a>
        <div class="confirm-card" id="bloqueContrato">
          <input class="form-check-input" type="checkbox" id="checkContratoModal">
          <label for="checkContratoModal" class="mb-0">Acepto los terminos y condiciones</label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-modal-primary" id="btnAceptarContrato" disabled>
          Aceptar y continuar
        </button>
      </div>
    </div>
  </div>
</div>

<div class="toast-saas d-none" id="copyToast">Copiado al portapapeles</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
/* =====================================================
   ★ FLUJO DE PAGO REDISEÑADO ★
   Mantiene compatibilidad total con el backend original.
   ===================================================== */
(function(){
  // Maps de items para el resumen del modal
  const CUOTAS_MAP = <?= json_encode($cuotas_map_js, JSON_UNESCAPED_UNICODE) ?>;
  const ACTS_MAP   = <?= json_encode($actividades_map_js, JSON_UNESCAPED_UNICODE) ?>;

  const modalPagoEl = document.getElementById('modalPago');
  const modal = modalPagoEl ? new bootstrap.Modal(modalPagoEl) : null;

  const formPago    = document.getElementById('formPago');
  const paso1       = document.getElementById('paso1');
  const paso2Webpay = document.getElementById('paso2Webpay');
  const paso2Transf = document.getElementById('paso2Transf');
  const cardWebpay  = document.getElementById('cardWebpay');
  const cardTransf  = document.getElementById('cardTransf');
  const mpWebpay    = document.getElementById('mpWebpay');
  const mpTransf    = document.getElementById('mpTransf');

  const stepEl1     = document.getElementById('payStep1');
  const stepEl2     = document.getElementById('payStep2');
  const stepLabel2  = document.getElementById('payStep2Label');
  const titleEl     = document.getElementById('payModalTitle');

  const btnSig      = document.getElementById('btnSiguiente');
  const btnVolver   = document.getElementById('btnVolver');
  const btnConfT    = document.getElementById('btnConfirmarTransf');
  const btnWP       = document.getElementById('btnIrWebpay');

  const inputFile   = document.getElementById('inputComprobante');
  const uploader    = document.getElementById('uploader');
  const filePreview = document.getElementById('filePreview');
  const fpName      = document.getElementById('fpName');
  const fpSize      = document.getElementById('fpSize');
  const fpRemove    = document.getElementById('fpRemove');

  const summaryList = document.getElementById('paySummaryList');
  const transfMonto = document.getElementById('transfMonto');
  const cntItemsTxt = document.getElementById('cntItemsTxt');
  const totalItems  = document.getElementById('totalItems');

  const payLoader     = document.getElementById('payLoader');
  const payLoaderText = document.getElementById('payLoaderText');
  const payLoaderSub  = document.getElementById('payLoaderSub');

  let metodoSel = null;
  let totalActual = 0;
  let lockUI = false;

  /* ============ Helpers ============ */
  function fmt(n){ return '$'+(parseInt(n||0,10)).toLocaleString('es-CL'); }

  function setAll(selector, checked) {
    document.querySelectorAll(selector).forEach(el => { if (!el.disabled) el.checked = checked; });
  }
  document.getElementById('chkAllCuotas')?.addEventListener('change', e => setAll('.chkCuota', e.target.checked));
  document.getElementById('chkAllAct')?.addEventListener('change', e => setAll('.chkAct', e.target.checked));

  function collectChecked(sel) {
    const els = Array.from(document.querySelectorAll(sel+':checked'));
    let total = 0, ids = [], seen = new Set();
    els.forEach(chk => {
      if (chk.disabled) return;
      if (seen.has(chk.value)) return;
      seen.add(chk.value);
      total += parseInt(chk.dataset.valor || '0', 10);
      ids.push(chk.value);
    });
    return {count: ids.length, total, ids};
  }

  /* ============ Resumen del modal ============ */
  function renderSummary(items) {
    summaryList.innerHTML = '';
    items.forEach(it => {
      const li = document.createElement('li');
      li.innerHTML = `
        <span class="it-name">
          <span>${escHtml(it.label)}</span>
          <span class="it-sub">${escHtml(it.sub || '')}</span>
        </span>
        <span class="it-val">${fmt(it.valor)}</span>
      `;
      summaryList.appendChild(li);
    });
  }

  function escHtml(s){
    return String(s||'').replace(/[&<>"']/g, m => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[m]));
  }

  /* ============ Apertura del modal ============ */
  function openModal() {
    const cuotas = collectChecked('.chkCuota');
    const acts   = collectChecked('.chkAct');
    const count  = cuotas.count + acts.count;
    if (!count) {
      // Mensaje no agresivo
      flashSelectionWarning();
      return;
    }

    const items = [];
    cuotas.ids.forEach(id => {
      const m = CUOTAS_MAP[id];
      if (m) items.push(m);
    });
    acts.ids.forEach(id => {
      const m = ACTS_MAP[id];
      if (m) items.push(m);
    });

    totalActual = cuotas.total + acts.total;
    cntItemsTxt.textContent = count + ' ' + (count === 1 ? 'item' : 'items');
    totalItems.textContent  = fmt(totalActual);
    transfMonto.textContent = fmt(totalActual);
    document.getElementById('cuotas_ids').value      = cuotas.ids.join(',');
    document.getElementById('actividades_ids').value = acts.ids.join(',');

    renderSummary(items);
    resetModal();
    modal?.show();
  }

  function flashSelectionWarning() {
    // Subraya el bloque de cuotas brevemente sin alert intrusivo
    const target = document.getElementById('apo-cuotas');
    if (!target) { alert('Selecciona al menos una cuota o actividad.'); return; }
    target.style.transition = 'box-shadow .25s ease';
    target.style.boxShadow = '0 0 0 3px rgba(244,63,94,.32), 0 10px 26px rgba(16,42,67,.10)';
    setTimeout(() => { target.style.boxShadow = ''; }, 1200);
    showToast('Selecciona al menos un item para pagar', 'warn');
  }

  function openModalSingle(id, total, tipo) {
    document.querySelectorAll('.chkAct,.chkCuota').forEach(x => x.checked = false);

    const items = [];
    if (tipo === 'actividad') {
      const m = ACTS_MAP[id];
      if (m) items.push(m);
      document.getElementById('actividades_ids').value = id;
      document.getElementById('cuotas_ids').value = '';
    } else {
      const m = CUOTAS_MAP[id];
      if (m) items.push(m);
      document.getElementById('cuotas_ids').value = id;
      document.getElementById('actividades_ids').value = '';
    }

    totalActual = total;
    cntItemsTxt.textContent = '1 item';
    totalItems.textContent  = fmt(total);
    transfMonto.textContent = fmt(total);
    renderSummary(items);
    resetModal();
    modal?.show();
  }

  document.getElementById('btnPagar')?.addEventListener('click', openModal);
  document.getElementById('btnPagarAct')?.addEventListener('click', openModal);
  document.querySelectorAll('.btnPayOne').forEach(btn => {
    btn.addEventListener('click', () => openModalSingle(btn.dataset.id, parseInt(btn.dataset.valor||'0',10), btn.dataset.tipo||'actividad'));
  });

  /* ============ Reset / pasos ============ */
  function resetModal() {
    metodoSel = null;
    [cardWebpay, cardTransf].forEach(c => c?.classList.remove('is-selected'));
    if (mpWebpay) mpWebpay.checked = false;
    if (mpTransf) mpTransf.checked = false;
    if (inputFile) inputFile.value = '';
    filePreview?.classList.add('d-none');
    titleEl.textContent = 'Realizar pago';
    stepLabel2.textContent = 'Confirmar';
    hideLoader();
    mostrarPaso(1);
    btnSig.disabled = true;
    btnConfT.disabled = true;
    setLockUI(false);
  }

  function selMetodo(m) {
    if (lockUI) return;
    metodoSel = m;
    cardWebpay?.classList.toggle('is-selected', m === 'Webpay');
    cardTransf?.classList.toggle('is-selected', m === 'Transferencia');
    if (mpWebpay) mpWebpay.checked = (m === 'Webpay');
    mpTransf.checked = (m === 'Transferencia');
    btnSig.disabled = false;

    // Pequeño feedback visual del botón continuar
    btnSig.classList.add('pulse-once');
    setTimeout(() => btnSig.classList.remove('pulse-once'), 600);

    // Doble click avanza directo (UX power-user)
  }

  cardWebpay?.addEventListener('click', () => selMetodo('Webpay'));
  cardTransf?.addEventListener('click', () => selMetodo('Transferencia'));

  // Navegación con teclado entre cards
  [cardWebpay, cardTransf].forEach(c => {
    c?.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        c.click();
      }
    });
    if (c) c.tabIndex = 0;
  });

  function mostrarPaso(n) {
    if (n === 1) {
      paso1.classList.remove('d-none');
      paso2Webpay?.classList.add('d-none');
      paso2Transf.classList.add('d-none');

      stepEl1.classList.add('is-active');
      stepEl1.classList.remove('is-done');
      stepEl2.classList.remove('is-active', 'is-done');

      btnVolver.style.visibility = 'hidden';
      btnSig.classList.remove('d-none');
      btnConfT.classList.add('d-none');
      btnWP?.classList.add('d-none');
      titleEl.textContent = 'Realizar pago';
    } else {
      paso1.classList.add('d-none');

      stepEl1.classList.remove('is-active');
      stepEl1.classList.add('is-done');
      stepEl2.classList.add('is-active');

      btnVolver.style.visibility = 'visible';
      btnSig.classList.add('d-none');

      if (metodoSel === 'Webpay') {
        paso2Webpay?.classList.remove('d-none');
        paso2Transf.classList.add('d-none');
        btnWP?.classList.remove('d-none');
        btnConfT.classList.add('d-none');
        stepLabel2.textContent = 'Confirmar Webpay';
        titleEl.textContent = 'Confirmar pago con Webpay';
      } else {
        paso2Transf.classList.remove('d-none');
        paso2Webpay?.classList.add('d-none');
        btnConfT.classList.remove('d-none');
        btnWP?.classList.add('d-none');
        stepLabel2.textContent = 'Adjuntar comprobante';
        titleEl.textContent = 'Pago por transferencia';
        validarComp();
      }
    }
  }

  btnSig?.addEventListener('click', () => { if (metodoSel && !lockUI) mostrarPaso(2); });
  btnVolver?.addEventListener('click', () => { if (!lockUI) mostrarPaso(1); });

  /* ============ Validaciones de comprobante ============ */
  function validarComp() {
    const ok = !!(inputFile.files && inputFile.files.length > 0);
    btnConfT.disabled = !ok;
  }

  function humanSize(b) {
    if (b<1024) return b+' B';
    if (b<1048576) return (b/1024).toFixed(1)+' KB';
    return (b/1048576).toFixed(2)+' MB';
  }

  inputFile?.addEventListener('change', function(){
    if (this.files && this.files.length > 0) {
      const f = this.files[0];

      // Validar tamaño
      if (f.size > 5*1024*1024) {
        showToast('El archivo supera los 5 MB', 'error');
        this.value = '';
        filePreview.classList.add('d-none');
        validarComp();
        return;
      }

      // Validar extensión
      const okExt = /\.(pdf|jpg|jpeg|png|webp)$/i.test(f.name);
      if (!okExt) {
        showToast('Formato no permitido (usa PDF, JPG, PNG o WEBP)', 'error');
        this.value = '';
        filePreview.classList.add('d-none');
        validarComp();
        return;
      }

      fpName.textContent = f.name;
      fpSize.textContent = humanSize(f.size) + ' · ' + (f.type || 'archivo');
      filePreview.classList.remove('d-none');
      showToast('Comprobante listo para enviar', 'ok');
    } else {
      filePreview.classList.add('d-none');
    }
    validarComp();
  });

  fpRemove?.addEventListener('click', e => {
    e.preventDefault(); e.stopPropagation();
    inputFile.value = '';
    filePreview.classList.add('d-none');
    validarComp();
  });

  // Drag & drop
  ['dragenter','dragover'].forEach(ev => uploader?.addEventListener(ev, e => {
    e.preventDefault(); uploader.classList.add('is-drag');
  }));
  ['dragleave','drop'].forEach(ev => uploader?.addEventListener(ev, e => {
    e.preventDefault(); uploader.classList.remove('is-drag');
  }));
  uploader?.addEventListener('drop', e => {
    if (e.dataTransfer.files.length > 0) {
      inputFile.files = e.dataTransfer.files;
      inputFile.dispatchEvent(new Event('change'));
    }
  });

  /* ============ Webpay submit ============ */
  btnWP?.addEventListener('click', () => {
    if (lockUI) return;
    setLockUI(true);
    showLoader('Redirigiendo a Webpay...', 'No cierres esta ventana');

    const cuotas = document.getElementById('cuotas_ids').value;
    const acts   = document.getElementById('actividades_ids').value;
    const form   = document.createElement('form');
    form.method = 'POST'; form.action = '/webpay/crear_transaccion.php';

    const ai = document.createElement('input');
    ai.type='hidden'; ai.name='alumno_id'; ai.value=<?= json_encode((int)$alumno_id) ?>;
    form.appendChild(ai);

    cuotas.split(',').forEach(id => {
      if (id) { const i=document.createElement('input'); i.type='hidden'; i.name='cuotas[]'; i.value=id; form.appendChild(i); }
    });
    acts.split(',').forEach(id => {
      if (id) { const i=document.createElement('input'); i.type='hidden'; i.name='actividades[]'; i.value=id; form.appendChild(i); }
    });

    document.body.appendChild(form);
    btnWP.disabled = true;
    btnWP.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Redirigiendo...';
    form.submit();
  });

  /* ============ Transferencia submit ============ */
  formPago?.addEventListener('submit', function(e){
    // Solo intervenir si estamos enviando el flujo de transferencia
    const enviandoTransf = !btnConfT.classList.contains('d-none');
    if (!enviandoTransf) return; // dejar que Webpay siga su flujo (no debería llegar aquí)

    if (!inputFile.files || inputFile.files.length === 0) {
      e.preventDefault();
      showToast('Adjunta el comprobante antes de enviar', 'error');
      return;
    }

    if (lockUI) { e.preventDefault(); return; }

    setLockUI(true);
    showLoader('Enviando comprobante...', 'Estamos subiendo tu archivo');
    btnConfT.disabled = true;
    btnConfT.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Enviando...';
    // dejamos que el form continúe con el submit normal
  });

  /* ============ Loader / lock ============ */
  function showLoader(text, sub) {
    if (text) payLoaderText.textContent = text;
    if (sub)  payLoaderSub.textContent = sub;
    payLoader.classList.add('is-visible');
  }
  function hideLoader() { payLoader.classList.remove('is-visible'); }

  function setLockUI(locked) {
    lockUI = locked;
    [cardWebpay, cardTransf, btnSig, btnVolver, btnConfT, btnWP].forEach(el => {
      if (!el) return;
      if (locked) el.setAttribute('aria-disabled','true');
      else el.removeAttribute('aria-disabled');
    });
  }

  // Al cerrar el modal, asegurarse de no dejar nada bloqueado
  modalPagoEl?.addEventListener('hidden.bs.modal', () => {
    hideLoader();
    setLockUI(false);
    btnConfT.innerHTML = '<i class="fa-regular fa-paper-plane"></i> Enviar comprobante';
    if (btnWP) if (btnWP) btnWP.innerHTML = '<i class="fa-regular fa-credit-card"></i> Pagar con Webpay';
    if (btnWP) if (btnWP) btnWP.disabled = false;
  });

  /* ============ Copy helpers ============ */
  const toast = document.getElementById('copyToast');
  function showToast(msg, type) {
    if (!toast) return;
    toast.textContent = msg;
    toast.style.background = (type === 'error')
      ? 'linear-gradient(135deg,#be123c,#f43f5e)'
      : (type === 'warn')
        ? 'linear-gradient(135deg,#b45309,#f59e0b)'
        : 'linear-gradient(135deg,#0f766e,#10b981)';
    toast.classList.remove('d-none');
    clearTimeout(toast._t);
    toast._t = setTimeout(()=>toast.classList.add('d-none'), 2000);
  }

  function copiar(texto, btn) {
    if (!texto || texto === '—') return;
    const onOk = () => {
      showToast('Copiado: ' + texto.slice(0, 28));
      if (btn) {
        btn.classList.add('is-copied');
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-check"></i>';
        setTimeout(() => {
          btn.classList.remove('is-copied');
          btn.innerHTML = orig;
        }, 1200);
      }
    };

    if (navigator.clipboard?.writeText) {
      navigator.clipboard.writeText(texto).then(onOk).catch(() => fallback());
    } else {
      fallback();
    }

    function fallback() {
      const ta = document.createElement('textarea');
      ta.value = texto;
      ta.style.position='fixed'; ta.style.opacity='0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); onOk(); } catch(e){}
      document.body.removeChild(ta);
    }
  }

  document.querySelectorAll('.btnCopiar').forEach(btn => {
    btn.addEventListener('click', e => {
      e.preventDefault();
      copiar(btn.dataset.val, btn);
    });
  });

  document.getElementById('btnCopiarTodo')?.addEventListener('click', (e) => {
    const b = <?= json_encode($config_escuela['banco']??'') ?>;
    const t = <?= json_encode($config_escuela['tipo_cuenta']??'') ?>;
    const n = <?= json_encode($config_escuela['numero_cuenta']??'') ?>;
    const r = <?= json_encode($config_escuela['rut_titular']??'') ?>;
    const m = <?= json_encode($config_escuela['correo_comprobantes']??'') ?>;
    copiar(`Banco: ${b}\n${t||'Cuenta'}: ${n}\nRUT: ${r}\nCorreo: ${m}`, e.currentTarget);
  });

  /* ============ Bloqueo por contrato ============ */
  const check = document.getElementById('checkContrato');
  const btnP1 = document.getElementById('btnPagar');
  const btnP2 = document.getElementById('btnPagarAct');
  function togglePagos() {
    const ok = check?.checked;
    if (btnP1) btnP1.disabled = !ok;
    if (btnP2) btnP2.disabled = !ok;
    document.querySelectorAll('.btnPayOne').forEach(b => { if (!ok) b.disabled = true; });
  }
  if (check) { check.addEventListener('change', togglePagos); togglePagos(); }
})();

/* =====================================================
   Bloqueo del modal de contrato (sin cambios funcionales)
   ===================================================== */
(function(){
  const yaAceptado = <?= (int)$contrato_aceptado ?>;
  const modalEl = document.getElementById('modalContrato');
  const modal   = modalEl ? new bootstrap.Modal(modalEl) : null;
  const checkM  = document.getElementById('checkContratoModal');
  const btnAcep = document.getElementById('btnAceptarContrato');
  const checkP  = document.getElementById('checkContrato');
  const btnP1   = document.getElementById('btnPagar');
  const btnP2   = document.getElementById('btnPagarAct');

  function bloquear(b) {
    if (btnP1) btnP1.disabled = b;
    if (btnP2) btnP2.disabled = b;
    document.querySelectorAll('.btnPayOne').forEach(btn => { if (b) btn.disabled=true; });
  }

  if (!yaAceptado) { bloquear(true); modal?.show(); }

  checkM?.addEventListener('change', () => { btnAcep.disabled = !checkM.checked; });

  btnAcep?.addEventListener('click', () => {
    fetch('guardar_contrato.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:'aceptado=1'
    })
    .then(r=>r.json())
    .then(data => {
      bloquear(false);
      if (checkP) checkP.checked = true;
      const bl = document.getElementById('bloqueContrato');
      if (bl) { bl.classList.add('confirmed'); }
      modal.hide();
    });
  });
})();
</script>

<script>
/* =====================================================
   MODAL DE ÉXITO POST-PAGO
   Se dispara automáticamente si llegamos con
   ?webpay=ok o ?pago=ok. Limpia la URL para evitar
   que reaparezca al recargar.
   ===================================================== */
(function(){
  const tipo = <?= json_encode($pago_exito_tipo ?? '') ?>;
  if (!tipo) return;

  const modalEl = document.getElementById('modalExitoPago');
  if (!modalEl) return;

  const bloqueWebpay = document.getElementById('exitoWebpay');
  const bloqueTransf = document.getElementById('exitoTransf');

  if (tipo === 'webpay') {
    bloqueWebpay?.classList.remove('d-none');
    bloqueTransf?.classList.add('d-none');
  } else if (tipo === 'transferencia') {
    bloqueTransf?.classList.remove('d-none');
    bloqueWebpay?.classList.add('d-none');
  } else {
    return;
  }

  // Limpiar parámetros de la URL para evitar que el modal
  // vuelva a salir al recargar la página o auto-refresh.
  try {
    const url = new URL(window.location.href);
    url.searchParams.delete('pago');
    url.searchParams.delete('webpay');
    url.searchParams.delete('wp');
    window.history.replaceState({}, document.title, url.pathname + (url.search || '') + url.hash);
  } catch(e) {}

  // Pequeño delay para que primero se vea el panel y luego el modal
  setTimeout(() => {
    const modal = new bootstrap.Modal(modalEl, { backdrop: 'static', keyboard: true });
    modal.show();
  }, 350);
})();
</script>

<script>
/* Auto refresh, evitar si modal de pago está abierto o el usuario está escribiendo */
setInterval(() => {
  if (document.querySelector('.modal.show')) return;
  if (document.activeElement && ['INPUT','TEXTAREA','SELECT'].includes(document.activeElement.tagName)) return;
  location.reload();
}, 30000);
</script>


<script>
/* =====================================================
   Actualización de los botones "Pagar seleccionada(s)"
   del header de cada sección (Cuotas / Actividades).
   Muestra contador y total cuando hay selección.
   ===================================================== */
(function(){
  const btnPagarCuotas = document.getElementById('btnPagar');
  const btnPagarActs   = document.getElementById('btnPagarAct');

  // Guardar el HTML original para restaurar cuando no hay selección
  const ORIG_CUOTAS = btnPagarCuotas ? btnPagarCuotas.innerHTML : '';
  const ORIG_ACTS   = btnPagarActs   ? btnPagarActs.innerHTML   : '';

  function fmt(n){ return '$'+(parseInt(n || 0, 10)).toLocaleString('es-CL'); }

  function sumar(selector){
    const checks = Array.from(document.querySelectorAll(selector));
    let total = 0;
    const seen = new Set();
    checks.forEach(chk => {
      if (chk.disabled) return;
      if (seen.has(chk.value)) return;
      seen.add(chk.value);
      total += parseInt(chk.dataset.valor || '0', 10);
    });
    return { count: seen.size, total };
  }

  function refresh(){
    const cuotas = sumar('.chkCuota:checked');
    const acts   = sumar('.chkAct:checked');

    // Botón de Cuotas
    if (btnPagarCuotas) {
      if (cuotas.count > 0) {
        btnPagarCuotas.classList.add('btn-has-selection');
        btnPagarCuotas.innerHTML = `
          <span class="sel-badge">${cuotas.count}</span>
          <span class="sel-label">Pagar</span>
          <span class="sel-total">${fmt(cuotas.total)}</span>
        `;
      } else {
        btnPagarCuotas.classList.remove('btn-has-selection');
        btnPagarCuotas.innerHTML = ORIG_CUOTAS;
      }
    }

    // Botón de Actividades
    if (btnPagarActs) {
      if (acts.count > 0) {
        btnPagarActs.classList.add('btn-has-selection');
        btnPagarActs.innerHTML = `
          <span class="sel-badge">${acts.count}</span>
          <span class="sel-label">Pagar</span>
          <span class="sel-total">${fmt(acts.total)}</span>
        `;
      } else {
        btnPagarActs.classList.remove('btn-has-selection');
        btnPagarActs.innerHTML = ORIG_ACTS;
      }
    }
  }

  document.addEventListener('change', function(e){
    if (e.target && (
      e.target.matches('.chkCuota') ||
      e.target.matches('.chkAct') ||
      e.target.matches('#chkAllCuotas') ||
      e.target.matches('#chkAllAct')
    )) {
      setTimeout(refresh, 0);
    }
  });

  refresh();
})();
</script>

</body>
</html>