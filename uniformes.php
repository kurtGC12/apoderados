<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');
if (function_exists('mb_internal_encoding')) mb_internal_encoding('UTF-8');

require_once __DIR__ . '/../includes/auth_apoderado.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/webpay_config_helper.php';
@mysqli_set_charset($conn, 'utf8mb4');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function clp($n){ return '$'.number_format((int)$n, 0, ',', '.'); }
function col_exists(mysqli $conn, string $table, string $column): bool {
  $table = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);
  $rs = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
  return $rs && $rs->num_rows > 0;
}
function ensure_uniformes_schema(mysqli $conn): void {
  $conn->query("CREATE TABLE IF NOT EXISTS uniformes_productos (
    id INT NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(140) NOT NULL,
    descripcion TEXT NULL,
    precio INT NOT NULL DEFAULT 0,
    escuela_id INT NULL,
    categoria_id INT NULL,
    tallas VARCHAR(255) NULL,
    stock INT NULL DEFAULT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_uniforme_escuela (escuela_id),
    KEY idx_uniforme_categoria (categoria_id),
    KEY idx_uniforme_activo (activo)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $conn->query("CREATE TABLE IF NOT EXISTS uniformes_producto_tallas (
    id INT NOT NULL AUTO_INCREMENT,
    producto_id INT NOT NULL,
    talla VARCHAR(40) NOT NULL,
    precio INT NOT NULL DEFAULT 0,
    stock INT NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_upt_producto (producto_id),
    KEY idx_upt_activo (activo),
    UNIQUE KEY uq_producto_talla (producto_id, talla)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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
ensure_uniformes_schema($conn);

if (!isset($_SESSION['alumno_rut'])) { header('Location: login.php'); exit; }
$alumno_rut = (string)$_SESSION['alumno_rut'];

$alumno = null;
if ($st=$conn->prepare("SELECT id,nombre,categoria,escuela_id,categoria_id FROM alumnos WHERE rut=? LIMIT 1")) {
  $st->bind_param('s',$alumno_rut);
  $st->execute();
  $st->bind_result($aid,$anombre,$acategoria,$aescuela,$acategoria_id);
  if ($st->fetch()) $alumno=['id'=>(int)$aid,'nombre'=>$anombre,'categoria'=>$acategoria,'escuela_id'=>(int)$aescuela,'categoria_id'=>(int)$acategoria_id];
  $st->close();
}
if (!$alumno) { http_response_code(403); exit('Alumno no válido'); }

$config_escuela = [
  'banco'=>'', 'tipo_cuenta'=>'', 'numero_cuenta'=>'', 'rut_titular'=>'', 'nombre_titular'=>'', 'correo_comprobantes'=>''
];
$qCfg = $conn->query("SELECT banco, tipo_cuenta, numero_cuenta, rut_titular, nombre_titular, correo_comprobantes FROM configuracion WHERE id = 1 LIMIT 1");
if ($qCfg && $qCfg->num_rows > 0) $config_escuela = array_merge($config_escuela, $qCfg->fetch_assoc());

$webpay_activo = function_exists('webpay_cfg_is_active') ? webpay_cfg_is_active($conn) : false;

$productos = [];
$sqlProd = "SELECT id,nombre,descripcion,precio FROM uniformes_productos
            WHERE activo=1
              AND (escuela_id IS NULL OR escuela_id=0 OR escuela_id=?)
              AND (categoria_id IS NULL OR categoria_id=0 OR categoria_id=?)
            ORDER BY nombre ASC";
if ($st=$conn->prepare($sqlProd)) {
  $st->bind_param('ii',$alumno['escuela_id'],$alumno['categoria_id']);
  $st->execute();
  $st->bind_result($pid,$pnombre,$pdesc,$pprecio);
  while($st->fetch()) $productos[(int)$pid]=['id'=>(int)$pid,'nombre'=>$pnombre,'descripcion'=>$pdesc,'precio'=>(int)$pprecio,'tallas'=>[]];
  $st->close();
}

if ($productos) {
  foreach ($productos as $pid => $p) {
    if ($st=$conn->prepare("SELECT id,talla,precio,stock FROM uniformes_producto_tallas WHERE producto_id=? AND activo=1 AND stock>0 ORDER BY id ASC")) {
      $st->bind_param('i',$pid);
      $st->execute();
      $st->bind_result($tid,$ttalla,$tprecio,$tstock);
      while($st->fetch()) {
        $productos[$pid]['tallas'][] = ['id'=>(int)$tid,'talla'=>$ttalla,'precio'=>(int)$tprecio,'stock'=>(int)$tstock];
      }
      $st->close();
    }
  }
  $productos = array_values(array_filter($productos, function($p){ return !empty($p['tallas']); }));
}

$compras=[];
$sqlHist = "SELECT uv.id, uv.talla, uv.cantidad, uv.total, uv.metodo_pago, uv.estado, uv.comprobante, uv.creado_en, up.nombre
            FROM uniformes_ventas uv
            LEFT JOIN uniformes_productos up ON up.id=uv.producto_id
            WHERE uv.alumno_id=?
            ORDER BY uv.creado_en DESC, uv.id DESC";
if ($st=$conn->prepare($sqlHist)) {
  $st->bind_param('i',$alumno['id']);
  $st->execute();
  $st->bind_result($vid,$vtalla,$vcant,$vtotal,$vmet,$vest,$vcomp,$vfecha,$vprod);
  while($st->fetch()) $compras[]=['id'=>$vid,'talla'=>$vtalla,'cantidad'=>$vcant,'total'=>$vtotal,'metodo'=>$vmet,'estado'=>$vest,'comprobante'=>$vcomp,'fecha'=>$vfecha,'producto'=>$vprod];
  $st->close();
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Uniformes · Panel Apoderado</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="../assets/css/sistema.css?v=1" rel="stylesheet">
<style>
  body{background:#f4fbfa;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#16324a}.apo-shell{max-width:1120px;margin:0 auto;padding:14px 10px 34px}.mini-topbar{background:#fff;border:1px solid rgba(15,118,110,.12);border-radius:18px;padding:14px 16px;box-shadow:0 8px 28px rgba(16,42,67,.07);margin-bottom:16px}.mini-title{font-weight:950;letter-spacing:-.03em;color:#0f766e;margin:0;font-size:1.1rem}.mini-sub{margin:0;color:#52748e;font-size:.86rem;font-weight:600}.card-soft{background:#fff;border:1px solid rgba(15,118,110,.12);border-radius:20px;box-shadow:0 8px 28px rgba(16,42,67,.08)}.uniform-card{height:100%;transition:.18s ease}.uniform-card:hover{transform:translateY(-1px);box-shadow:0 14px 30px rgba(16,42,67,.10)}.price{font-size:1.15rem;font-weight:950;color:#0f766e}.small-muted{font-size:.84rem;color:#52748e}.btn-pill{border-radius:999px;font-weight:850;font-size:.84rem;padding:.62rem .92rem}.btn-main{background:#0f766e;color:white;border:0}.btn-main:hover{background:#0d9488;color:white}.btn-soft{background:#eef8f7;color:#0f766e;border:1px solid #cfe8e5}.btn-soft:hover{background:#ccf8f1;color:#065f56}.form-control,.form-select{border-radius:14px;border-color:#dbeae7}.form-label{font-size:.78rem;font-weight:850;color:#52748e}.badge-state{display:inline-flex;gap:.35rem;align-items:center;border-radius:999px;padding:.35rem .65rem;font-size:.75rem;font-weight:850}.st-ok{background:#ccf8f1;color:#065f56}.st-wait{background:#fef3c7;color:#92400e}.st-bad{background:#fee2e2;color:#991b1b}.bank-box{background:#f6fffe;border:1px dashed #99ede0;border-radius:18px;padding:14px}.modal-content{border:0;border-radius:24px;box-shadow:0 24px 70px rgba(16,42,67,.22)}.talla-options{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.talla-option{border:1px solid #d8eee9;border-radius:16px;padding:12px;background:#fff;cursor:pointer;transition:.16s ease;position:relative}.talla-option:hover{border-color:#14b8a6;box-shadow:0 8px 20px rgba(15,118,110,.10)}.talla-option input{position:absolute;opacity:0;pointer-events:none}.talla-option-inner{display:flex;justify-content:space-between;gap:10px;align-items:center}.talla-dot{width:20px;height:20px;border-radius:999px;border:2px solid #93cfc8;display:inline-flex;align-items:center;justify-content:center;min-width:20px}.talla-dot:after{content:"";width:10px;height:10px;border-radius:999px;background:#0f766e;opacity:0}.talla-option:has(input:checked){border-color:#0f766e;background:#f0fdfa}.talla-option:has(input:checked) .talla-dot:after{opacity:1}.pay-grid{display:grid;grid-template-columns:1fr;gap:12px}.pay-option{position:relative;border:1px solid #d8eee9;border-radius:18px;padding:14px;background:#fff;cursor:pointer;transition:.16s ease}.pay-option input{position:absolute;opacity:0;pointer-events:none}.pay-option .pay-title{display:flex;align-items:center;gap:9px;font-weight:900;color:#102a43}.pay-option .pay-desc{font-size:.8rem;color:#52748e;margin-top:4px}.pay-option:has(input:checked){border-color:#0f766e;background:#f0fdfa;box-shadow:0 10px 24px rgba(15,118,110,.12)}.pay-icon{width:34px;height:34px;border-radius:12px;background:#e6fffb;color:#0f766e;display:inline-flex;align-items:center;justify-content:center}.bank-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.bank-cell{background:#fff;border:1px solid #d8eee9;border-radius:14px;padding:10px;display:flex;justify-content:space-between;gap:8px;align-items:center}.bank-cell-label{font-size:.7rem;text-transform:uppercase;letter-spacing:.04em;color:#52748e;font-weight:900}.bank-cell-value{font-size:.86rem;font-weight:850;color:#102a43;word-break:break-word}.copy-mini{border:0;background:#e6fffb;color:#0f766e;border-radius:10px;width:30px;height:30px;min-width:30px}.mobile-purchase{display:none}.webpay-note{background:#eef6ff;border:1px solid #bfdbfe;color:#1e3a8a;border-radius:16px;padding:12px;font-size:.84rem;font-weight:700}@media(min-width:768px){.pay-grid.has-webpay{grid-template-columns:1fr 1fr}}@media(max-width:575px){.apo-shell{padding:10px 8px 26px}.mini-topbar{border-radius:16px}.talla-options{grid-template-columns:1fr}.bank-grid{grid-template-columns:1fr}.desktop-table{display:none}.mobile-purchase{display:block}.modal-dialog{margin:.5rem}.card-soft{border-radius:18px}}
</style>
</head>
<body>
<div class="apo-shell">
  <div class="mini-topbar d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
    <div>
      <h1 class="mini-title"><i class="fa-solid fa-shirt me-2"></i> Uniformes disponibles</h1>
      <p class="mini-sub"><?= h($alumno['nombre']) ?> · <?= h($alumno['categoria']) ?> · Compra independiente de tus cuotas.</p>
    </div>
    <a href="panel_apoderado.php" class="btn btn-soft btn-pill"><i class="fa-solid fa-arrow-left me-1"></i> Volver al panel</a>
  </div>

  <?php if (isset($_GET['ok'])): ?><div class="alert alert-success card-soft py-2"><i class="fa-solid fa-circle-check me-1"></i> Solicitud enviada correctamente. Quedó pendiente de aprobación.</div><?php endif; ?>
  <?php if (isset($_GET['err'])): ?><div class="alert alert-warning card-soft py-2"><i class="fa-solid fa-triangle-exclamation me-1"></i> No se pudo completar la solicitud. Revisa talla, pago o comprobante.</div><?php endif; ?>

  <div class="row g-3 mb-4">
    <?php if (!$productos): ?><div class="col-12"><div class="card-soft text-center p-5 text-muted"><i class="fa-regular fa-folder-open me-1"></i> No hay uniformes disponibles para tu escuela o categoría.</div></div><?php endif; ?>
    <?php foreach($productos as $p):
      $modalId = 'modalUniforme'.$p['id'];
      $precios = array_map(function($t){ return (int)$t['precio']; }, $p['tallas']);
      $minP = min($precios); $maxP = max($precios);
      $precioTxt = $minP === $maxP ? clp($minP) : clp($minP).' - '.clp($maxP);
    ?>
      <div class="col-12 col-md-6 col-xl-4">
        <div class="card-soft uniform-card p-3 d-flex flex-column">
          <div class="d-flex justify-content-between gap-2 align-items-start mb-2"><h5 class="fw-bold mb-0"><?= h($p['nombre']) ?></h5><i class="fa-solid fa-shirt text-success fs-4"></i></div>
          <div class="small-muted flex-grow-1"><?= h($p['descripcion'] ?: 'Producto disponible para compra.') ?></div>
          <div class="mt-3"><div class="small-muted">Precio</div><div class="price"><?= h($precioTxt) ?></div></div>
          <button type="button" class="btn btn-main btn-pill mt-3" data-bs-toggle="modal" data-bs-target="#<?= h($modalId) ?>"><i class="fa-solid fa-cart-shopping me-1"></i> Comprar</button>
        </div>
      </div>

      <div class="modal fade" id="<?= h($modalId) ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
          <form method="post" action="procesar_uniforme.php" enctype="multipart/form-data" class="modal-content uniformeForm">
            <input type="hidden" name="producto_id" value="<?= (int)$p['id'] ?>">
            <input type="hidden" name="precio_unitario_front" class="precioFront" value="<?= (int)$p['tallas'][0]['precio'] ?>">
            <div class="modal-header border-0 pb-0"><div><h5 class="modal-title fw-bold"><i class="fa-solid fa-shirt me-2 text-success"></i><?= h($p['nombre']) ?></h5><div class="small-muted">Selecciona talla y forma de pago</div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label">Talla disponible</label>
                  <div class="talla-options">
                    <?php foreach($p['tallas'] as $idx=>$t): ?>
                      <label class="talla-option">
                        <input type="radio" name="talla_id" value="<?= (int)$t['id'] ?>" data-precio="<?= (int)$t['precio'] ?>" <?= $idx===0?'checked':'' ?> required>
                        <div class="talla-option-inner">
                          <div><strong>Talla <?= h($t['talla']) ?></strong><div class="small-muted"><?= clp($t['precio']) ?></div></div>
                          <span class="talla-dot"></span>
                        </div>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </div>
                <div class="col-6"><label class="form-label">Cantidad</label><input class="form-control qty" type="number" name="cantidad" min="1" value="1"></div>
                <div class="col-6"><label class="form-label">Total</label><div class="form-control fw-bold totalBox"><?= clp($p['tallas'][0]['precio']) ?></div></div>
                <div class="col-12">
                  <label class="form-label">Forma de pago</label>
                  <div class="pay-grid <?= $webpay_activo ? 'has-webpay' : '' ?>">
                    <label class="pay-option"><input class="metodoUniforme" type="radio" name="metodo_pago" value="transferencia" checked><div class="pay-title"><span class="pay-icon"><i class="fa-solid fa-building-columns"></i></span> Transferencia bancaria</div><div class="pay-desc">Adjunta el comprobante y quedará pendiente de aprobación.</div></label>
                    <?php if($webpay_activo): ?>
                    <label class="pay-option"><input class="metodoUniforme" type="radio" name="metodo_pago" value="webpay"><div class="pay-title"><span class="pay-icon"><i class="fa-solid fa-credit-card"></i></span> Webpay / Tarjeta</div><div class="pay-desc">Paga en línea con el mismo flujo usado para cuotas.</div></label>
                    <?php endif; ?>
                  </div>
                  <?php if($webpay_activo): ?><div class="webpay-note mt-2"><i class="fa-solid fa-circle-check me-1"></i> Webpay está activado para uniformes.</div><?php endif; ?>
                </div>
                <div class="col-12 boxTransferencia">
                  <div class="bank-box mb-2">
                    <strong><i class="fa-solid fa-building-columns me-1"></i> Datos de transferencia</strong>
                    <div class="small-muted mt-1 mb-2">Son los mismos datos configurados para el pago de cuotas.</div>
                    <div class="bank-grid">
                      <div class="bank-cell"><div><div class="bank-cell-label">Banco</div><div class="bank-cell-value"><?= h($config_escuela['banco'] ?: '—') ?></div></div><button type="button" class="copy-mini btnCopiar" data-val="<?= h($config_escuela['banco']) ?>"><i class="fa-regular fa-copy"></i></button></div>
                      <div class="bank-cell"><div><div class="bank-cell-label"><?= h($config_escuela['tipo_cuenta'] ?: 'Cuenta') ?></div><div class="bank-cell-value"><?= h($config_escuela['numero_cuenta'] ?: '—') ?></div></div><button type="button" class="copy-mini btnCopiar" data-val="<?= h($config_escuela['numero_cuenta']) ?>"><i class="fa-regular fa-copy"></i></button></div>
                      <div class="bank-cell"><div><div class="bank-cell-label">Titular</div><div class="bank-cell-value"><?= h($config_escuela['nombre_titular'] ?: '—') ?></div></div><button type="button" class="copy-mini btnCopiar" data-val="<?= h($config_escuela['nombre_titular']) ?>"><i class="fa-regular fa-copy"></i></button></div>
                      <div class="bank-cell"><div><div class="bank-cell-label">RUT</div><div class="bank-cell-value"><?= h($config_escuela['rut_titular'] ?: '—') ?></div></div><button type="button" class="copy-mini btnCopiar" data-val="<?= h($config_escuela['rut_titular']) ?>"><i class="fa-regular fa-copy"></i></button></div>
                      <div class="bank-cell" style="grid-column:1/-1"><div><div class="bank-cell-label">Correo comprobantes</div><div class="bank-cell-value"><?= h($config_escuela['correo_comprobantes'] ?: '—') ?></div></div><button type="button" class="copy-mini btnCopiar" data-val="<?= h($config_escuela['correo_comprobantes']) ?>"><i class="fa-regular fa-copy"></i></button></div>
                    </div>
                  </div>
                  <label class="form-label">Adjuntar comprobante</label><input class="form-control comprobanteUniforme" type="file" name="comprobante" accept=".pdf,.jpg,.jpeg,.png,.webp">
                </div>
                <div class="col-12"><label class="form-label">Observación opcional</label><textarea class="form-control" name="observacion" rows="2" placeholder="Comentario para la escuela"></textarea></div>
              </div>
            </div>
            <div class="modal-footer border-0"><button type="button" class="btn btn-soft btn-pill" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-main btn-pill"><i class="fa-solid fa-paper-plane me-1"></i> Enviar compra</button></div>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card-soft p-3 p-lg-4" id="historial-uniformes">
    <h5 class="fw-bold mb-3"><i class="fa-solid fa-clock-rotate-left me-2 text-success"></i> Mis compras de uniformes</h5>
    <div class="table-responsive desktop-table"><table class="table table-hover align-middle mb-0"><thead><tr><th>Fecha</th><th>Producto</th><th>Talla</th><th>Cantidad</th><th>Total</th><th>Estado</th><th>Comprobante</th></tr></thead><tbody>
      <?php if (!$compras): ?><tr><td colspan="7" class="text-center text-muted py-4">Aún no tienes compras de uniformes.</td></tr><?php endif; ?>
      <?php foreach($compras as $c): $estado=strtolower((string)$c['estado']); $cls=in_array($estado,['aprobado','pagado'],true)?'st-ok':($estado==='pendiente'?'st-wait':'st-bad'); ?>
        <tr><td><?= h(date('d-m-Y H:i', strtotime($c['fecha']))) ?></td><td><strong><?= h($c['producto']) ?></strong><div class="small-muted"><?= h($c['metodo']) ?></div></td><td><?= h($c['talla'] ?: '—') ?></td><td><?= (int)$c['cantidad'] ?></td><td><strong><?= clp($c['total']) ?></strong></td><td><span class="badge-state <?= $cls ?>"><?= h(ucfirst($c['estado'])) ?></span></td><td><?php if($c['comprobante']): ?><a target="_blank" href="<?= h($c['comprobante']) ?>" class="btn btn-soft btn-sm btn-pill"><i class="fa-solid fa-paperclip"></i></a><?php else: ?>—<?php endif; ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <div class="mobile-purchase">
      <?php if (!$compras): ?><div class="text-center text-muted py-4">Aún no tienes compras de uniformes.</div><?php endif; ?>
      <?php foreach($compras as $c): $estado=strtolower((string)$c['estado']); $cls=in_array($estado,['aprobado','pagado'],true)?'st-ok':($estado==='pendiente'?'st-wait':'st-bad'); ?>
        <div class="border rounded-4 p-3 mb-2 bg-white"><div class="d-flex justify-content-between gap-2"><strong><?= h($c['producto']) ?></strong><span class="badge-state <?= $cls ?>"><?= h(ucfirst($c['estado'])) ?></span></div><div class="small-muted mt-1">Talla <?= h($c['talla'] ?: '—') ?> · Cant. <?= (int)$c['cantidad'] ?> · <?= h($c['metodo']) ?></div><div class="fw-bold mt-2"><?= clp($c['total']) ?></div><?php if($c['comprobante']): ?><a target="_blank" href="<?= h($c['comprobante']) ?>" class="btn btn-soft btn-sm btn-pill mt-2"><i class="fa-solid fa-paperclip me-1"></i> Comprobante</a><?php endif; ?></div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function fmt(n){ return '$'+parseInt(n||0,10).toLocaleString('es-CL'); }
document.querySelectorAll('.uniformeForm').forEach(form => {
  const qty = form.querySelector('.qty');
  const totalBox = form.querySelector('.totalBox');
  const precioFront = form.querySelector('.precioFront');
  const tallaRadios = form.querySelectorAll('input[name="talla_id"]');
  const metodos = form.querySelectorAll('.metodoUniforme');
  const boxT = form.querySelector('.boxTransferencia');
  const comp = form.querySelector('.comprobanteUniforme');
  function selectedPrecio(){ const checked = form.querySelector('input[name="talla_id"]:checked'); return parseInt(checked?.dataset.precio || 0, 10); }
  function refreshTotal(){ const precio = selectedPrecio(); const cantidad = Math.max(1, parseInt(qty?.value || 1, 10)); if(totalBox) totalBox.textContent = fmt(precio * cantidad); if(precioFront) precioFront.value = precio; }
  function refreshMetodo(){ const checked = form.querySelector('.metodoUniforme:checked'); const isT = !checked || checked.value === 'transferencia'; if(boxT) boxT.style.display = isT ? '' : 'none'; if(comp) comp.required = isT; }
  qty?.addEventListener('input', refreshTotal); tallaRadios.forEach(r => r.addEventListener('change', refreshTotal)); metodos.forEach(m => m.addEventListener('change', refreshMetodo)); refreshTotal(); refreshMetodo();
});
document.querySelectorAll('.btnCopiar').forEach(btn => btn.addEventListener('click', async () => { const val = btn.dataset.val || ''; if (!val) return; try { await navigator.clipboard.writeText(val); btn.innerHTML = '<i class="fa-solid fa-check"></i>'; setTimeout(()=>btn.innerHTML='<i class="fa-regular fa-copy"></i>', 1000); } catch(e) {} }));
</script>
</body>
</html>
