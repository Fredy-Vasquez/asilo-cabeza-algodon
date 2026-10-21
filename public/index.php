<?php
// public/index.php — Panel principal (SPA). Requiere sesión iniciada.
require_once __DIR__ . '/../src/Core/Auth.php';
require_once __DIR__ . '/../src/Core/Bitacora.php';
Auth::iniciarSesion();
$u = Auth::usuario();
if (!$u) {
    header('Location: login.php');
    exit;
}
$iniciales = implode('', array_map(fn($p) => mb_substr($p, 0, 1), array_slice(preg_split('/\s+/', preg_replace('/^(Dra?\.|Lic\.|Ing\.)\s*/', '', $u['nombre'])), 0, 2)));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(Auth::csrf()) ?>">
    <title>Asilo Cabeza de Algodón</title>
    <link href="assets/vendor/fonts/fuentes.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/app.css?v=<?= filemtime(__DIR__ . '/assets/css/app.css') ?>" rel="stylesheet">
</head>
<body>
<a class="visually-hidden-focusable saltar" href="#contenido">Saltar al contenido</a>

<div class="app">
    <!-- Barra lateral: offcanvas en móvil, fija en escritorio -->
    <aside class="sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="sidebar" aria-label="Menú principal">
        <div class="sidebar-marca">
            <svg viewBox="0 0 48 48" width="30" height="30" aria-hidden="true"><g fill="currentColor"><circle cx="24" cy="14" r="8"/><circle cx="15" cy="21" r="7.5"/><circle cx="33" cy="21" r="7.5"/><circle cx="19" cy="27" r="6.5"/><circle cx="29" cy="27" r="6.5"/></g><path d="M10 29.5c4 3 8.5 4.5 14 4.5s10-1.5 14-4.5M24 34v10" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" opacity=".7"/></svg>
            <span>Cabeza de Algodón</span>
            <button type="button" class="btn-close btn-close-white ms-auto d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Cerrar menú"></button>
        </div>
        <nav class="sidebar-nav" id="menu"></nav>
        <div class="sidebar-usuario">
            <span class="avatar avatar-claro"><?= htmlspecialchars($iniciales) ?></span>
            <div class="flex-grow-1 min-w-0">
                <div class="text-truncate fw-bold"><?= htmlspecialchars($u['nombre']) ?></div>
                <div class="sidebar-rol"><?= htmlspecialchars($u['rol'] === 'Medico' ? 'Médico' : $u['rol']) ?></div>
            </div>
            <button class="btn btn-icono" id="btnSalir" title="Cerrar sesión" aria-label="Cerrar sesión"><i class="bi bi-box-arrow-right"></i></button>
        </div>
    </aside>

    <div class="principal">
        <header class="topbar">
            <button class="btn btn-icono d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-label="Abrir menú"><i class="bi bi-list"></i></button>
            <h1 id="tituloVista">Inicio</h1>
            <div class="ms-auto d-flex align-items-center gap-2" id="accionesVista"></div>
        </header>
        <main id="contenido" tabindex="-1">
            <div class="cargando"><div class="spinner-border text-success" role="status"><span class="visually-hidden">Cargando…</span></div></div>
        </main>
    </div>
</div>

<!-- Modal genérico de formularios (el contenido lo arma app.js) -->
<div class="modal fade" id="modalForm" tabindex="-1" aria-labelledby="modalFormTitulo" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
        <form class="modal-content" id="modalFormEl" novalidate>
            <div class="modal-header">
                <h2 class="modal-title" id="modalFormTitulo"></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body" id="modalFormCuerpo"></div>
            <div class="modal-footer">
                <div id="modalFormError" class="text-danger me-auto small" role="alert"></div>
                <button type="button" class="btn btn-link text-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="modalFormBoton">Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- Expediente del residente -->
<div class="offcanvas offcanvas-end expediente" tabindex="-1" id="panelExpediente" aria-labelledby="expTitulo">
    <div class="offcanvas-header">
        <h2 class="offcanvas-title" id="expTitulo">Expediente</h2>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
    </div>
    <div class="offcanvas-body" id="expCuerpo"></div>
</div>

<!-- Confirmación -->
<div class="modal fade" id="modalConfirmar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body p-4">
                <h2 class="h5 mb-2" id="confTitulo"></h2>
                <p class="mb-0 text-secondary" id="confTexto"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link text-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="confBoton">Confirmar</button>
            </div>
        </div>
    </div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3" id="avisos" aria-live="polite"></div>

<script>
window.USUARIO = <?= json_encode(['id' => $u['id'], 'id_rol' => $u['id_rol'], 'nombre' => $u['nombre'], 'rol' => $u['rol']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
</script>
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
<script src="assets/js/atencion.js?v=<?= filemtime(__DIR__ . '/assets/js/atencion.js') ?>"></script>
<script src="assets/js/servicios.js?v=<?= filemtime(__DIR__ . '/assets/js/servicios.js') ?>"></script>
<script src="assets/js/caja.js?v=<?= filemtime(__DIR__ . '/assets/js/caja.js') ?>"></script>
<script src="assets/js/reportes.js?v=<?= filemtime(__DIR__ . '/assets/js/reportes.js') ?>"></script>
</body>
</html>
