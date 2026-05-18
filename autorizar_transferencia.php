<?php
include("includes/db.php");

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $cuota_id = intval($_POST["cuota_id"]);
    $accion = $_POST["accion"];

    // Obtener el RUT del alumno a partir de la cuota
    $stmt = $conn->prepare("SELECT a.rut FROM cuotas c JOIN alumnos a ON c.alumno_id = a.id WHERE c.id = ?");
    $stmt->bind_param("i", $cuota_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $rut = '';
    if ($row = $result->fetch_assoc()) {
        $rut = $row['rut'];
    }
    $stmt->close();

    if ($accion === "aprobar") {
        $conn->query("UPDATE pagos_transferencia SET estado = 'aprobada' WHERE cuota_id = $cuota_id");
        $conn->query("UPDATE cuotas SET estado = 'pagada' WHERE id = $cuota_id");
    } elseif ($accion === "rechazar") {
        $conn->query("DELETE FROM pagos_transferencia WHERE cuota_id = $cuota_id AND estado = 'pendiente'");
    }

    // Redireccionar con filtro por RUT y anclaje a la cuota
    header("Location: cobranzas.php?rut=" . urlencode($rut) . "#cuota$cuota_id");
    exit;
}
?>
