
<?php
include("../includes/db.php");
session_start();

if (!isset($_SESSION['usuario_admin'])) {
    header("Location: login.php");
    exit;
}

if (isset($_GET['id'])) {
    $transferencia_id = intval($_GET['id']);
    $fecha_rechazo = date("Y-m-d H:i:s");

    // Obtener datos antes de actualizar
    $datos = $conn->query("
        SELECT pt.cuota_id, c.alumno_id, a.rut
        FROM pagos_transferencia pt
        JOIN cuotas c ON pt.cuota_id = c.id
        JOIN alumnos a ON c.alumno_id = a.id
        WHERE pt.id = $transferencia_id
    ");

    if ($datos && $datos->num_rows > 0) {
        $row = $datos->fetch_assoc();
        $cuota_id = $row['cuota_id'];
        $rut = $row['rut'];

        // Marcar como rechazado
        $conn->query("
            UPDATE pagos_transferencia
            SET estado = 'rechazado', fecha_aprobacion = '$fecha_rechazo'
            WHERE id = $transferencia_id
        ");

        // Redirigir con anclaje
        header("Location: ../admin/cobranzas.php?rut=$rut#cuota$cuota_id");
        exit;
    } else {
        echo "Transferencia no encontrada.";
    }
} else {
    echo "ID inválido.";
}
?>
