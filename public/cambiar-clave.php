<?php
// public/cambiar-clave.php — Cambio obligatorio de la contraseña temporal
// (después de que el administrador la restableció o al primer ingreso de un usuario nuevo).
require_once __DIR__ . '/../src/Core/Auth.php';
require_once __DIR__ . '/../src/Core/Bitacora.php';
Auth::iniciarSesion();
$u = Auth::vigente();
if (!$u) {
    header('Location: login.php');
    exit;
}
if (empty($u['debe_cambiar'])) {
    header('Location: index.php');
    exit;
}
$nombre = explode(' ', preg_replace('/^(Dra?\.|Lic\.|Ing\.)\s*/', '', $u['nombre']))[0];
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(Auth::csrf()) ?>">
    <title>Cree su contraseña | Asilo Cabeza de Algodón</title>
    <meta name="theme-color" content="#05080f">
    <link rel="icon" href="assets/img/manos-algodon.svg" type="image/svg+xml">
    <script src="assets/js/tema.js?v=<?= filemtime(__DIR__ . '/assets/js/tema.js') ?>"></script>
    <link href="assets/vendor/fonts/fuentes.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/app.css?v=<?= filemtime(__DIR__ . '/assets/css/app.css') ?>" rel="stylesheet">
</head>
<body class="login-body">
<main class="login login-solo">
    <button type="button" class="btn-tema" data-tema-boton><i class="bi bi-moon-stars-fill" aria-hidden="true"></i><i class="bi bi-sun-fill" aria-hidden="true"></i></button>
    <section class="login-panel">
        <form id="formClave" class="vidrio" novalidate>
            <div class="vidrio-avatar"><i class="bi bi-shield-lock-fill"></i></div>
            <h1>Hola, <?= htmlspecialchars($nombre) ?></h1>
            <p class="login-sub">Está usando una contraseña temporal. Por seguridad, cree una contraseña nueva que solo usted conozca.</p>
            <div id="claveError" class="alert alert-danger py-2 d-none" role="alert"></div>

            <div class="campo-linea">
                <label for="actual">Contraseña temporal</label>
                <i class="bi bi-key-fill" aria-hidden="true"></i>
                <input type="password" id="actual" name="actual" autocomplete="current-password" required autofocus>
            </div>
            <div class="campo-linea">
                <label for="nueva">Contraseña nueva</label>
                <i class="bi bi-lock-fill" aria-hidden="true"></i>
                <input type="password" id="nueva" name="nueva" autocomplete="new-password" required minlength="8">
                <button class="ver-clave" type="button" data-ver="nueva" aria-label="Mostrar contraseña"><i class="bi bi-eye"></i></button>
            </div>
            <div class="campo-linea">
                <label for="confirmar">Repita la contraseña nueva</label>
                <i class="bi bi-lock-fill" aria-hidden="true"></i>
                <input type="password" id="confirmar" name="confirmar" autocomplete="new-password" required>
            </div>
            <ul class="reglas-clave" id="reglas" aria-live="polite">
                <li data-regla="largo">Al menos 8 caracteres</li>
                <li data-regla="letra">Contiene letras</li>
                <li data-regla="numero">Contiene números</li>
                <li data-regla="distinta">Diferente de la temporal</li>
                <li data-regla="igual">Las dos contraseñas coinciden</li>
            </ul>
            <button type="submit" class="btn-ingresar" id="btnClave">Guardar y entrar</button>
            <p class="olvido-enlace text-center mt-3 mb-0"><button type="button" id="salir"><i class="bi bi-box-arrow-left"></i> Salir sin cambiarla</button></p>
        </form>
    </section>
</main>
<script>
const f = document.getElementById('formClave');
const err = document.getElementById('claveError');
const csrf = document.querySelector('meta[name=csrf-token]').content;
const reglas = {
    largo: () => f.nueva.value.length >= 8,
    letra: () => /[A-Za-z]/.test(f.nueva.value),
    numero: () => /\d/.test(f.nueva.value),
    distinta: () => f.nueva.value !== '' && f.nueva.value !== f.actual.value,
    igual: () => f.confirmar.value !== '' && f.nueva.value === f.confirmar.value,
};
const revisar = () => {
    let ok = true;
    document.querySelectorAll('#reglas li').forEach(li => {
        const cumple = reglas[li.dataset.regla]();
        li.classList.toggle('cumple', cumple);
        ok = ok && cumple;
    });
    return ok;
};
['input', 'change'].forEach(ev => f.addEventListener(ev, revisar));
document.querySelector('[data-ver]').addEventListener('click', e => {
    const ver = f.nueva.type === 'password';
    f.nueva.type = f.confirmar.type = ver ? 'text' : 'password';
    e.currentTarget.innerHTML = ver ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
});
document.getElementById('salir').addEventListener('click', async () => {
    try { await fetch('api.php?action=logout', {method: 'POST', headers: {'X-CSRF-Token': csrf}}); } catch (_) {}
    location.href = 'login.php';
});
f.addEventListener('submit', async e => {
    e.preventDefault();
    err.classList.add('d-none');
    if (!revisar()) { err.textContent = 'La contraseña nueva todavía no cumple todos los requisitos de la lista.'; err.classList.remove('d-none'); return; }
    const btn = document.getElementById('btnClave');
    btn.disabled = true; btn.textContent = 'Guardando…';
    try {
        const r = await fetch('api.php?action=cambiar_clave', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
            body: JSON.stringify({actual: f.actual.value, nueva: f.nueva.value, confirmar: f.confirmar.value})
        });
        if (r.status === 401) { location.href = 'login.php?expirada=1'; return; }
        const j = await r.json();
        if (j.status === 'success') { location.href = 'index.php'; return; }
        err.textContent = j.message; err.classList.remove('d-none');
    } catch (_) {
        err.textContent = 'No hay conexión con el servidor.'; err.classList.remove('d-none');
    }
    btn.disabled = false; btn.textContent = 'Guardar y entrar';
});
</script>
</body>
</html>
