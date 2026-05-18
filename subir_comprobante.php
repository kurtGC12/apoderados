<?php
session_start();
if (!isset($_SESSION['alumno_id'])) { header("Location: login.php"); exit(); }
include("../includes/db.php");

$alumno_id = $_SESSION['alumno_id'];
$cuota_id = $_POST['cuota_id'];
$file = $_FILES['voucher'];

$alumno = $conn->query("SELECT escuela FROM alumnos WHERE id = $alumno_id")->fetch_assoc();
$correo = ($alumno['escuela'] == 'Quilpué') ? 'quilpue@mati14.cl' : 'nogales@mati14.cl';

if ($file['error'] === UPLOAD_ERR_OK) {
    $nombre_archivo = basename($file['name']);
    $ruta_destino = "../vouchers/" . time() . "_" . $nombre_archivo;
    move_uploaded_file($file['tmp_name'], $ruta_destino);

    $stmt = $conn->prepare("INSERT INTO pagos_transferencia (alumno_id, cuota_id, archivo, fecha_envio) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param("iis", $alumno_id, $cuota_id, $ruta_destino);
    $stmt->execute();

    $asunto = "Nuevo comprobante de transferencia";
    $mensaje = "El apoderado del alumno ID $alumno_id ha enviado un comprobante para la cuota ID $cuota_id.";
    $headers = "From: sistema@escuela.cl\r\nContent-Type: text/plain; charset=utf-8\r\n";
    mail($correo, $asunto, $mensaje, $headers);

    echo "<script>alert('Comprobante enviado correctamente');window.location='panel_apoderados.php';</script>";
} else {
    echo "<script>alert('Error al subir el archivo');window.location='panel_apoderados.php';</script>";
}
?>