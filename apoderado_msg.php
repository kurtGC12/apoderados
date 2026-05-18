<?php
header('Content-Type: text/html; charset=UTF-8');
$map = [
  'transferencia_enviada' => '¡Gracias! Hemos recibido tu comprobante de transferencia. Te avisaremos cuando sea revisado.',
  'transferencia_error'   => 'Ocurrió un problema al enviar el comprobante. Intenta nuevamente o contáctanos.',
  'rechazo'               => 'Tu comprobante fue rechazado. Favor acercarse presencialmente con el encargado(a) de los cobros.'
];
$key = isset($_GET['type']) ? $_GET['type'] : '';
$msg = isset($map[$key]) ? $map[$key] : 'Información no disponible.';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Información</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="container py-5">
  <div class="alert alert-info"><?php echo htmlspecialchars($msg); ?></div>
  <a class="btn btn-primary" href="/apoderados/login.php">Ir al inicio de sesión</a>
</body>
</html>
