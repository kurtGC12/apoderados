<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

function rut_norm($r){
    return strtoupper(str_replace(['.', '-', ' '], '', trim($r)));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $rut   = rut_norm($_POST['rut'] ?? '');
    $clave = trim($_POST['clave'] ?? '');

    if ($rut === '' || $clave === '') {
        $error = 'Debes ingresar RUT y contraseña.';
    } else {

        $sql = "
            SELECT id, nombre, rut
            FROM alumnos
            WHERE REPLACE(REPLACE(UPPER(rut),'.',''),'-','') = ?
              AND clave = ?
            LIMIT 1
        ";

        if ($st = $conn->prepare($sql)) {
            $st->bind_param('ss', $rut, $clave);
            $st->execute();
            $st->bind_result($id, $nombre, $rut_db);

            if ($st->fetch()) {

                // ✅ SESIÓN CORRECTA
                $_SESSION['rol']           = 'apoderado';
                $_SESSION['alumno_id']     = (int)$id;
                $_SESSION['alumno_nombre'] = $nombre;
                $_SESSION['alumno_rut']    = $rut_db;

                $st->close();
                header('Location: panel_apoderado.php');
                exit;
            } else {
                $error = 'RUT o contraseña incorrectos.';
            }
            $st->close();
        } else {
            $error = 'Error interno de sistema.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Acceso Apoderados | Academia de fútbol </title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
/* ===== Fondo con video + overlay difuminado ===== */
html, body { height: 100%; }
body{
  margin:0;
  min-height:100vh;
  display:flex;
  align-items:center;
  justify-content:center;
  overflow:hidden;
  font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}

/* Video ocupa toda la pantalla */
.bg-video{
  position:fixed;
  inset:0;
  width:100%;
  height:100%;
  object-fit:cover;
  z-index:-3;
}

/* Capa difuminada (blur real) + oscurecido elegante */
.bg-overlay{
  position:fixed;
  inset:0;
  z-index:-2;
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  background: rgba(10, 20, 35, 0.30);
}

/* Velo/vinieta para contraste suave */
.bg-vignette{
  position:fixed;
  inset:0;
  z-index:-1;
  background:
    radial-gradient(60% 60% at 50% 40%, rgba(255,255,255,0.12) 0%, rgba(255,255,255,0) 55%),
    radial-gradient(70% 80% at 50% 50%, rgba(0,0,0,0.30) 0%, rgba(0,0,0,0.58) 70%);
}

/* ===== Card elegante tipo glass ===== */
.login-card{
  width:100%;
  max-width:430px;
  padding:30px 28px;
  border-radius:22px;

  background: rgba(255,255,255,0.90);
  border: 1px solid rgba(255,255,255,0.55);
  box-shadow:
    0 25px 60px rgba(0,0,0,.30),
    0 1px 0 rgba(255,255,255,.35) inset;
}

/* Encabezado */
.brand{
  text-align:center;
  margin-bottom:18px;
}

/* ✅ LOGO MÁS GRANDE + TRANSPARENTE (sin cajita) */
.brand .logo{
  width:130px;
  height:130px;
  margin:0 auto 12px;
  border-radius:0;
  background: transparent;
  display:flex;
  align-items:center;
  justify-content:center;
  box-shadow:none;
}

.brand .logo img{
  width:100%;
  height:100%;
  object-fit:contain;
  display:block;
  background: transparent;
}

.brand h1{
  font-size:24px;
  margin:0;
  font-weight:900;
  letter-spacing:.2px;
  color:#0f172a;
}

.brand p{
  font-size:13px;
  color:#475569;
  margin-top:4px;
}

/* Inputs finos */
.form-label{
  font-size:12.5px;
  font-weight:800;
  color:#334155;
}

.form-control{
  border-radius:14px;
  padding:12px 14px;
  border:1px solid rgba(148,163,184,.55);
  background: rgba(255,255,255,0.92);
}

.form-control:focus{
  box-shadow:0 0 0 .2rem rgba(56,189,248,.18);
  border-color:#38bdf8;
}

/* Botón principal */
.btn-login{
  border-radius:14px;
  padding:12px;
  font-weight:900;
  letter-spacing:.2px;
  border:none;
  background: linear-gradient(135deg, #0ea5e9, #38bdf8);
  box-shadow: 0 12px 24px rgba(14,165,233,.22);
}

.btn-login:hover{
  filter: brightness(0.98);
}

.footer-text{
  text-align:center;
  font-size:12px;
  color:#475569;
  margin-top:14px;
}

.alert{
  border-radius:14px;
}

/* Responsive */
@media (max-width: 420px){
  .login-card{ margin: 0 14px; padding: 26px 22px; }
  .brand .logo{ width:110px; height:110px; } /* ✅ grande pero proporcional en móvil */
  .brand h1{ font-size:22px; }
}
</style>
</head>

<body>

<!-- ✅ Video de fondo: mismo directorio -->
<video class="bg-video" autoplay muted loop playsinline>
  <source src="escuela.mp4" type="video/mp4">
</video>

<div class="bg-overlay"></div>
<div class="bg-vignette"></div>

<div class="login-card">

  <div class="brand">
    <div class="logo">
      <img src="logo.svg" alt="Logo Academia de fútbol">
    </div>
    <h1>Academia de Fútbol </h1>
    <p>Portal de Apoderados</p>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger py-2 text-center mb-3">
      <?=htmlspecialchars($error)?>
    </div>
  <?php endif; ?>

  <form method="POST" autocomplete="off">

    <div class="mb-3">
      <label class="form-label">RUT del alumno</label>
      <input type="text" name="rut" class="form-control" placeholder="12.345.678-9" required>
    </div>

    <div class="mb-3">
      <label class="form-label">Contraseña</label>
      <input type="password" name="clave" class="form-control" placeholder="••••••••" required>
    </div>

    <button class="btn btn-login w-100 text-white">
      Ingresar
    </button>

  </form>

  <div class="footer-text">
    Acceso exclusivo para apoderados registrados en la Academia.
  </div>

</div>

</body>
</html>
