<?php
// public/login.php — Pantalla 1: control de acceso
require_once __DIR__ . '/../src/Core/Auth.php';
require_once __DIR__ . '/../src/Core/Bitacora.php';
Auth::iniciarSesion();
if (Auth::usuario()) {
    header('Location: index.php');
    exit;
}
$expirada = isset($_GET['expirada']);
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(Auth::csrf()) ?>">
    <title>Ingresar | Asilo Cabeza de Algodón</title>
    <meta name="theme-color" content="#05080f">
    <link rel="icon" href="assets/img/manos-algodon.svg" type="image/svg+xml">
    <script src="assets/js/tema.js?v=<?= filemtime(__DIR__ . '/assets/js/tema.js') ?>"></script>
    <link href="assets/vendor/fonts/fuentes.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/app.css?v=<?= filemtime(__DIR__ . '/assets/css/app.css') ?>" rel="stylesheet">
</head>
<body class="login-body">
<main class="login">
    <button type="button" class="btn-tema" data-tema-boton><i class="bi bi-moon-stars-fill" aria-hidden="true"></i><i class="bi bi-sun-fill" aria-hidden="true"></i></button>
    <section class="login-marca">
        <div class="login-logo">
            <img src="assets/img/manos-algodon.svg" alt="" width="54" height="54">
            <span>Asilo Cabeza de Algodón</span>
        </div>
        <p class="login-lema">Cuidamos a quienes <em>sembraron</em> nuestro camino.</p>
        <p class="login-pie">Ficha médica, citas con la fundación y caja del asilo en un solo lugar · Mazatenango, Suchitepéquez</p>
        <img class="login-ilustracion" src="assets/img/cosecha.svg" alt="Ilustración: un abuelo cosecha algodón y lo guarda en su canasto" width="420" height="315">
    </section>

    <section class="login-panel">
        <form id="formLogin" class="vidrio" novalidate>
            <div class="vidrio-avatar"><img src="assets/img/manos-algodon.svg" alt="" width="70" height="70"></div>
            <h1>Bienvenido</h1>
            <p class="login-sub">Use el correo y la contraseña que le asignó la administración.</p>

            <?php if ($expirada): ?>
                <div class="alert alert-warning py-2">Su sesión expiró. Ingrese de nuevo.</div>
            <?php endif; ?>
            <div id="loginError" class="alert alert-danger py-2 d-none" role="alert"></div>

            <div class="campo-linea">
                <label for="email">Correo electrónico</label>
                <i class="bi bi-envelope-fill" aria-hidden="true"></i>
                <input type="email" id="email" name="email" autocomplete="username" required autofocus placeholder="usuario@asilo.com">
            </div>
            <div class="campo-linea mb-4">
                <label for="password">Contraseña</label>
                <i class="bi bi-lock-fill" aria-hidden="true"></i>
                <input type="password" id="password" name="password" autocomplete="current-password" required placeholder="••••••••">
                <button class="ver-clave" type="button" id="verPassword" aria-label="Mostrar contraseña"><i class="bi bi-eye"></i></button>
            </div>
            <button type="submit" class="btn-ingresar" id="btnIngresar">Ingresar</button>

            <details class="login-demo">
                <summary>Cuentas de demostración</summary>
                <p>Contraseña para todas: <strong>Asilo2026!</strong></p>
                <ul>
                    <li><button type="button" data-demo="admin@asilo.com">admin@asilo.com</button> Administrador</li>
                    <li><button type="button" data-demo="medico@asilo.com">medico@asilo.com</button> Médico general</li>
                    <li><button type="button" data-demo="enfermera@asilo.com">enfermera@asilo.com</button> Enfermera</li>
                    <li><button type="button" data-demo="fundacion@asilo.com">fundacion@asilo.com</button> Fundación</li>
                    <li><button type="button" data-demo="enfermero@asilo.com">enfermero@asilo.com</button> Enfermero</li>
                    <li><button type="button" data-demo="internista@asilo.com">internista@asilo.com</button> Especialista: Medicina interna</li>
                    <li><button type="button" data-demo="cardiologo@asilo.com">cardiologo@asilo.com</button> Especialista: Cardiología</li>
                    <li><button type="button" data-demo="psiquiatra@asilo.com">psiquiatra@asilo.com</button> Especialista: Psiquiatría</li>
                    <li><button type="button" data-demo="geriatra@asilo.com">geriatra@asilo.com</button> Especialista: Geriatría</li>
                    <li><button type="button" data-demo="traumatologo@asilo.com">traumatologo@asilo.com</button> Especialista: Traumatología</li>
                    <li><button type="button" data-demo="laboratorio@asilo.com">laboratorio@asilo.com</button> Laboratorio</li>
                    <li><button type="button" data-demo="farmacia@asilo.com">farmacia@asilo.com</button> Farmacia</li>
                    <li><button type="button" data-demo="contador@asilo.com">contador@asilo.com</button> Contador (caja)</li>
                </ul>
            </details>
        </form>
    </section>
</main>
<script>
const form = document.getElementById('formLogin');
const err = document.getElementById('loginError');
document.getElementById('verPassword').addEventListener('click', e => {
    const p = document.getElementById('password');
    const ver = p.type === 'password';
    p.type = ver ? 'text' : 'password';
    e.currentTarget.setAttribute('aria-label', ver ? 'Ocultar contraseña' : 'Mostrar contraseña'); e.currentTarget.innerHTML = ver ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
});
document.querySelectorAll('[data-demo]').forEach(b => b.addEventListener('click', () => {
    document.getElementById('email').value = b.dataset.demo;
    document.getElementById('password').value = 'Asilo2026!';
    document.getElementById('password').focus();
}));
form.addEventListener('submit', async e => {
    e.preventDefault();
    err.classList.add('d-none');
    if (!form.checkValidity()) { form.classList.add('was-validated'); return; }
    const btn = document.getElementById('btnIngresar');
    btn.disabled = true; btn.textContent = 'Verificando…';
    try {
        const r = await fetch('api.php?action=login', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name=csrf-token]').content},
            body: JSON.stringify({email: form.email.value, password: form.password.value})
        });
        const j = await r.json();
        if (j.status === 'success') { location.href = 'index.php'; return; }
        err.textContent = j.message; err.classList.remove('d-none');
    } catch (_) {
        err.textContent = 'No hay conexión con el servidor. Verifique que Apache esté encendido.';
        err.classList.remove('d-none');
    }
    btn.disabled = false; btn.textContent = 'Ingresar';
});
</script>
</body>
</html>
