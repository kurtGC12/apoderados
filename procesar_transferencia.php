<?php
/**
 * procesar_transferencia.php (v4)
 * Inserta UNA FILA por cuota en pagos_transferencia con estado 'pendiente'
 * (mantiene la lógica original de cobranzas.php), adjunta el voucher y
 * notifica por correo con detalle N°/Mes/Monto. Luego vuelve al panel.
 */

header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');
session_start();
require_once __DIR__ . '/../includes/db.php';
if (function_exists('mysqli_set_charset')) { mysqli_set_charset($conn, 'utf8mb4'); }
$conn->query("SET NAMES 'utf8mb4'");
$conn->query("SET CHARACTER SET utf8mb4");
$conn->query("SET SESSION collation_connection = 'utf8mb4_general_ci'");

function nombreMesES($mesNumero) {
    $meses = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
    return $meses[intval($mesNumero)] ?? '';
}

// Reunir IDs desde cualquier nombre
function recoger_ids_post(): array {
    $candidatos = ['ids','cuotas','cuota_id','id_cuota','seleccionadas','cuotas_ids'];
    $ids = [];
    foreach ($candidatos as $n) {
        if (isset($_POST[$n])) {
            $v = $_POST[$n];
            if (is_array($v)) { $ids = array_merge($ids, $v); }
            else {
                $v = trim((string)$v);
                if ($v !== '') {
                    if ($v[0] === '[') { $tmp = json_decode($v, true); if (is_array($tmp)) $ids = array_merge($ids, $tmp); }
                    else { $ids = array_merge($ids, preg_split('/\s*,\s*/', $v, -1, PREG_SPLIT_NO_EMPTY)); }
                }
            }
        }
    }
    if (empty($ids) && !empty($_POST['ids_json'])) {
        $tmp = json_decode($_POST['ids_json'], true);
        if (is_array($tmp)) $ids = array_merge($ids, $tmp);
    }
    $ids = array_values(array_filter(array_map('strval', $ids), fn($x)=>$x!=='' && $x!=='0'));
    return array_unique($ids);
}

$rut        = isset($_POST['rut']) ? trim((string)$_POST['rut']) : '';
$alumno_id  = isset($_POST['alumno_id']) ? (int)$_POST['alumno_id'] : null;
$total_post = isset($_POST['total_monto']) ? (float)$_POST['total_monto'] : 0.0;
$origen     = isset($_POST['origen']) ? trim((string)$_POST['origen']) : 'panel_apoderado';

$ids = recoger_ids_post();
if (empty($ids)) { echo '❌ No se seleccionaron cuotas.<br><br><a href="panel_apoderado.php">Volver</a>'; exit; }

