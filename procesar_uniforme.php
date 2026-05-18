<?php
session_start();
require_once __DIR__ . '/../includes/auth_apoderado.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/webpay_config_helper.php';
@mysqli_set_charset($conn, 'utf8mb4');

function redirect_uniforme(string $qs): void { header('Location: uniformes.php'.$qs); exit; }
function col_exists(mysqli $conn, string $table, string $column): bool {
  $table = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);
  $rs = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
  return $rs && $rs->num_rows > 0;
}
function ensure_uniformes_ventas_schema(mysqli $conn): void {
  $conn->query("CREATE TABLE IF NOT EXISTS uniformes_ventas (
    id INT NOT NULL AUTO_INCREMENT,
    producto_id INT NOT NULL,
    talla_id INT NULL,
    alumno_id INT NOT NULL,
    escuela_id INT NULL,
    categoria_id INT NULL,
    talla VARCHAR(40) NULL,
    cantidad INT NOT NULL DEFAULT 1,
    precio_unitario INT NOT NULL DEFAULT 0,
    total INT NOT NULL DEFAULT 0,
    metodo_pago ENUM('transferencia','webpay','efectivo') NOT NULL DEFAULT 'transferencia',
    estado ENUM('pendiente','aprobado','rechazado','pagado','anulado') NOT NULL DEFAULT 'pendiente',
    comprobante VARCHAR(255) NULL,
    observacion TEXT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aprobado_por VARCHAR(120) NULL,
    aprobado_en DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_uniforme_producto (producto_id),
    KEY idx_uniforme_alumno (alumno_id),
    KEY idx_uniforme_escuela (escuela_id),
    KEY idx_uniforme_estado (estado),
    KEY idx_uniforme_fecha (creado_en)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  if (!col_exists($conn, 'uniformes_ventas', 'talla_id')) @ $conn->query("ALTER TABLE uniformes_ventas ADD COLUMN talla_id INT NULL AFTER producto_id");
}
ensure_uniformes_ventas_schema($conn);

if (!isset($_SESSION['alumno_rut'])) redirect_uniforme('?err=sesion');
$alumno_rut = (string)$_SESSION['alumno_rut'];

$alumno = null;
if ($st=$conn->prepare("SELECT id,nombre,escuela_id,categoria_id FROM alumnos WHERE rut=? LIMIT 1")) {
  $st->bind_param('s',$alumno_rut);
  $st->execute();
  $st->bind_result($aid,$anombre,$aescuela,$acategoria);
  if ($st->fetch()) $alumno=['id'=>(int)$aid,'nombre'=>$anombre,'escuela_id'=>(int)$aescuela,'categoria_id'=>(int)$acategoria];
  $st->close();
}
if (!$alumno) redirect_uniforme('?err=alumno');

$producto_id = max(0, (int)($_POST['producto_id'] ?? 0));
$talla_id = max(0, (int)($_POST['talla_id'] ?? 0));
$cantidad = max(1, (int)($_POST['cantidad'] ?? 1));
$metodo_pago = (string)($_POST['metodo_pago'] ?? 'transferencia');
$observacion = trim((string)($_POST['observacion'] ?? ''));
if (!in_array($metodo_pago, ['transferencia','webpay'], true)) $metodo_pago = 'transferencia';
if ($producto_id <= 0 || $talla_id <= 0) redirect_uniforme('?err=producto');

if ($metodo_pago === 'webpay') {
  $webpay_activo = function_exists('webpay_cfg_is_active') ? webpay_cfg_is_active($conn) : false;
  if (!$webpay_activo) redirect_uniforme('?err=webpay');
}

$uploadPath = null;
if ($metodo_pago === 'transferencia') {
  if (empty($_FILES['comprobante']['name']) || (int)($_FILES['comprobante']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    redirect_uniforme('?err=comprobante');
  }
  $allowed = ['pdf','jpg','jpeg','png','webp'];
  $ext = strtolower(pathinfo((string)$_FILES['comprobante']['name'], PATHINFO_EXTENSION));
  if (!in_array($ext, $allowed, true)) redirect_uniforme('?err=archivo');

  $dirFs = realpath(__DIR__ . '/../uploads');
  if (!$dirFs) {
    @mkdir(__DIR__ . '/../uploads', 0775, true);
    $dirFs = realpath(__DIR__ . '/../uploads');
  }
  $subDir = $dirFs . '/uniformes';
  if (!is_dir($subDir)) @mkdir($subDir, 0775, true);
  $safeName = 'uniforme_'.$alumno['id'].'_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.'.$ext;
  $destFs = $subDir . '/' . $safeName;
  if (!move_uploaded_file($_FILES['comprobante']['tmp_name'], $destFs)) redirect_uniforme('?err=upload');
  $uploadPath = '../uploads/uniformes/'.$safeName;
}

try {
  $conn->begin_transaction();

  $sql = "SELECT up.id, up.nombre, up.escuela_id, up.categoria_id, upt.id, upt.talla, upt.precio, upt.stock
          FROM uniformes_productos up
          INNER JOIN uniformes_producto_tallas upt ON upt.producto_id = up.id
          WHERE up.id=? AND upt.id=? AND up.activo=1 AND upt.activo=1
            AND (up.escuela_id IS NULL OR up.escuela_id=0 OR up.escuela_id=?)
            AND (up.categoria_id IS NULL OR up.categoria_id=0 OR up.categoria_id=?)
          LIMIT 1
          FOR UPDATE";
  $st = $conn->prepare($sql);
  if (!$st) throw new Exception($conn->error);
  $st->bind_param('iiii', $producto_id, $talla_id, $alumno['escuela_id'], $alumno['categoria_id']);
  if (!$st->execute()) throw new Exception($st->error);
  $st->bind_result($pid,$pnombre,$pesc,$pcat,$tid,$talla,$precio,$stock);
  if (!$st->fetch()) throw new Exception('Producto o talla no disponible.');
  $producto = ['id'=>(int)$pid,'escuela_id'=>(int)$pesc,'categoria_id'=>(int)$pcat,'talla_id'=>(int)$tid,'talla'=>$talla,'precio'=>(int)$precio,'stock'=>(int)$stock];
  $st->close();

  if ($producto['stock'] < $cantidad) throw new Exception('Stock insuficiente.');

  $upd = $conn->prepare("UPDATE uniformes_producto_tallas SET stock = stock - ?, actualizado_en=NOW() WHERE id=? AND stock >= ?");
  if (!$upd) throw new Exception($conn->error);
  $upd->bind_param('iii', $cantidad, $producto['talla_id'], $cantidad);
  if (!$upd->execute()) throw new Exception($upd->error);
  if ($upd->affected_rows < 1) throw new Exception('No se pudo reservar stock.');
  $upd->close();

  $total = $producto['precio'] * $cantidad;
  $estado = $metodo_pago === 'webpay' ? 'pendiente' : 'pendiente';
  $sqlIns = "INSERT INTO uniformes_ventas
            (producto_id, talla_id, alumno_id, escuela_id, categoria_id, talla, cantidad, precio_unitario, total, metodo_pago, estado, comprobante, observacion)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
  $ins = $conn->prepare($sqlIns);
  if (!$ins) throw new Exception($conn->error);
  $escuelaVenta = $alumno['escuela_id'];
  $categoriaVenta = $alumno['categoria_id'];
  $tallaGuardar = $producto['talla'];
  $precioUnitario = $producto['precio'];
  $comp = $uploadPath;
  $ins->bind_param('iiiiisiiissss', $producto_id, $talla_id, $alumno['id'], $escuelaVenta, $categoriaVenta, $tallaGuardar, $cantidad, $precioUnitario, $total, $metodo_pago, $estado, $comp, $observacion);
  if (!$ins->execute()) throw new Exception($ins->error);
  $venta_id = (int)$conn->insert_id;
  $ins->close();

  $conn->commit();

  if ($metodo_pago === 'webpay') {
    header('Location: ../webpay/crear_transaccion_uniforme.php?venta_id='.$venta_id);
    exit;
  }
  redirect_uniforme('?ok=1');
} catch (Throwable $e) {
  $conn->rollback();
  if ($uploadPath) {
    $tryFs = realpath(__DIR__ . '/../') . '/' . ltrim(str_replace('../','',$uploadPath), '/');
    if (is_file($tryFs)) @unlink($tryFs);
  }
  redirect_uniforme('?err=stock');
}
