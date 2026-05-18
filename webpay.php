<?php
// webpay.php
session_start();

/**
 * Guardamos lo que se estaba pagando
 * para que al volver siga el flujo normal
 */
$_SESSION['webpay_cuotas_ids']      = $_GET['cuotas_ids']      ?? '';
$_SESSION['webpay_actividades_ids'] = $_GET['actividades_ids'] ?? '';

/**
 * URL REAL DE TRANSBANK
 */
$webpay_url = 'https://www.webpay.cl/form-pay/244948'
  . '?utm_source=transbank'
  . '&utm_medium=portal3.0'
  . '&utm_campaign=link_portal';

/**
 * Redirección inmediata
 */
header("Location: $webpay_url");
exit;