// ====== Guardar voucher ======
$uploadDirRel = '../uploads/comprobantes/';
$uploadDirAbs = realpath(__DIR__ . '/../') . '/uploads/comprobantes/';
if (!is_dir($uploadDirAbs)) { @mkdir($uploadDirAbs, 0775, true); }
$voucherGuardado = null;
if (!empty($_FILES['voucher']) && is_uploaded_file($_FILES['voucher']['tmp_name'])) {
    $ext = strtolower(pathinfo($_FILES['voucher']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf','jpg','jpeg','png'])) { $ext = 'pdf'; }
    $primerId  = rawurlencode((string)$ids[0]);
    $rutSafe   = preg_replace('/[^0-9kK\.-]+/', '', $rut) ?: 'sin_rut';
    $filename  = 'voucher_' . $rutSafe . '_' . $primerId . '_' . date('Ymd_His') . '.' . $ext;
    $destAbs   = $uploadDirAbs . $filename;
    if (move_uploaded_file($_FILES['voucher']['tmp_name'], $destAbs)) {
        $voucherGuardado = $uploadDirRel . $filename;
    }
}

// ====== Metadata de cuotas para correo y montos ======
function detectar_columna($conn, $tabla, $candidatas) {
    foreach ($candidatas as $c) {
        $res = $conn->query("SHOW COLUMNS FROM `$tabla` LIKE '$c'");
        if ($res && $res->num_rows > 0) return $c;
    }
    return null;
}

// Detectar columnas en cuotas
$pk  = detectar_columna($conn, 'cuotas', ['id','cuota_id','id_cuota']) ?? 'id';
$mco = detectar_columna($conn, 'cuotas', ['valor','monto','importe']) ?? 'valor';
$has_numero = detectar_columna($conn, 'cuotas', ['numero_cuota']);
$has_vto    = detectar_columna($conn, 'cuotas', ['fecha_vencimiento']);
$has_mes    = detectar_columna($conn, 'cuotas', ['mes']);

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types = str_repeat('s', count($ids));
$cols  = "`$pk` as idcuota, `$mco` as monto";
if ($has_numero) $cols .= ", `numero_cuota`";
if ($has_vto)    $cols .= ", `fecha_vencimiento`";
if ($has_mes)    $cols .= ", `mes`";

$detalles = []; $totalLocal = 0.0;
$stmt = $conn->prepare("SELECT $cols FROM `cuotas` WHERE `$pk` IN ($placeholders)");
if ($stmt) {
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $numero = $has_numero ? (string)($r['numero_cuota'] ?? '') : '';
        $mesTxt = '';
        if ($has_vto && !empty($r['fecha_vencimiento'])) {
            $ts = strtotime($r['fecha_vencimiento']); if ($ts) $mesTxt = nombreMesES((int)date('n',$ts));
        } elseif ($has_mes && $r['mes'] !== null && $r['mes'] !== '') {
            $mesTxt = ctype_digit((string)$r['mes']) ? nombreMesES((int)$r['mes']) : (string)$r['mes'];
        } elseif ($numero !== '' && ctype_digit($numero)) {
            $n=(int)$numero; if ($n>=1 && $n<=12) $mesTxt = nombreMesES($n);
        }
        $monto = (float)$r['monto'];
        $totalLocal += $monto;
        $detalles[] = ['id'=>(string)$r['idcuota'], 'numero'=>$numero, 'mes'=>$mesTxt, 'monto'=>$monto];
    }
    $stmt->close();
}

$total = $total_post > 0 ? $total_post : $totalLocal;

// ====== Insertar UNA fila por cuota en pagos_transferencia ======
if ($conn->query("SHOW TABLES LIKE 'pagos_transferencia'")->num_rows > 0) {
    // Detectar columnas disponibles en pagos_transferencia
    $col_archivo = detectar_columna($conn, 'pagos_transferencia', ['archivo','voucher','comprobante']);
    $col_estado  = detectar_columna($conn, 'pagos_transferencia', ['estado']);
    $col_fecha   = detectar_columna($conn, 'pagos_transferencia', ['fecha_envio','fecha_hora','created_at','fecha']);
    // Armar SQL dinámico
    $cols = ['alumno_id','cuota_id'];
    $ph   = ['?','?'];
    $typesIns = 'ii';
    if ($col_archivo) { $cols[] = $col_archivo; $ph[]='?'; $typesIns.='s'; }
    if ($col_estado)  { $cols[] = $col_estado;  $ph[]='?'; $typesIns.='s'; }
    if ($col_fecha)   { $cols[] = $col_fecha;   $ph[]='?'; $typesIns.='s'; }
    $sqlIns = "INSERT INTO pagos_transferencia (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")";

    $stmtIns = $conn->prepare($sqlIns);
    if ($stmtIns) {
        foreach ($ids as $idc) {
            $params = [$alumno_id, (int)$idc];
            if ($col_archivo) $params[] = $voucherGuardado;
            if ($col_estado)  $params[] = 'pendiente'; // *** clave para cobranzas.php ***
            if ($col_fecha)   $params[] = date('Y-m-d H:i:s');
            $stmtIns->bind_param($typesIns, ...$params);
            $stmtIns->execute();
        }
        $stmtIns->close();
    }
} else {
    // Fallback histórico
    if ($conn->query("SHOW TABLES LIKE 'pagos'")->num_rows > 0) {
        $stmt2 = $conn->prepare("INSERT INTO pagos (cuota_id, alumno_id, metodo, monto, estado, comprobante, fecha_hora, origen) VALUES (?, ?, 'transferencia', ?, 'pendiente', ?, ?, ?)");
        if ($stmt2) {
            foreach ($ids as $idc) {
                $montoCuota = 0.0;
                $stmt2->bind_param('iidsss', $idc, $alumno_id, $montoCuota, $voucherGuardado, date('Y-m-d H:i:s'), $origen);
                $stmt2->execute();
            }
            $stmt2->close();
        }
    }
}

