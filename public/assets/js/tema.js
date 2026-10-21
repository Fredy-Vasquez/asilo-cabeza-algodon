// public/assets/js/tema.js — Tema claro / oscuro.
// Se carga en el <head> para aplicar el tema antes de pintar la página (sin parpadeo).
// Por defecto el sistema abre en modo oscuro; la elección del usuario se guarda en el navegador.
(function () {
    var CLAVE = 'asilo-tema';
    var raiz = document.documentElement;
    function leer() { try { return localStorage.getItem(CLAVE); } catch (e) { return null; } }
    function guardar(t) { try { localStorage.setItem(CLAVE, t); } catch (e) { /* modo privado: no se recuerda */ } }
    function aplicar(t) {
        raiz.setAttribute('data-bs-theme', t);
        var meta = document.querySelector('meta[name=theme-color]');
        if (meta) meta.setAttribute('content', t === 'dark' ? '#05080f' : '#14213D');
        document.querySelectorAll('[data-tema-boton]').forEach(function (b) {
            var oscuro = t === 'dark';
            b.setAttribute('aria-pressed', oscuro ? 'true' : 'false');
            b.title = oscuro ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro';
            b.setAttribute('aria-label', b.title);
        });
    }
    aplicar(leer() || 'dark');

    window.cambiarTema = function () {
        var nuevo = raiz.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
        guardar(nuevo);
        aplicar(nuevo);
    };
    document.addEventListener('DOMContentLoaded', function () {
        aplicar(raiz.getAttribute('data-bs-theme'));
        document.querySelectorAll('[data-tema-boton]').forEach(function (b) {
            b.addEventListener('click', window.cambiarTema);
        });
    });
})();
