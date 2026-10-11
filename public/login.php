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
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(Auth::csrf()) ?>">
    <title>Ingresar | Asilo Cabeza de Algodón</title>
    <link href="assets/vendor/fonts/fuentes.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/app.css?v=<?= filemtime(__DIR__ . '/assets/css/app.css') ?>" rel="stylesheet">
</head>
<body class="login-body">
<main class="login">
    <section class="login-marca" aria-hidden="true">
        <div class="login-logo">
            <svg viewBox="0 0 48 48" width="44" height="44" aria-hidden="true"><g fill="currentColor"><circle cx="24" cy="14" r="8"/><circle cx="15" cy="21" r="7.5"/><circle cx="33" cy="21" r="7.5"/><circle cx="19" cy="27" r="6.5"/><circle cx="29" cy="27" r="6.5"/></g><path d="M10 29.5c4 3 8.5 4.5 14 4.5s10-1.5 14-4.5M24 34v10" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" opacity=".7"/></svg>
            <span>Asilo Cabeza de Algodón</span>
        </div>
        <p class="login-lema">La ficha médica, las citas con la fundación y la caja del asilo en un solo lugar.</p>
        <p class="login-pie">Centro gerontológico en Mazatenango, Suchitepéquez</p>
    </section>

    <section class="login-panel">
        <form id="formLogin" class="login-form" novalidate>
            <h1>Ingresar al sistema</h1>
            <p class="text-secondary mb-4">Use el correo y la contraseña que le asignó la administración.</p>

            <?php if ($expirada): ?>
                <div class="alert alert-warning py-2">Su sesión expiró. Ingrese de nuevo.</div>
            <?php endif; ?>
            <div id="loginError" class="alert alert-danger py-2 d-none" role="alert"></div>

            <div class="mb-3">
                <label for="email" class="form-label">Correo electrónico</label>
                <input type="email" class="form-control form-control-lg" id="email" name="email" autocomplete="username" required autofocus>
            </div>
            <div class="mb-4">
                <label for="password" class="form-label">Contraseña</label>
                <div class="input-group input-group-lg">
                    <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
                    <button class="btn btn-outline-secondary" type="button" id="verPassword" aria-label="Mostrar contraseña"><i class="bi bi-eye"></i></button>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-lg w-100" id="btnIngresar">Ingresar</button>

            <details class="login-demo">
                <summary>Cuentas de demostración</summary>
                <p>Contraseña para todas: <strong>Asilo2026!</strong></p>
                <ul>
                    <li><button type="button" data-demo="admin@asilo.com">admin@asilo.com</button> Administrador</li>
                    <li><button type="button" data-demo="medico@asilo.com">medico@asilo.com</button> Médico general</li>
                    <li><button type="button" data-demo="enfermera@asilo.com">enfermera@asilo.com</button> Enfermera</li>
                    <li><button type="button" data-demo="fundacion@asilo.com">fundacion@asilo.com</button> Fundación</li>
                    <li><button type="button" data-demo="internista@asilo.com">internista@asilo.com</button> Médico especialista</li>
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
    e.currentTarget.innerHTML = ver ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
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
