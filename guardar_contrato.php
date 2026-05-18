<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['alumno_rut'])) {
  echo json_encode(['ok' => false]);
  exit;
}

$rut = $_SESSION['alumno_rut'];

$stmt = $conn->prepare("
  UPDATE alumnos 
  SET contrato_aceptado = 1,
      contrato_fecha = NOW()
  WHERE rut = ?
");
$stmt->bind_param("s", $rut);
$stmt->execute();

$q = $conn->prepare("SELECT contrato_fecha FROM alumnos WHERE rut = ? LIMIT 1");
$q->bind_param("s", $rut);
$q->execute();
$q->bind_result($contrato_fecha);
$q->fetch();
$q->close();

echo json_encode([
  'ok' => true,
  'fecha' => $contrato_fecha ? date('d-m-Y H:i', strtotime($contrato_fecha)) : ''
]);