// ====== Correo a Cobranzas con detalle ======
$correo_cobranzas = 'c.gonzalez@mati14.cl'; // ajustar
$subject = 'Nueva transferencia pendiente de aprobación';

$rowsHtml = '';
foreach ($detalles as $d) {
    $numero = htmlspecialchars((string)$d['numero'], ENT_QUOTES, 'UTF-8');
    $mes    = htmlspecialchars((string)$d['mes'], ENT_QUOTES, 'UTF-8');
    $montoF = '$' . number_format((float)$d['monto'], 0, ',', '.') . ' CLP';
    $rowsHtml .= '<tr><td style="padding:6px 10px;border:1px solid #ddd;">#'.$numero.'</td><td style="padding:6px 10px;border:1px solid #ddd;">'.$mes.'</td><td style="padding:6px 10px;border:1px solid #ddd;">'.$montoF.'</td></tr>';
}
$tablaHtml = '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #ddd;"><thead><tr><th style="padding:6px 10px;border:1px solid #ddd;background:#f7f7f7;">Cuota</th><th style="padding:6px 10px;border:1px solid #ddd;background:#f7f7f7;">Mes</th><th style="padding:6px 10px;border:1px solid #ddd;background:#f7f7f7;">Monto</th></tr></thead><tbody>'.$rowsHtml.'</tbody></table>';

$body  = '<html><body style="font-family:Arial,Helvetica,sans-serif">';
$body .= '<h3>Nueva transferencia enviada desde el Panel de Apoderado</h3>';
$body .= '<p><strong>RUT:</strong> ' . htmlspecialchars($rut, ENT_QUOTES, 'UTF-8') . '</p>';
$body .= '<p><strong>Alumno ID:</strong> ' . htmlspecialchars((string)$alumno_id, ENT_QUOTES, 'UTF-8') . '</p>';
$body .= $tablaHtml;
$body .= '<p><strong>Total:</strong> $' . number_format((float)$total, 0, ',', '.') . ' CLP</p>';
if ($voucherGuardado) { $body .= '<p><strong>Comprobante:</strong> ' . htmlspecialchars($voucherGuardado, ENT_QUOTES, 'UTF-8') . '</p>'; }
$body .= '<p>Estado inicial: <strong>Pendiente</strong></p>';
$body .= '<hr><p>Accede a Cobranza para Aprobar/Rechazar.</p>';
$body .= '</body></html>';

$correo_enviado = false;
if (function_exists('enviarCorreo')) { $correo_enviado = @enviarCorreo($correo_cobranzas, $subject, $body); }
elseif (function_exists('sendMail')) { $correo_enviado = @sendMail($correo_cobranzas, $subject, $body); }
else {
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Notificaciones <no-reply@escuelafutbol.cl>\r\n";
    $correo_enviado = @mail($correo_cobranzas, $subject, $body, $headers);
}

// ====== Regresar al panel del apoderado ======
$qs = ['ok'=>1, 'pendienteIds'=>implode(',', array_map('strval',$ids))];
$anchor = '#tabla-cuotas';
$destino = 'panel_apoderado.php';
$sep = (strpos($destino, '?') === false) ? '?' : '&';
header('Location: ' . $destino . $sep . http_build_query($qs) . $anchor);
exit;
