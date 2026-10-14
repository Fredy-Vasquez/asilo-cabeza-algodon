/* ============================================================
   Asilo Cabeza de Algodón — aplicación de una sola página (SPA)
   Navegación por hash (#inicio, #residentes, ...), Fetch API, Bootstrap 5.
   ============================================================ */
'use strict';

const U = window.USUARIO;
const ROL = { ADMIN: 1, MEDICO: 2, CONTADOR: 3, ENFERMERO: 4, FUNDACION: 5, ESPECIALISTA: 6, LABORATORIO: 7, FARMACIA: 8 };
const TODOS = [1, 2, 3, 4, 5, 6, 7, 8];
const puede = (...roles) => roles.includes(U.id_rol);
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
const $ = (sel, raiz = document) => raiz.querySelector(sel);
const $$ = (sel, raiz = document) => [...raiz.querySelectorAll(sel)];

/* ---------------- Utilidades ---------------- */
async function api(accion, datos = null, extra = '') {
    const opciones = { headers: { 'X-CSRF-Token': CSRF, 'Accept': 'application/json' } };
    if (datos !== null) {
        opciones.method = 'POST';
        opciones.headers['Content-Type'] = 'application/json';
        opciones.body = JSON.stringify(datos);
    }
    let r;
    try {
        r = await fetch(`api.php?action=${accion}${extra}`, opciones);
    } catch (_) {
        throw new Error('No hay conexión con el servidor. Verifique que Apache esté encendido.');
    }
    if (r.status === 401) { location.href = 'login.php?expirada=1'; throw new Error('Sesión expirada'); }
    let j;
    try { j = await r.json(); } catch (_) { throw new Error('El servidor devolvió una respuesta inesperada. Revise el registro de errores de PHP.'); }
    if (j.status !== 'success') throw new Error(j.message || 'Ocurrió un error.');
    return j.data;
}

const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const fmtQ = n => 'Q ' + Number(n || 0).toLocaleString('es-GT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
function fmtFecha(f) {
    if (!f) return '—';
    const [a, m, d] = String(f).slice(0, 10).split('-');
    return `${parseInt(d, 10)} ${MESES[parseInt(m, 10) - 1]} ${a}`;
}
function fmtFechaHora(f) {
    if (!f) return '—';
    return `${fmtFecha(f)}, ${String(f).slice(11, 16)}`;
}
const hoy = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const PARTICULAS = ['de', 'del', 'la', 'las', 'los', 'y', 'dra.', 'dr.', 'lic.', 'ing.'];
const primeraLetra = t => (String(t || '').split(/\s+/).find(w => w && !PARTICULAS.includes(w.toLowerCase())) || '')[0] || '';
const iniciales = (n, a = '') => (primeraLetra(n) + primeraLetra(a)).toUpperCase();
const nombreRol = r => r;
const TEXTO_ALERTA = { Normal: 'Normal', Atencion: 'Atención', Urgente: 'Urgente' };
const TEXTO_ESTADO = { activo: 'Activo', inactivo: 'De baja', hospitalizado: 'Hospitalizado', disponible: 'Disponible', ocupada: 'Llena', mantenimiento: 'Mantenimiento' };
const GENERO = { M: 'Masculino', F: 'Femenino', Otro: 'Otro' };

function etiqueta(clave, texto) {
    const c = String(clave || 'neutro').toLowerCase();
    return `<span class="etiqueta e-${esc(c)}">${esc(texto ?? clave)}</span>`;
}
const etiquetaAlerta = n => n ? etiqueta(n, TEXTO_ALERTA[n] || n) : '<span class="text-secondary">Sin evaluar</span>';
const etiquetaEstado = e => etiqueta(e, TEXTO_ESTADO[e] || e);

function aviso(mensaje, tipo = 'ok') {
    const t = document.createElement('div');
    t.className = `toast ${tipo}`;
    t.setAttribute('role', tipo === 'error' ? 'alert' : 'status');
    const icono = { ok: 'check-circle-fill', error: 'exclamation-octagon-fill', aviso: 'info-circle-fill' }[tipo];
    t.innerHTML = `<div class="d-flex align-items-start gap-2 p-3"><i class="bi bi-${icono} mt-1"></i><div class="flex-grow-1">${esc(mensaje)}</div>
        <button type="button" class="btn-close ${tipo === 'aviso' ? '' : 'btn-close-white'}" data-bs-dismiss="toast" aria-label="Cerrar"></button></div>`;
    $('#avisos').appendChild(t);
    const b = new bootstrap.Toast(t, { delay: tipo === 'error' ? 7000 : 4500 });
    t.addEventListener('hidden.bs.toast', () => t.remove());
    b.show();
}

const modalConfirmar = new bootstrap.Modal('#modalConfirmar');
function confirmar(titulo, texto, boton = 'Confirmar', tipo = 'danger') {
    return new Promise(resolve => {
        $('#confTitulo').textContent = titulo;
        $('#confTexto').textContent = texto;
        const b = $('#confBoton');
        b.textContent = boton;
        b.className = `btn btn-${tipo}`;
        let ok = false;
        b.onclick = () => { ok = true; modalConfirmar.hide(); };
        $('#modalConfirmar').addEventListener('hidden.bs.modal', () => resolve(ok), { once: true });
        modalConfirmar.show();
    });
}

/* Modal de formulario reutilizable */
const modalForm = new bootstrap.Modal('#modalForm');
let alEnviarForm = null;
function abrirForm({ titulo, cuerpo, boton = 'Guardar', alEnviar, extraPie = '' }) {
    $('#modalFormTitulo').textContent = titulo;
    $('#modalFormCuerpo').innerHTML = cuerpo;
    $('#modalFormBoton').textContent = boton;
    $('#modalFormError').innerHTML = extraPie;
    $('#modalFormEl').classList.remove('was-validated');
    alEnviarForm = alEnviar;
    modalForm.show();
    setTimeout(() => $('#modalFormCuerpo input:not([type=hidden]), #modalFormCuerpo select')?.focus(), 350);
}
function cerrarForm() { modalForm.hide(); }

$('#modalFormEl').addEventListener('submit', async e => {
    e.preventDefault();
    const form = e.currentTarget;
    const err = $('#modalFormError');
    if (!form.checkValidity()) {
        form.classList.add('was-validated');
        err.textContent = 'Revise los campos marcados.';
        form.querySelector(':invalid')?.focus();
        return;
    }
    const boton = $('#modalFormBoton');
    const texto = boton.textContent;
    boton.disabled = true;
    boton.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Guardando…';
    err.textContent = '';
    try {
        await alEnviarForm(datosForm(form), form);
    } catch (ex) {
        err.textContent = ex.message;
    } finally {
        boton.disabled = false;
        boton.textContent = texto;
    }
});

function datosForm(form) {
    const d = {};
    for (const el of form.elements) {
        if (!el.name || el.disabled) continue;
        if (el.name.endsWith('[]')) {
            const k = el.name.slice(0, -2);
            d[k] = d[k] || [];
            if (el.checked) d[k].push(el.value);
        } else if (el.type === 'checkbox') {
            d[el.name] = el.checked;
        } else if (el.type === 'radio') {
            if (el.checked) d[el.name] = el.value;
        } else {
            d[el.name] = el.value.trim();
        }
    }
    return d;
}

function campo({ nombre, etiqueta: et, tipo = 'text', valor = '', req = false, col = 'col-md-6', attrs = '', ayuda = '' }) {
    return `<div class="${col}"><label class="form-label ${req ? 'requerido' : ''}" for="f_${nombre}">${et}</label>
        <input type="${tipo}" class="form-control" id="f_${nombre}" name="${nombre}" value="${esc(valor)}" ${req ? 'required' : ''} ${attrs}>
        ${ayuda ? `<div class="form-text">${ayuda}</div>` : ''}</div>`;
}
function selector({ nombre, etiqueta: et, opciones, valor = '', req = false, col = 'col-md-6', vacio = 'Seleccione…', ayuda = '' }) {
    const ops = opciones.map(o => `<option value="${esc(o.v)}" ${String(o.v) === String(valor) ? 'selected' : ''}>${esc(o.t)}</option>`).join('');
    return `<div class="${col}"><label class="form-label ${req ? 'requerido' : ''}" for="f_${nombre}">${et}</label>
        <select class="form-select" id="f_${nombre}" name="${nombre}" ${req ? 'required' : ''}>${vacio !== null ? `<option value="">${vacio}</option>` : ''}${ops}</select>
        ${ayuda ? `<div class="form-text">${ayuda}</div>` : ''}</div>`;
}

function vacio(icono, texto, accion = '') {
    return `<div class="vacio"><i class="bi bi-${icono}"></i><p class="mb-2">${texto}</p>${accion}</div>`;
}
function errorVista(ex) {
    return `<div class="panel">${vacio('exclamation-triangle', esc(ex.message), '<button class="btn btn-outline-primary btn-sm" onclick="navegar()">Intentar de nuevo</button>')}</div>`;
}

/* ---------------- Navegación ---------------- */
const VISTAS = {
    inicio:          { titulo: 'Inicio', icono: 'house', grupo: null, roles: TODOS, render: vistaInicio },
    residentes:      { titulo: 'Residentes', icono: 'people', grupo: 'Residentes', roles: TODOS, render: vistaResidentes },
    habitaciones:    { titulo: 'Habitaciones', icono: 'door-open', grupo: 'Residentes', roles: [1], render: vistaHabitaciones },
    familiares:      { titulo: 'Familiares', icono: 'person-vcard', grupo: 'Residentes', roles: [1], render: vistaFamiliares },
    evaluaciones:    { titulo: 'Evaluaciones médicas', menu: 'Evaluaciones', icono: 'heart-pulse', grupo: 'Atención médica', roles: [1, 2], render: vistaEvaluaciones },
    solicitudes:     { titulo: 'Solicitudes de atención', menu: 'Solicitudes', icono: 'send', grupo: 'Atención médica', roles: [1, 2, 5], render: (c, p) => vistaSolicitudes(c, p) },
    agenda:          { titulo: 'Mi agenda', icono: 'calendar-check', grupo: 'Atención médica', roles: [6], render: (c, p) => vistaAgenda(c, p) },
    acompanamientos: { titulo: 'Mis acompañamientos', menu: 'Acompañamientos', icono: 'person-walking', grupo: 'Atención médica', roles: [4], render: (c, p) => vistaAcompanamientos(c, p) },
    visita:          { titulo: 'Visita médica', oculto: true, roles: [1, 2, 6], render: (c, p) => vistaVisita(c, p) },
    laboratorio:     { titulo: 'Laboratorio', icono: 'eyedropper', grupo: 'Servicios clínicos', roles: [1, 7], render: (c, p) => vistaLaboratorio(c, p) },
    examenes:        { titulo: 'Catálogo de exámenes', menu: 'Exámenes', icono: 'list-check', grupo: 'Servicios clínicos', roles: [1, 7], render: c => vistaExamenes(c) },
    farmacia:        { titulo: 'Farmacia: recetas por entregar', menu: 'Recetas', icono: 'capsule', grupo: 'Servicios clínicos', roles: [1, 8], render: c => vistaFarmacia(c) },
    inventario:      { titulo: 'Inventario de medicamentos', menu: 'Inventario', icono: 'box-seam', grupo: 'Servicios clínicos', roles: [1, 8], render: c => vistaInventario(c) },
    cotizador:       { titulo: 'Cotizador de estancia', menu: 'Cotizador', icono: 'calculator', grupo: 'Finanzas', roles: [1, 3], render: vistaCotizador },
    usuarios:        { titulo: 'Usuarios del sistema', menu: 'Usuarios', icono: 'person-gear', grupo: 'Administración', roles: [1], render: vistaUsuarios },
    configuracion:   { titulo: 'Configuración', icono: 'sliders', grupo: 'Administración', roles: [1], render: (c, p) => vistaConfiguracion(c, p) },
    bitacora:        { titulo: 'Bitácora de auditoría', menu: 'Bitácora', icono: 'journal-text', grupo: 'Administración', roles: [1], render: vistaBitacora },
};

function construirMenu() {
    let html = '', grupo;
    for (const [clave, v] of Object.entries(VISTAS)) {
        if (!v.roles.includes(U.id_rol) || v.oculto) continue;
        if (v.grupo && v.grupo !== grupo) { html += `<div class="sidebar-grupo">${v.grupo}</div>`; grupo = v.grupo; }
        html += `<a href="#${clave}" data-vista="${clave}"><i class="bi bi-${v.icono}"></i>${v.menu || v.titulo}</a>`;
    }
    $('#menu').innerHTML = html;
}

function parametros() {
    const [vista, q = ''] = location.hash.slice(1).split('?');
    return { vista: vista || 'inicio', params: new URLSearchParams(q) };
}

async function navegar() {
    let { vista, params } = parametros();
    if (!VISTAS[vista] || !VISTAS[vista].roles.includes(U.id_rol)) vista = 'inicio';
    const v = VISTAS[vista];
    const enMenu = vista === 'visita' ? (puede(ROL.ESPECIALISTA) ? 'agenda' : 'solicitudes') : vista;
    $$('#menu a').forEach(a => a.classList.toggle('activo', a.dataset.vista === enMenu));
    $('#tituloVista').textContent = v.titulo;
    $('#accionesVista').innerHTML = '';
    document.title = `${v.titulo} | Asilo Cabeza de Algodón`;
    const c = $('#contenido');
    c.innerHTML = '<div class="cargando"><div class="spinner-border text-success" role="status"><span class="visually-hidden">Cargando…</span></div></div>';
    bootstrap.Offcanvas.getInstance('#sidebar')?.hide();
    try {
        await v.render(c, params);
    } catch (ex) {
        c.innerHTML = errorVista(ex);
    }
}
function recargarVista() { return navegar(); }

$('#btnSalir').addEventListener('click', async () => {
    try { await api('logout', {}); } catch (_) { /* se redirige igual */ }
    location.href = 'login.php';
});

/* ================================================================
   INICIO
   ================================================================ */
async function vistaInicio(c) {
    const d = await api('dashboard');
    const h = new Date().getHours();
    const saludo = h < 12 ? 'Buenos días' : h < 19 ? 'Buenas tardes' : 'Buenas noches';
    const nombre = U.nombre.replace(/^(Dra?\.|Lic\.|Ing\.)\s*/, '').split(' ')[0];
    const fecha = new Date().toLocaleDateString('es-GT', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    const pct = d.camas.total ? Math.round(d.camas.ocupadas / d.camas.total * 100) : 0;
    const urgentes = d.alertas.filter(a => a.nivel_alerta === 'Urgente').length;

    if (puede(ROL.ADMIN)) {
        $('#accionesVista').innerHTML = `<button class="btn btn-primary" id="btnNuevoRes"><i class="bi bi-person-plus me-1"></i> Registrar residente</button>`;
        $('#btnNuevoRes').onclick = () => formResidente();
    }

    c.innerHTML = `
    <div class="saludo"><h2>${saludo}, ${esc(nombre)}</h2><p class="text-secondary mb-0 text-capitalize-first">${esc(fecha.charAt(0).toUpperCase() + fecha.slice(1))}</p></div>
    <div class="cifras">
        <div><div class="cifra">${d.residentes.activo + d.residentes.hospitalizado}</div><p>residentes en el asilo${d.residentes.hospitalizado ? `, ${d.residentes.hospitalizado} hospitalizado(s)` : ''}</p></div>
        <div><div class="cifra">${d.camas.ocupadas}<small> de ${d.camas.total}</small></div><p>camas ocupadas (${pct}%)</p></div>
        <div><div class="cifra">${d.alertas.length}</div><p>${urgentes ? `residentes con alerta, ${urgentes} urgente(s)` : 'residentes con alerta clínica'}</p></div>
        ${puede(ROL.LABORATORIO) ? `<div><a class="cifra-enlace" href="#laboratorio"><div class="cifra">${d.laboratorio_pendientes}</div><p>exámenes esperan resultado</p></a></div>`
            : puede(ROL.FARMACIA) ? `<div><a class="cifra-enlace" href="#farmacia"><div class="cifra">${d.farmacia_pendientes}</div><p>medicamentos por entregar</p></a></div>`
            : puede(ROL.ENFERMERO, ROL.ESPECIALISTA)
            ? `<div><div class="cifra">${d.proximas_citas.length}</div><p>${puede(ROL.ESPECIALISTA) ? 'citas próximas en su agenda' : 'acompañamientos próximos'}</p></div>`
            : `<div><div class="cifra">${d.solicitudes.Pendiente}</div><p>solicitudes esperan horario de la fundación</p></div>`}
    </div>
    <div class="row g-4">
        <div class="col-xl-8">
            <section class="panel">
                <div class="panel-cab"><h2>Habitaciones</h2>
                    <div class="leyenda ms-auto"><span><i class="cama cama-ocupada"></i>Ocupada</span><span><i class="cama cama-hospital"></i>Hospitalizado</span><span><i class="cama cama-libre"></i>Libre</span></div>
                </div>
                <div class="panel-cuerpo">${mapaHabitaciones(d.habitaciones, puede(ROL.ADMIN))}</div>
            </section>
        </div>
        <div class="col-xl-4 d-flex flex-column gap-4">
            ${puede(ROL.ADMIN, ROL.FARMACIA) && d.stock_bajo.length ? `<section class="panel">
                <div class="panel-cab"><h2>Medicamentos por agotarse</h2><a class="ms-auto small" href="#inventario">Inventario</a></div>
                <ul class="lista-simple panel-cuerpo">${d.stock_bajo.map(m => `<li class="d-flex justify-content-between gap-2"><span>${esc(m.nombre_comercial)}</span>${etiqueta('urgente', `${m.stock} de ${m.stock_minimo}`)}</li>`).join('')}</ul>
            </section>` : ''}
            ${puede(ROL.ADMIN) && (d.laboratorio_pendientes || d.farmacia_pendientes) ? `<section class="panel">
                <div class="panel-cab"><h2>Servicios clínicos</h2></div>
                <div class="panel-cuerpo servicios">
                    <div><i class="bi bi-eyedropper me-2"></i><a href="#laboratorio">${d.laboratorio_pendientes} examen(es) esperan resultado</a></div>
                    <div><i class="bi bi-capsule me-2"></i><a href="#farmacia">${d.farmacia_pendientes} medicamento(s) por entregar</a></div>
                </div></section>` : ''}
            <section class="panel">
                <div class="panel-cab"><h2>Requieren atención</h2></div>
                <div>${d.alertas.length ? d.alertas.map(itemAlerta).join('') : vacio('emoji-smile', 'Ninguna evaluación reciente presenta alertas.')}</div>
            </section>
            <section class="panel">
                <div class="panel-cab"><h2>Próximas citas</h2>${puede(ROL.ADMIN, ROL.MEDICO, ROL.FUNDACION) ? '<a class="ms-auto small" href="#solicitudes?estado=Programada">Ver todas</a>' : ''}</div>
                <div>${d.proximas_citas.length ? d.proximas_citas.map(itemCita).join('') : vacio('calendar', 'No hay citas programadas.')}</div>
            </section>
            <section class="panel">
                <div class="panel-cab"><h2>Servicios de cálculo</h2></div>
                <div class="panel-cuerpo servicios">
                    <div><span class="punto ${d.microservicios.ms_imc ? 'ok' : 'no'}"></span>Cálculo de IMC: ${d.microservicios.ms_imc ? 'en línea' : 'sin respuesta, se usa el cálculo de respaldo'}</div>
                    <div><span class="punto ${d.microservicios.ms_cotizador ? 'ok' : 'no'}"></span>Cotizador: ${d.microservicios.ms_cotizador ? 'en línea' : 'sin respuesta, se usa el cálculo de respaldo'}</div>
                </div>
            </section>
        </div>
    </div>`;
    activarMapa(c);
    $$('.alerta-item', c).forEach(b => b.addEventListener('click', () => abrirExpediente(+b.dataset.id)));
}

function itemAlerta(a) {
    const clase = a.nivel_alerta === 'Urgente' ? 'urgente' : 'atencion';
    const signos = [];
    if (a.presion_sistolica) signos.push(`PA ${a.presion_sistolica}/${a.presion_diastolica}`);
    if (a.glucosa) signos.push(`glucosa ${Math.round(a.glucosa)}`);
    if (a.imc) signos.push(`IMC ${a.imc}`);
    const tag = puede(ROL.ADMIN, ROL.MEDICO, ROL.ENFERMERO, ROL.ESPECIALISTA) ? 'button' : 'div';
    return `<${tag} class="alerta-item ${clase}" data-id="${a.id_residente}" ${tag === 'button' ? 'type="button"' : ''}>
        <span class="avatar">${esc(iniciales(a.nombre, a.apellido))}</span>
        <div class="flex-grow-1 min-w-0"><div class="d-flex justify-content-between gap-2"><strong>${esc(a.nombre)} ${esc(a.apellido)}</strong>${etiquetaAlerta(a.nivel_alerta)}</div>
        <p>${a.numero_habitacion ? `Hab. ${esc(a.numero_habitacion)}, ` : ''}${esc(signos.join(', '))}</p>
        <p>Evaluado el ${fmtFecha(a.fecha_evaluacion)}</p></div></${tag}>`;
}

function mapaHabitaciones(habs, editable, conNombres = false) {
    if (!habs.length) return vacio('door-closed', 'Aún no hay habitaciones registradas.');
    const pisos = {};
    habs.forEach(h => (pisos[h.piso] = pisos[h.piso] || []).push(h));
    return Object.entries(pisos).map(([piso, lista]) => `
        <div class="mapa-piso"><h3>${piso === '0' ? 'Planta baja' : `Piso ${piso}`}</h3><div class="mapa">
        ${lista.map(h => {
            const mant = h.estado === 'mantenimiento';
            const camas = [];
            for (let i = 0; i < h.capacidad; i++) {
                const r = h.residentes[i];
                if (r) camas.push(`<span class="cama ${r.estado === 'hospitalizado' ? 'cama-hospital' : 'cama-ocupada'}" title="${esc(r.nombre + ' ' + r.apellido)}">${esc(iniciales(r.nombre, r.apellido))}</span>`);
                else camas.push(`<span class="cama ${mant ? '' : 'cama-libre'}" title="${mant ? 'Fuera de servicio' : 'Cama libre'}"></span>`);
            }
            const libres = h.capacidad - h.ocupantes;
            const estado = mant ? 'Cerrada' : libres === 0 ? 'Llena' : `${libres} libre${libres > 1 ? 's' : ''}`;
            const tag = editable ? 'button' : 'div';
            const nombres = conNombres && h.residentes.length
                ? `<div class="small mt-2">${h.residentes.map(r => esc(r.nombre + ' ' + r.apellido)).join('<br>')}</div>` : '';
            return `<${tag} class="cuarto ${mant ? 'mant' : libres === 0 ? 'lleno' : ''}" data-hab="${h.id_habitacion}" ${editable ? 'type="button"' : ''}
                    aria-label="Habitación ${esc(h.numero_habitacion)}, ${esc(h.tipo)}, ${estado}">
                <span class="cuarto-estado">${estado}</span>
                <div class="cuarto-num">${esc(h.numero_habitacion)}</div>
                <div class="cuarto-tipo">${esc(h.tipo)}${Number(h.costo_adicional) > 0 ? `, +${fmtQ(h.costo_adicional)}` : ''}</div>
                <div class="camas">${camas.join('')}</div>${nombres}
            </${tag}>`;
        }).join('')}</div></div>`).join('');
}
function activarMapa(c, habs) {
    $$('button.cuarto', c).forEach(b => b.addEventListener('click', async () => {
        const lista = habs || await api('habitaciones');
        formHabitacion(lista.find(h => h.id_habitacion == b.dataset.hab));
    }));
}

/* ================================================================
   RESIDENTES
   ================================================================ */
let filtroResidentes = { estado: 'activo', q: '' };

async function vistaResidentes(c) {
    if (puede(ROL.ADMIN)) {
        $('#accionesVista').innerHTML = `<button class="btn btn-primary" id="btnNuevoRes"><i class="bi bi-person-plus me-1"></i> Registrar residente</button>`;
        $('#btnNuevoRes').onclick = () => formResidente();
    }
    const lista = await api('residentes');
    const conteo = { activo: 0, hospitalizado: 0, inactivo: 0, '': lista.length };
    lista.forEach(r => conteo[r.estado]++);
    const filtrosEst = [['activo', 'Activos'], ['hospitalizado', 'Hospitalizados'], ['inactivo', 'De baja'], ['', 'Todos']];

    c.innerHTML = `<section class="panel">
        <div class="panel-cab">
            <div class="filtros w-100">
                <input type="search" class="form-control" id="buscarRes" placeholder="Buscar por nombre, DPI o habitación" aria-label="Buscar residente" value="${esc(filtroResidentes.q)}">
                <div class="btn-group" role="group" aria-label="Filtrar por estado">
                ${filtrosEst.map(([v, t]) => `<input type="radio" class="btn-check" name="estRes" id="est_${v || 'todos'}" value="${v}" ${filtroResidentes.estado === v ? 'checked' : ''}>
                    <label class="btn btn-outline-secondary btn-sm" for="est_${v || 'todos'}">${t} <span class="opacity-75">${conteo[v]}</span></label>`).join('')}
                </div>
            </div>
        </div>
        <div class="table-responsive"><table class="table tabla">
            <thead><tr><th>Residente</th><th>Habitación</th><th>Familiar</th><th>Última evaluación</th><th>Estado</th></tr></thead>
            <tbody id="tbRes"></tbody></table></div>
    </section>`;

    const pintar = () => {
        const q = filtroResidentes.q.toLowerCase();
        const filas = lista.filter(r => (!filtroResidentes.estado || r.estado === filtroResidentes.estado) &&
            (!q || `${r.nombre} ${r.apellido} ${r.documento_id || ''} ${r.numero_habitacion || ''}`.toLowerCase().includes(q)));
        const clic = puede(ROL.ADMIN, ROL.MEDICO, ROL.ENFERMERO, ROL.ESPECIALISTA);
        $('#tbRes').innerHTML = filas.length ? filas.map(r => `
            <tr class="${clic ? 'clic' : ''}" data-id="${r.id_residente}" ${clic ? 'tabindex="0"' : ''}>
                <td><div class="persona"><span class="avatar">${esc(iniciales(r.nombre, r.apellido))}</span>
                    <div><strong>${esc(r.nombre)} ${esc(r.apellido)}</strong><small>${r.edad} años, ${esc(GENERO[r.genero])}</small></div></div></td>
                <td>${r.numero_habitacion ? `<strong>${esc(r.numero_habitacion)}</strong><br><small class="text-secondary">${esc(r.tipo_habitacion)}</small>` : '<span class="text-secondary">Sin asignar</span>'}</td>
                <td>${esc(r.familiar_nombre)}<br><small class="text-secondary">${esc(r.familiar_telefono)}</small></td>
                <td>${etiquetaAlerta(r.ultima_alerta)}</td>
                <td>${etiquetaEstado(r.estado)}</td>
            </tr>`).join('')
            : `<tr><td colspan="5">${vacio('search', lista.length ? 'Ningún residente coincide con la búsqueda.' : 'Todavía no hay residentes registrados.',
                !lista.length && puede(ROL.ADMIN) ? '<button class="btn btn-primary btn-sm" onclick="formResidente()">Registrar el primero</button>' : '')}</td></tr>`;
        if (clic) $$('#tbRes tr.clic').forEach(tr => {
            tr.addEventListener('click', () => abrirExpediente(+tr.dataset.id));
            tr.addEventListener('keydown', e => { if (e.key === 'Enter') abrirExpediente(+tr.dataset.id); });
        });
    };
    $('#buscarRes').addEventListener('input', e => { filtroResidentes.q = e.target.value; pintar(); });
    $$('input[name=estRes]').forEach(i => i.addEventListener('change', () => { filtroResidentes.estado = i.value; pintar(); }));
    pintar();
}

async function formResidente(r = null, preseleccion = {}) {
    let familiares, habitaciones, catalogo;
    try {
        [familiares, habitaciones, catalogo] = await Promise.all([
            api('familiares'),
            api('habitaciones_disponibles', null, r?.id_habitacion ? `&incluir=${r.id_habitacion}` : ''),
            api('cotizador_catalogo'),
        ]);
    } catch (ex) { aviso(ex.message, 'error'); return; }

    const v = { ...(r || { fecha_ingreso: hoy(), genero: '' }), ...preseleccion };
    const sinHab = !habitaciones.length;
    const cuerpo = `
        ${!familiares.length ? `<div class="ayuda-vacia mb-3">Para registrar un residente primero debe existir su familiar (responsable legal).</div>` : ''}
        ${sinHab ? `<div class="ayuda-vacia mb-3">No hay camas libres. Libere una habitación o registre una nueva antes de continuar.</div>` : ''}
        <input type="hidden" name="id_residente" value="${esc(v.id_residente || '')}">
        <h3 class="form-seccion">Datos personales</h3>
        <div class="row g-3">
            ${campo({ nombre: 'nombre', etiqueta: 'Nombres', valor: v.nombre, req: true, attrs: 'maxlength="100"' })}
            ${campo({ nombre: 'apellido', etiqueta: 'Apellidos', valor: v.apellido, req: true, attrs: 'maxlength="100"' })}
            ${campo({ nombre: 'documento_id', etiqueta: 'DPI', valor: v.documento_id, col: 'col-md-4', attrs: 'maxlength="20" inputmode="numeric"', ayuda: 'Opcional' })}
            ${campo({ nombre: 'fecha_nacimiento', etiqueta: 'Fecha de nacimiento', tipo: 'date', valor: v.fecha_nacimiento, req: true, col: 'col-md-4', attrs: `max="${hoy()}"` })}
            ${selector({ nombre: 'genero', etiqueta: 'Género', valor: v.genero, req: true, col: 'col-md-4', opciones: [{ v: 'F', t: 'Femenino' }, { v: 'M', t: 'Masculino' }, { v: 'Otro', t: 'Otro' }] })}
        </div>
        <h3 class="form-seccion">Ingreso y alojamiento</h3>
        <div class="row g-3">
            ${campo({ nombre: 'fecha_ingreso', etiqueta: 'Fecha de ingreso', tipo: 'date', valor: v.fecha_ingreso, req: true, col: 'col-md-4', attrs: `max="${hoy()}"` })}
            ${selector({ nombre: 'id_habitacion', etiqueta: 'Habitación', valor: v.id_habitacion, req: true, col: 'col-md-4',
                opciones: habitaciones.map(h => { const l = h.capacidad - h.ocupantes; return { v: h.id_habitacion, t: `${h.numero_habitacion}, ${h.tipo}${String(h.id_habitacion) === String(r?.id_habitacion) ? ' (actual)' : `, ${l} libre${l > 1 ? 's' : ''}`}` }; }) })}
            ${selector({ nombre: 'id_plan', etiqueta: 'Plan de servicio', valor: v.id_plan, col: 'col-md-4', vacio: 'Sin plan',
                opciones: catalogo.planes.map(p => ({ v: p.id_plan, t: `${p.nombre_plan} (${fmtQ(p.costo_base)})` })) })}
        </div>
        <div class="row g-3 mt-0">
            <div class="col-12"><label class="form-label requerido" for="f_motivo">Motivo de ingreso</label>
                <textarea class="form-control" id="f_motivo" name="motivo_ingreso" rows="2" maxlength="2000" required placeholder="Por qué fue recluido en el asilo">${esc(v.motivo_ingreso || '')}</textarea></div>
        </div>
        <h3 class="form-seccion">Familiar responsable</h3>
        <div class="row g-3 align-items-end">
            ${selector({ nombre: 'id_familiar', etiqueta: 'Familiar', valor: v.id_familiar, req: true, col: 'col-md-8', ayuda: 'Paga la cuota mensual y recibe los avisos por correo',
                opciones: familiares.map(a => ({ v: a.id_familiar, t: `${a.apellido}, ${a.nombre} (${a.parentesco})` })) })}
            <div class="col-md-4 mb-md-4"><button type="button" class="btn btn-outline-primary w-100" id="btnFamNuevo"><i class="bi bi-plus-lg me-1"></i> Nuevo familiar</button></div>
            <div class="col-12"><label class="form-label" for="f_obs">Observaciones</label>
                <textarea class="form-control" id="f_obs" name="observaciones" rows="2" maxlength="2000" placeholder="Condiciones conocidas, movilidad, dieta…">${esc(v.observaciones || '')}</textarea></div>
        </div>`;

    abrirForm({
        titulo: r ? `Editar expediente de ${r.nombre}` : 'Registrar residente',
        cuerpo,
        boton: r ? 'Guardar cambios' : 'Registrar residente',
        alEnviar: async d => {
            const res = await api('residente_guardar', d);
            cerrarForm();
            aviso(res.mensaje);
            bootstrap.Offcanvas.getInstance('#panelExpediente')?.hide();
            recargarVista();
        },
    });
    $('#btnFamNuevo').onclick = () => {
        const borrador = datosForm($('#modalFormEl'));
        cerrarForm();
        $('#modalForm').addEventListener('hidden.bs.modal', () => formFamiliar(null, nuevo => formResidente(r, { ...borrador, id_familiar: nuevo })), { once: true });
    };
}

/* Expediente (offcanvas) con pestañas: datos, ficha médica, evaluaciones */
const panelExp = new bootstrap.Offcanvas('#panelExpediente');
const COLOR_PADEC = { 'Activo': 'atencion', 'Controlado': 'normal', 'Resuelto': 'neutro' };
async function abrirExpediente(id, pestana = 'datos') {
    $('#expCuerpo').innerHTML = '<div class="cargando"><div class="spinner-border text-success"></div></div>';
    panelExp.show();
    let r;
    try { r = await api('residente_expediente', null, `&id_residente=${id}`); }
    catch (ex) { $('#expCuerpo').innerHTML = vacio('exclamation-triangle', esc(ex.message)); return; }

    $('#expTitulo').textContent = 'Expediente';
    const editaFicha = puede(ROL.ADMIN, ROL.MEDICO);
    const acciones = [];
    if (r.estado !== 'inactivo' && editaFicha) acciones.push(`<a class="btn btn-primary btn-sm" href="#evaluaciones?r=${r.id_residente}" id="expEval"><i class="bi bi-heart-pulse me-1"></i>Nueva evaluación</a>`);
    if (r.estado !== 'inactivo' && editaFicha) acciones.push(`<button class="btn btn-outline-primary btn-sm" id="expRemitir"><i class="bi bi-send me-1"></i>Remitir a especialista</button>`);
    if (puede(ROL.ADMIN)) acciones.push(`<button class="btn btn-outline-primary btn-sm" id="expEditar"><i class="bi bi-pencil me-1"></i>Editar datos</button>`);
    const estados = [];
    if (editaFicha) {
        if (r.estado === 'activo') estados.push(['hospitalizado', 'Marcar hospitalizado']);
        if (r.estado === 'hospitalizado') estados.push(['activo', 'Marcar de regreso (activo)']);
        if (r.estado !== 'inactivo' && puede(ROL.ADMIN)) estados.push(['inactivo', 'Dar de baja']);
    }
    if (estados.length) acciones.push(`<div class="dropdown"><button class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">Cambiar estado</button>
        <ul class="dropdown-menu">${estados.map(([e, t]) => `<li><button class="dropdown-item ${e === 'inactivo' ? 'text-danger' : ''}" data-estado="${e}">${t}</button></li>`).join('')}</ul></div>`);

    const evals = r.evaluaciones, padec = r.padecimientos, perm = r.medicamentos_permanentes;
    const activos = padec.filter(p => p.estado !== 'Resuelto').length;
    const permActivos = perm.filter(m => +m.activo).length;
    const alergias = padec.filter(p => p.tipo === 'Alergia' && p.estado !== 'Resuelto');
    const tab = (clave, texto, n) => `<li class="nav-item" role="presentation"><button class="nav-link ${pestana === clave ? 'active' : ''}" data-bs-toggle="tab" data-bs-target="#exp_${clave}" type="button" role="tab">${texto}${n !== undefined ? ` <span class="badge text-bg-light">${n}</span>` : ''}</button></li>`;

    $('#expCuerpo').innerHTML = `
        <div class="exp-cab"><span class="avatar avatar-grande">${esc(iniciales(r.nombre, r.apellido))}</span>
            <div><h3>${esc(r.nombre)} ${esc(r.apellido)}</h3><div class="text-secondary">${r.edad} años, ${esc(GENERO[r.genero])}${r.numero_habitacion ? `, habitación ${esc(r.numero_habitacion)}` : ''}</div><div class="mt-1">${etiquetaEstado(r.estado)}</div></div></div>
        ${alergias.length ? `<div class="aviso-alergia"><i class="bi bi-exclamation-triangle-fill"></i> Alergia: <strong>${alergias.map(a => esc(a.descripcion)).join(', ')}</strong></div>` : ''}
        <div class="d-flex flex-wrap gap-2 mb-3">${acciones.join('')}</div>
        <ul class="nav nav-tabs exp-tabs" role="tablist">${tab('datos', 'Datos')}${tab('ficha', 'Ficha médica', activos + permActivos)}${tab('visitas', 'Visitas', r.visitas.length)}${tab('evaluaciones', 'Evaluaciones', evals.length)}</ul>
        <div class="tab-content pt-3">
          <div class="tab-pane fade ${pestana === 'datos' ? 'show active' : ''}" id="exp_datos" role="tabpanel">
            <dl class="datos">
                <div><dt>DPI</dt><dd>${esc(r.documento_id || '—')}</dd></div>
                <div><dt>Fecha de nacimiento</dt><dd>${fmtFecha(r.fecha_nacimiento)}</dd></div>
                <div><dt>Ingreso</dt><dd>${fmtFecha(r.fecha_ingreso)}</dd></div>
                <div><dt>Habitación</dt><dd>${r.numero_habitacion ? `${esc(r.numero_habitacion)}, ${esc(r.tipo_habitacion)}` : 'Sin asignar'}</dd></div>
                <div style="grid-column:1/-1"><dt>Motivo de ingreso</dt><dd class="fw-normal">${esc(r.motivo_ingreso || '—')}</dd></div>
                <div><dt>Plan de servicio</dt><dd>${r.nombre_plan ? `${esc(r.nombre_plan)} (${fmtQ(r.costo_base)})` : 'Sin plan'}</dd></div>
                <div><dt>Familiar responsable</dt><dd>${esc(r.familiar_nombre)} ${esc(r.familiar_apellido)}<br><span class="fw-normal text-secondary">${esc(r.familiar_parentesco)}, ${esc(r.familiar_telefono)}${r.familiar_email ? `<br>${esc(r.familiar_email)}` : ''}</span></dd></div>
                ${r.observaciones ? `<div style="grid-column:1/-1"><dt>Observaciones</dt><dd class="fw-normal">${esc(r.observaciones)}</dd></div>` : ''}
            </dl>
          </div>
          <div class="tab-pane fade ${pestana === 'ficha' ? 'show active' : ''}" id="exp_ficha" role="tabpanel">
            <div class="d-flex align-items-center mb-2"><h4 class="h6 mb-0">Padecimientos y psicopatologías</h4>
                ${editaFicha ? '<button class="btn btn-link btn-sm ms-auto" id="btnNuevoPadec"><i class="bi bi-plus-lg"></i> Agregar</button>' : ''}</div>
            ${padec.length ? `<ul class="lista-ficha">${padec.map(p => `<li class="${p.estado === 'Resuelto' ? 'resuelto' : ''}">
                <div class="flex-grow-1 min-w-0"><div><strong>${esc(p.descripcion)}</strong> <span class="text-secondary small">${esc(p.tipo)}</span></div>
                <div class="small text-secondary">${p.fecha_diagnostico ? `Diagnosticado el ${fmtFecha(p.fecha_diagnostico)}` : 'Sin fecha de diagnóstico'}${p.notas ? `. ${esc(p.notas)}` : ''}</div></div>
                ${etiqueta(COLOR_PADEC[p.estado], p.estado)}
                ${editaFicha ? `<button class="btn btn-icono btn-sm" data-padec="${p.id_padecimiento}" aria-label="Editar ${esc(p.descripcion)}"><i class="bi bi-pencil"></i></button>` : ''}</li>`).join('')}</ul>`
                : vacio('clipboard2', 'Sin padecimientos registrados.')}
            <div class="d-flex align-items-center mb-2 mt-4"><h4 class="h6 mb-0">Medicamentos permanentes</h4>
                ${editaFicha ? '<button class="btn btn-link btn-sm ms-auto" id="btnNuevoPerm"><i class="bi bi-plus-lg"></i> Agregar</button>' : ''}</div>
            ${perm.length ? `<ul class="lista-ficha">${perm.map(m => `<li class="${+m.activo ? '' : 'resuelto'}">
                <div class="flex-grow-1 min-w-0"><div><strong>${esc(m.nombre_comercial)}</strong></div>
                <div class="small">${esc(m.dosis)}, ${esc(m.frecuencia).toLowerCase()}${m.indicaciones ? `. ${esc(m.indicaciones)}` : ''}</div>
                <div class="small text-secondary">Desde el ${fmtFecha(m.fecha_inicio)}</div></div>
                ${+m.activo ? etiqueta('normal', 'Vigente') : etiqueta('neutro', 'Suspendido')}
                ${editaFicha ? `<button class="btn btn-icono btn-sm" data-perm="${m.id_permanente}" aria-label="Editar ${esc(m.nombre_comercial)}"><i class="bi bi-pencil"></i></button>` : ''}</li>`).join('')}</ul>`
                : vacio('capsule', 'Sin medicamentos permanentes.')}
          </div>
          <div class="tab-pane fade ${pestana === 'visitas' ? 'show active' : ''}" id="exp_visitas" role="tabpanel">
            ${r.solicitudes_abiertas.length ? `<div class="mb-3"><h4 class="h6">En proceso</h4>${r.solicitudes_abiertas.map(x => `<div class="solicitud-abierta">${etiquetaSolicitud(x.estado)} <strong>${esc(x.especialidad)}</strong>
                <span class="text-secondary">${x.estado === 'Programada' ? `el ${fmtFechaHora(x.fecha_programada)} con ${esc(x.medico_asignado)}` : `solicitada el ${fmtFecha(x.fecha_solicitud)}`}</span></div>`).join('')}</div>` : ''}
            ${r.visitas.length ? `<ul class="linea-tiempo">${r.visitas.map(itemVisita).join('')}</ul>` : vacio('hospital', 'Aún no tiene visitas médicas con especialistas.')}
          </div>
          <div class="tab-pane fade ${pestana === 'evaluaciones' ? 'show active' : ''}" id="exp_evaluaciones" role="tabpanel">
            ${evals.length ? `<ul class="linea-tiempo">${evals.map(itemEvaluacion).join('')}</ul>` : vacio('clipboard2-pulse', 'Aún no tiene evaluaciones médicas.')}
          </div>
        </div>`;

    $('#expEval')?.addEventListener('click', () => panelExp.hide());
    $('#expEditar')?.addEventListener('click', () => formResidente(r));
    $('#expRemitir')?.addEventListener('click', () => formSolicitud(r.id_residente));
    $('#btnNuevoPadec')?.addEventListener('click', () => formPadecimiento(r));
    $('#btnNuevoPerm')?.addEventListener('click', () => formPermanente(r));
    $$('[data-padec]', $('#expCuerpo')).forEach(b => b.addEventListener('click', () => formPadecimiento(r, padec.find(p => p.id_padecimiento == b.dataset.padec))));
    $$('[data-perm]', $('#expCuerpo')).forEach(b => b.addEventListener('click', () => formPermanente(r, perm.find(m => m.id_permanente == b.dataset.perm))));
    $$('[data-estado]', $('#expCuerpo')).forEach(b => b.addEventListener('click', async () => {
        const e = b.dataset.estado;
        if (e === 'inactivo' && !await confirmar(`¿Dar de baja a ${r.nombre} ${r.apellido}?`, 'Su cama quedará libre y el expediente se conservará como histórico.', 'Dar de baja')) return;
        try {
            const res = await api('residente_estado', { id_residente: r.id_residente, estado: e });
            aviso(res.mensaje);
            abrirExpediente(r.id_residente);
            recargarVista();
        } catch (ex) { aviso(ex.message, 'error'); }
    }));
}

function formPadecimiento(r, p = null) {
    const v = p || { estado: 'Activo', tipo: '' };
    abrirForm({
        titulo: p ? 'Editar padecimiento' : `Agregar a la ficha de ${r.nombre}`,
        boton: p ? 'Guardar cambios' : 'Agregar a la ficha',
        cuerpo: `<input type="hidden" name="id_padecimiento" value="${esc(v.id_padecimiento || '')}"><input type="hidden" name="id_residente" value="${r.id_residente}">
        <div class="row g-3">
            ${selector({ nombre: 'tipo', etiqueta: 'Tipo', valor: v.tipo, req: true, opciones: ['Psicopatología', 'Enfermedad crónica', 'Alergia', 'Discapacidad', 'Otro'].map(t => ({ v: t, t })) })}
            ${selector({ nombre: 'estado', etiqueta: 'Estado', valor: v.estado, req: true, vacio: null, opciones: ['Activo', 'Controlado', 'Resuelto'].map(t => ({ v: t, t })) })}
            ${campo({ nombre: 'descripcion', etiqueta: 'Descripción', valor: v.descripcion, req: true, col: 'col-md-8', attrs: 'maxlength="255" placeholder="Ej.: Demencia tipo Alzheimer, hipertensión, penicilina"' })}
            ${campo({ nombre: 'fecha_diagnostico', etiqueta: 'Fecha de diagnóstico', tipo: 'date', valor: v.fecha_diagnostico, col: 'col-md-4', attrs: `max="${hoy()}"` })}
            <div class="col-12"><label class="form-label" for="f_notas">Notas</label><textarea class="form-control" id="f_notas" name="notas" rows="2" maxlength="2000">${esc(v.notas || '')}</textarea></div>
        </div>`,
        extraPie: p ? '<button type="button" class="btn btn-link text-danger p-0" id="btnElimPadec">Quitar de la ficha</button>' : '',
        alEnviar: async d => {
            const res = await api('padecimiento_guardar', d);
            cerrarForm(); aviso(res.mensaje); abrirExpediente(r.id_residente, 'ficha'); recargarVista();
        },
    });
    $('#btnElimPadec')?.addEventListener('click', async () => {
        cerrarForm();
        if (!await confirmar(`¿Quitar «${p.descripcion}» de la ficha?`, 'Si el padecimiento ya no está presente, es mejor marcarlo como «Resuelto» para conservar el historial.', 'Quitar')) return;
        try { const res = await api('padecimiento_eliminar', { id_padecimiento: p.id_padecimiento }); aviso(res.mensaje); abrirExpediente(r.id_residente, 'ficha'); }
        catch (ex) { aviso(ex.message, 'error'); }
    });
}

let cacheMedicamentos = null;
async function formPermanente(r, m = null) {
    try { cacheMedicamentos = cacheMedicamentos || await api('medicamentos_catalogo'); }
    catch (ex) { aviso(ex.message, 'error'); return; }
    const v = m || { fecha_inicio: hoy(), activo: 1 };
    abrirForm({
        titulo: m ? `Editar ${m.nombre_comercial}` : `Medicamento permanente para ${r.nombre}`,
        boton: m ? 'Guardar cambios' : 'Agregar a la ficha',
        cuerpo: `<input type="hidden" name="id_permanente" value="${esc(v.id_permanente || '')}"><input type="hidden" name="id_residente" value="${r.id_residente}">
        <div class="row g-3">
            ${selector({ nombre: 'id_medicamento', etiqueta: 'Medicamento', valor: v.id_medicamento, req: true, col: 'col-12',
                opciones: cacheMedicamentos.map(x => ({ v: x.id_medicamento, t: `${x.nombre_comercial} (${x.presentacion})` })) })}
            ${campo({ nombre: 'dosis', etiqueta: 'Dosis', valor: v.dosis, req: true, col: 'col-md-4', attrs: 'maxlength="100" placeholder="1 tableta"' })}
            ${campo({ nombre: 'frecuencia', etiqueta: 'Frecuencia', valor: v.frecuencia, req: true, col: 'col-md-4', attrs: 'maxlength="100" list="frecuencias" placeholder="Cada 12 horas"' })}
            <datalist id="frecuencias"><option>Cada 8 horas</option><option>Cada 12 horas</option><option>Cada 24 horas</option><option>Una vez por semana</option><option>Solo si hay dolor</option></datalist>
            ${campo({ nombre: 'fecha_inicio', etiqueta: 'Desde', tipo: 'date', valor: v.fecha_inicio, col: 'col-md-4', attrs: `max="${hoy()}"` })}
            ${campo({ nombre: 'indicaciones', etiqueta: 'Indicaciones', valor: v.indicaciones, col: 'col-12', attrs: 'maxlength="500" placeholder="Con el desayuno, con agua…"' })}
            <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="f_activo" name="activo" ${+v.activo ? 'checked' : ''}>
                <label class="form-check-label" for="f_activo">Vigente (desactívelo para suspender el tratamiento sin perder el historial)</label></div></div>
        </div>`,
        alEnviar: async d => {
            const res = await api('permanente_guardar', d);
            cerrarForm(); aviso(res.mensaje); abrirExpediente(r.id_residente, 'ficha');
        },
    });
}

function itemEvaluacion(e) {
    const s = [];
    s.push(`<span>IMC <b>${e.imc ?? '—'}</b></span>`);
    s.push(`<span>Peso <b>${Number(e.peso_kg)} kg</b></span>`);
    if (e.presion_sistolica) s.push(`<span>PA <b>${e.presion_sistolica}/${e.presion_diastolica}</b></span>`);
    if (e.glucosa) s.push(`<span>Glucosa <b>${Math.round(e.glucosa)}</b></span>`);
    if (e.ritmo_cardiaco) s.push(`<span>Pulso <b>${e.ritmo_cardiaco}</b></span>`);
    return `<li><div class="fila"><strong>${fmtFechaHora(e.fecha_evaluacion)}</strong>${etiquetaAlerta(e.nivel_alerta)}</div>
        <div class="small text-secondary">${esc(e.categoria_imc || '')}${e.evaluador ? `, por ${esc(e.evaluador)}` : ''}</div>
        <div class="signos">${s.join('')}</div>
        ${e.diagnostico ? `<p class="mb-0 mt-2 small">${esc(e.diagnostico)}</p>` : ''}</li>`;
}

/* ================================================================
   HABITACIONES
   ================================================================ */
async function vistaHabitaciones(c) {
    $('#accionesVista').innerHTML = `<button class="btn btn-primary" id="btnNuevaHab"><i class="bi bi-plus-lg me-1"></i> Agregar habitación</button>`;
    $('#btnNuevaHab').onclick = () => formHabitacion();
    const habs = await api('habitaciones');
    const camas = habs.filter(h => h.estado !== 'mantenimiento').reduce((s, h) => s + h.capacidad, 0);
    const ocup = habs.reduce((s, h) => s + h.ocupantes, 0);
    c.innerHTML = `<section class="panel">
        <div class="panel-cab"><h2>${habs.length} habitaciones, ${ocup} de ${camas} camas ocupadas</h2>
            <div class="leyenda ms-auto"><span><i class="cama cama-ocupada"></i>Ocupada</span><span><i class="cama cama-hospital"></i>Hospitalizado</span><span><i class="cama cama-libre"></i>Libre</span></div></div>
        <div class="panel-cuerpo">${mapaHabitaciones(habs, true, true)}
            <p class="text-secondary small mt-3 mb-0">Seleccione una habitación para editarla. El estado «Llena» o «Disponible» se calcula solo, según los residentes asignados.</p></div>
    </section>`;
    activarMapa(c, habs);
}

function formHabitacion(h = null) {
    const v = h || { piso: 1, tipo: 'Compartida', capacidad: 2, costo_adicional: '0.00' };
    const ocupada = h && h.ocupantes > 0;
    abrirForm({
        titulo: h ? `Habitación ${h.numero_habitacion}` : 'Agregar habitación',
        boton: h ? 'Guardar cambios' : 'Agregar habitación',
        cuerpo: `<input type="hidden" name="id_habitacion" value="${esc(v.id_habitacion || '')}">
        <div class="row g-3">
            ${campo({ nombre: 'numero_habitacion', etiqueta: 'Número', valor: v.numero_habitacion, req: true, col: 'col-md-4', attrs: 'maxlength="10"' })}
            ${campo({ nombre: 'piso', etiqueta: 'Piso', tipo: 'number', valor: v.piso, req: true, col: 'col-md-4', attrs: 'min="0" max="20"' })}
            ${selector({ nombre: 'tipo', etiqueta: 'Tipo', valor: v.tipo, req: true, col: 'col-md-4', vacio: null,
                opciones: ['Privada', 'Compartida', 'Cuidados Intensivos'].map(t => ({ v: t, t })) })}
            ${campo({ nombre: 'capacidad', etiqueta: 'Camas', tipo: 'number', valor: v.capacidad, req: true, col: 'col-md-4', attrs: `min="${Math.max(1, h?.ocupantes || 0)}" max="10"`,
                ayuda: ocupada ? `Ocupadas: ${h.ocupantes}` : '' })}
            ${campo({ nombre: 'costo_adicional', etiqueta: 'Costo adicional mensual (Q)', tipo: 'number', valor: v.costo_adicional, col: 'col-md-8', attrs: 'min="0" step="0.01"' })}
            <div class="col-12"><div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="f_mant" name="mantenimiento" ${v.estado === 'mantenimiento' ? 'checked' : ''} ${ocupada ? 'disabled' : ''}>
                <label class="form-check-label" for="f_mant">Fuera de servicio por mantenimiento</label>
                ${ocupada ? '<div class="form-text">Reubique a los residentes para poder ponerla en mantenimiento.</div>' : ''}</div></div>
            ${ocupada ? `<div class="col-12"><div class="small text-secondary">Residentes: ${h.residentes.map(r => esc(r.nombre + ' ' + r.apellido)).join(', ')}</div></div>` : ''}
        </div>`,
        extraPie: h && !ocupada ? '<button type="button" class="btn btn-link text-danger p-0" id="btnElimHab">Eliminar habitación</button>' : '',
        alEnviar: async d => {
            const res = await api('habitacion_guardar', d);
            cerrarForm(); aviso(res.mensaje); recargarVista();
        },
    });
    $('#btnElimHab')?.addEventListener('click', async () => {
        cerrarForm();
        if (!await confirmar(`¿Eliminar la habitación ${h.numero_habitacion}?`, 'Esta acción no se puede deshacer.', 'Eliminar')) return;
        try { const res = await api('habitacion_eliminar', { id_habitacion: h.id_habitacion }); aviso(res.mensaje); recargarVista(); }
        catch (ex) { aviso(ex.message, 'error'); }
    });
}

/* ================================================================
   FAMILIARS
   ================================================================ */
async function vistaFamiliares(c) {
    $('#accionesVista').innerHTML = `<button class="btn btn-primary" id="btnNuevoFam"><i class="bi bi-plus-lg me-1"></i> Registrar familiar</button>`;
    $('#btnNuevoFam').onclick = () => formFamiliar(null, () => recargarVista());
    const lista = await api('familiares');
    c.innerHTML = `<section class="panel">
        <div class="panel-cab"><input type="search" class="form-control" style="max-width:300px" id="buscarFam" placeholder="Buscar por nombre o DPI" aria-label="Buscar familiar"></div>
        <div class="table-responsive"><table class="table tabla">
        <thead><tr><th>Familiar</th><th>Parentesco</th><th>DPI</th><th>Contacto</th><th class="text-end">Residentes a cargo</th></tr></thead>
        <tbody id="tbFam"></tbody></table></div></section>`;
    const pintar = (q = '') => {
        const f = lista.filter(a => `${a.nombre} ${a.apellido} ${a.documento_id}`.toLowerCase().includes(q.toLowerCase()));
        $('#tbFam').innerHTML = f.length ? f.map(a => `<tr class="clic" tabindex="0" data-id="${a.id_familiar}">
            <td><div class="persona"><span class="avatar">${esc(iniciales(a.nombre, a.apellido))}</span><strong>${esc(a.nombre)} ${esc(a.apellido)}</strong></div></td>
            <td>${esc(a.parentesco)}</td><td>${esc(a.documento_id)}</td>
            <td>${esc(a.telefono)}${a.email ? `<br><small class="text-secondary">${esc(a.email)}</small>` : ''}</td>
            <td class="text-end cifra">${a.residentes_a_cargo}</td></tr>`).join('')
            : `<tr><td colspan="5">${vacio('person-vcard', lista.length ? 'Ningún familiar coincide con la búsqueda.' : 'Registre al primer familiar para poder ingresar residentes.')}</td></tr>`;
        $$('#tbFam tr.clic').forEach(tr => {
            const abrir = () => formFamiliar(lista.find(a => a.id_familiar == tr.dataset.id), () => recargarVista());
            tr.addEventListener('click', abrir);
            tr.addEventListener('keydown', e => { if (e.key === 'Enter') abrir(); });
        });
    };
    $('#buscarFam').addEventListener('input', e => pintar(e.target.value));
    pintar();
}

function formFamiliar(a = null, despues = null) {
    const v = a || {};
    abrirForm({
        titulo: a ? `${a.nombre} ${a.apellido}` : 'Registrar familiar',
        boton: a ? 'Guardar cambios' : 'Registrar familiar',
        cuerpo: `<input type="hidden" name="id_familiar" value="${esc(v.id_familiar || '')}">
        <div class="row g-3">
            ${campo({ nombre: 'nombre', etiqueta: 'Nombres', valor: v.nombre, req: true, attrs: 'maxlength="100"' })}
            ${campo({ nombre: 'apellido', etiqueta: 'Apellidos', valor: v.apellido, req: true, attrs: 'maxlength="100"' })}
            ${campo({ nombre: 'documento_id', etiqueta: 'DPI', valor: v.documento_id, req: true, attrs: 'maxlength="20" inputmode="numeric"' })}
            ${campo({ nombre: 'parentesco', etiqueta: 'Parentesco', valor: v.parentesco, req: true, attrs: 'maxlength="50" list="parentescos"' })}
            <datalist id="parentescos"><option>Hijo</option><option>Hija</option><option>Esposo</option><option>Esposa</option><option>Hermano</option><option>Hermana</option><option>Sobrino</option><option>Sobrina</option><option>Nieto</option><option>Nieta</option><option>Tutor legal</option></datalist>
            ${campo({ nombre: 'telefono', etiqueta: 'Teléfono', tipo: 'tel', valor: v.telefono, req: true, attrs: 'maxlength="20" pattern="[0-9+\\-\\s()]{8,20}"', ayuda: 'Ej.: 5512-3487' })}
            ${campo({ nombre: 'email', etiqueta: 'Correo electrónico', tipo: 'email', valor: v.email, attrs: 'maxlength="100"', ayuda: 'Se usará para avisos al familiar' })}
            <div class="col-12"><label class="form-label" for="f_dir">Dirección</label><input class="form-control" id="f_dir" name="direccion" maxlength="500" value="${esc(v.direccion || '')}"></div>
        </div>`,
        extraPie: a && Number(a.residentes_a_cargo) === 0 ? '<button type="button" class="btn btn-link text-danger p-0" id="btnElimFam">Eliminar familiar</button>' : '',
        alEnviar: async d => {
            const res = await api('familiar_guardar', d);
            cerrarForm(); aviso(res.mensaje);
            if (despues) $('#modalForm').addEventListener('hidden.bs.modal', () => despues(res.id), { once: true });
        },
    });
    $('#btnElimFam')?.addEventListener('click', async () => {
        cerrarForm();
        if (!await confirmar(`¿Eliminar a ${a.nombre} ${a.apellido}?`, 'Esta acción no se puede deshacer.', 'Eliminar')) return;
        try { const res = await api('familiar_eliminar', { id_familiar: a.id_familiar }); aviso(res.mensaje); recargarVista(); }
        catch (ex) { aviso(ex.message, 'error'); }
    });
}

/* ================================================================
   EVALUACIONES MÉDICAS (CU-01, consume ms-imc)
   ================================================================ */
async function vistaEvaluaciones(c, params) {
    const [residentes, recientes] = await Promise.all([api('residentes'), api('evaluaciones')]);
    const elegibles = residentes.filter(r => r.estado !== 'inactivo');
    const pre = params.get('r') || '';

    c.innerHTML = `<div class="row g-4">
        <div class="col-lg-7"><form class="panel" id="formEval" novalidate>
            <div class="panel-cab"><h2>Registrar evaluación</h2></div>
            <div class="panel-cuerpo">
                <div class="row g-3">
                    ${selector({ nombre: 'id_residente', etiqueta: 'Residente', valor: pre, req: true, col: 'col-12',
                        opciones: elegibles.map(r => ({ v: r.id_residente, t: `${r.apellido}, ${r.nombre}${r.numero_habitacion ? ` (hab. ${r.numero_habitacion})` : ''}` })) })}
                    <h3 class="form-seccion col-12 mb-0">Medidas</h3>
                    ${campo({ nombre: 'peso_kg', etiqueta: 'Peso (kg)', tipo: 'number', req: true, attrs: 'min="20" max="250" step="0.1" placeholder="65.5"' })}
                    ${campo({ nombre: 'altura_m', etiqueta: 'Estatura (m)', tipo: 'number', req: true, attrs: 'min="1" max="2.3" step="0.01" placeholder="1.62"' })}
                    <h3 class="form-seccion col-12 mb-0">Signos vitales <span class="fw-normal text-secondary small">(deje vacío lo que no se midió)</span></h3>
                    ${campo({ nombre: 'presion_sistolica', etiqueta: 'Presión sistólica', tipo: 'number', col: 'col-6 col-md-3', attrs: 'min="50" max="260" placeholder="120"', ayuda: 'mmHg' })}
                    ${campo({ nombre: 'presion_diastolica', etiqueta: 'Diastólica', tipo: 'number', col: 'col-6 col-md-3', attrs: 'min="30" max="160" placeholder="80"', ayuda: 'mmHg' })}
                    ${campo({ nombre: 'glucosa', etiqueta: 'Glucosa', tipo: 'number', col: 'col-6 col-md-3', attrs: 'min="20" max="600" step="0.1" placeholder="95"', ayuda: 'mg/dL' })}
                    ${campo({ nombre: 'ritmo_cardiaco', etiqueta: 'Pulso', tipo: 'number', col: 'col-6 col-md-3', attrs: 'min="20" max="220" placeholder="72"', ayuda: 'latidos/min' })}
                    <div class="col-12"><label class="form-label" for="f_diag">Diagnóstico y observaciones</label>
                        <textarea class="form-control" id="f_diag" name="diagnostico" rows="3" maxlength="2000"></textarea></div>
                </div>
                <div id="evalError" class="text-danger small mt-3" role="alert"></div>
                <button class="btn btn-primary mt-3" type="submit" id="btnEval">Calcular y guardar evaluación</button>
            </div></form></div>
        <div class="col-lg-5"><section class="panel h-100"><div class="panel-cab"><h2>Resultado</h2></div>
            <div class="panel-cuerpo" id="resultadoEval">${vacio('clipboard2-pulse', 'Al guardar, aquí verá el IMC, su clasificación geriátrica y el nivel de alerta.')}</div></section></div>
    </div>
    <section class="panel mt-4"><div class="panel-cab"><h2>Evaluaciones recientes</h2></div>
        <div class="table-responsive"><table class="table tabla"><thead><tr><th>Fecha</th><th>Residente</th><th>IMC</th><th>Presión</th><th>Glucosa</th><th>Alerta</th><th>Registró</th></tr></thead>
        <tbody>${recientes.length ? recientes.map(e => `<tr class="clic" data-id="${e.id_residente}" tabindex="0">
            <td>${fmtFechaHora(e.fecha_evaluacion)}</td><td><strong>${esc(e.nombre)} ${esc(e.apellido)}</strong></td>
            <td class="cifra">${e.imc ?? '—'}</td><td>${e.presion_sistolica ? `${e.presion_sistolica}/${e.presion_diastolica}` : '—'}</td>
            <td>${e.glucosa ? Math.round(e.glucosa) : '—'}</td><td>${etiquetaAlerta(e.nivel_alerta)}</td><td class="text-secondary">${esc(e.evaluador)}</td></tr>`).join('')
            : `<tr><td colspan="7">${vacio('clipboard2-pulse', 'Aún no hay evaluaciones registradas.')}</td></tr>`}</tbody></table></div></section>`;

    $$('tr.clic', c).forEach(tr => tr.addEventListener('click', () => abrirExpediente(+tr.dataset.id)));

    $('#formEval').addEventListener('submit', async e => {
        e.preventDefault();
        const f = e.currentTarget, err = $('#evalError');
        err.textContent = '';
        if (!f.checkValidity()) { f.classList.add('was-validated'); err.textContent = 'Revise los campos marcados.'; f.querySelector(':invalid')?.focus(); return; }
        const b = $('#btnEval');
        b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Calculando…';
        try {
            const r = await api('evaluacion_guardar', datosForm(f));
            mostrarResultadoImc(r);
            aviso(`Evaluación de ${r.residente} guardada.`);
            if (r.aviso) aviso(r.aviso, 'aviso');
            const id = f.id_residente.value;
            f.reset(); f.classList.remove('was-validated'); f.id_residente.value = id;
            const nuevas = await api('evaluaciones');
            $('tbody', c.lastElementChild).innerHTML = nuevas.map(e => `<tr class="clic" data-id="${e.id_residente}"><td>${fmtFechaHora(e.fecha_evaluacion)}</td><td><strong>${esc(e.nombre)} ${esc(e.apellido)}</strong></td><td class="cifra">${e.imc ?? '—'}</td><td>${e.presion_sistolica ? `${e.presion_sistolica}/${e.presion_diastolica}` : '—'}</td><td>${e.glucosa ? Math.round(e.glucosa) : '—'}</td><td>${etiquetaAlerta(e.nivel_alerta)}</td><td class="text-secondary">${esc(e.evaluador)}</td></tr>`).join('');
            $$('tr.clic', c).forEach(tr => tr.addEventListener('click', () => abrirExpediente(+tr.dataset.id)));
        } catch (ex) {
            err.textContent = ex.message;
        } finally {
            b.disabled = false; b.textContent = 'Calcular y guardar evaluación';
        }
    });
}

function mostrarResultadoImc(r) {
    // Escala visual de 15 a 40 con las bandas geriátricas (22, 27, 30)
    const pos = v => Math.min(100, Math.max(0, (v - 15) / 25 * 100));
    $('#resultadoEval').innerHTML = `<div class="resultado-imc">
        <div class="d-flex justify-content-between align-items-start"><div><div class="text-secondary small">IMC de ${esc(r.residente)}</div>
            <div class="cifra">${r.imc}</div></div>${etiquetaAlerta(r.nivel_alerta)}</div>
        <p class="fw-bold mt-2 mb-0">${esc(r.categoria)}</p>
        <div class="escala" aria-hidden="true"><div class="escala-barra"><span></span><span></span><span></span><span></span></div>
            <div class="escala-marca" style="left:0%"></div></div>
        <div class="escala-num" aria-hidden="true">${[15, 22, 27, 30, 40].map(n => `<span style="left:${pos(n)}%">${n}</span>`).join('')}</div>
        ${r.motivos.length ? `<div class="mt-4"><strong>Motivos de la alerta</strong><ul class="motivos">${r.motivos.map(m => `<li>${esc(m)}</li>`).join('')}</ul></div>`
            : '<p class="mt-4 mb-0 text-secondary">Medidas y signos dentro de los rangos de referencia.</p>'}
        <p class="small text-secondary mt-3 mb-0">${r.origen_calculo === 'local' ? 'Calculado por el servidor principal (microservicio sin respuesta).' : 'Calculado por el microservicio de IMC.'}</p>
    </div>`;
    requestAnimationFrame(() => requestAnimationFrame(() => { $('.escala-marca').style.left = pos(r.imc) + '%'; }));
}

/* ================================================================
   COTIZADOR (RF03, consume ms-cotizador)
   ================================================================ */
async function vistaCotizador(c) {
    const cat = await api('cotizador_catalogo');
    const radio = (nombre, valor, titulo, detalle, marcado) => `<div class="col-sm-6 col-xl-4">
        <input type="radio" class="btn-check" name="${nombre}" id="${nombre}_${esc(valor)}" value="${esc(valor)}" ${marcado ? 'checked' : ''} required>
        <label class="opcion-tarjeta" for="${nombre}_${esc(valor)}"><strong>${esc(titulo)}</strong><small>${esc(detalle)}</small></label></div>`;

    c.innerHTML = `<div class="row g-4">
        <div class="col-lg-7"><form class="panel" id="formCot" novalidate><div class="panel-cuerpo">
            <h3 class="form-seccion">Plan de servicio</h3>
            <div class="row g-2">${cat.planes.map((p, i) => radio('id_plan', p.id_plan, p.nombre_plan, `${fmtQ(p.costo_base)} al mes`, i === 0)).join('')}</div>
            <h3 class="form-seccion">Habitación</h3>
            <div class="row g-2">${cat.habitaciones.map((h, i) => radio('tipo_habitacion', h.tipo, h.tipo, Number(h.costo_adicional) > 0 ? `+${fmtQ(h.costo_adicional)}` : 'Sin recargo', i === 0)).join('')}</div>
            <h3 class="form-seccion">Nivel de cuidados</h3>
            <div class="row g-2">${cat.cuidados.map((n, i) => radio('nivel_cuidados', n.clave, n.nombre.split(':')[0], `${n.nombre.split(':')[1]?.trim() || ''}, ${n.recargo > 0 ? '+' + fmtQ(n.recargo) : 'sin recargo'}`, i === 0)).join('')}</div>
            <h3 class="form-seccion">Servicios adicionales</h3>
            <div class="row g-2">${cat.extras.map(x => `<div class="col-sm-6"><div class="form-check">
                <input class="form-check-input" type="checkbox" name="extras[]" value="${esc(x.clave)}" id="ex_${esc(x.clave)}">
                <label class="form-check-label" for="ex_${esc(x.clave)}">${esc(x.nombre)} <span class="text-secondary">(${fmtQ(x.precio)})</span></label></div></div>`).join('')}</div>
            <h3 class="form-seccion">Descuento</h3>
            <div class="row g-3 align-items-end">
                ${campo({ nombre: 'descuento_pct', etiqueta: 'Porcentaje de descuento', tipo: 'number', valor: 0, col: 'col-sm-5', attrs: 'min="0" max="50" step="1"', ayuda: 'Máximo 50 %. Ej.: convenio con la fundación.' })}
            </div>
            <div id="cotError" class="text-danger small mt-3" role="alert"></div>
            <button class="btn btn-primary mt-3" type="submit" id="btnCot">Calcular cotización</button>
        </div></form></div>
        <div class="col-lg-5"><section class="panel" style="position:sticky;top:90px"><div class="panel-cab"><h2>Costo mensual estimado</h2></div>
            <div class="panel-cuerpo" id="resCot">${vacio('receipt', 'Elija las opciones y presione «Calcular cotización».')}</div></section></div>
    </div>`;

    $('#formCot').addEventListener('submit', async e => {
        e.preventDefault();
        const b = $('#btnCot'), err = $('#cotError');
        err.textContent = '';
        if (!e.currentTarget.checkValidity()) { err.textContent = 'Revise el porcentaje de descuento.'; return; }
        b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Calculando…';
        try {
            const r = await api('cotizar', datosForm(e.currentTarget));
            $('#resCot').innerHTML = `<ul class="desglose">
                <li><span>${esc(r.plan)}</span><span>${fmtQ(r.costo_base)}</span></li>
                <li><span>Habitación ${esc(r.tipo_habitacion.toLowerCase())}</span><span>${fmtQ(r.costo_habitacion)}</span></li>
                <li><span>Cuidados: ${esc(r.nivel_cuidados.split(':')[0].toLowerCase())}</span><span>${fmtQ(r.recargo_cuidado)}</span></li>
                ${r.extras.map(x => `<li><span>${esc(x.nombre)}</span><span>${fmtQ(x.precio)}</span></li>`).join('')}
                ${r.descuento_pct > 0 ? `<li><span>Subtotal</span><span>${fmtQ(r.subtotal)}</span></li><li class="descuento"><span>Descuento ${r.descuento_pct} %</span><span>− ${fmtQ(r.monto_descuento)}</span></li>` : ''}
                <li class="total"><span>Total al mes</span><span>${fmtQ(r.monto_total)}</span></li></ul>
                <p class="small text-secondary mt-3 mb-0">${r.origen_calculo === 'local' ? 'Calculado por el servidor principal (microservicio sin respuesta).' : 'Calculado por el microservicio cotizador.'} Montos en quetzales; cotización de referencia.</p>`;
            if (r.aviso) aviso(r.aviso, 'aviso');
        } catch (ex) { err.textContent = ex.message; }
        finally { b.disabled = false; b.textContent = 'Calcular cotización'; }
    });
}

/* ================================================================
   USUARIOS
   ================================================================ */
async function vistaUsuarios(c) {
    $('#accionesVista').innerHTML = `<button class="btn btn-primary" id="btnNuevoUsr"><i class="bi bi-person-plus me-1"></i> Crear usuario</button>`;
    const { usuarios, roles, especialidades } = await api('usuarios');
    $('#btnNuevoUsr').onclick = () => formUsuario(null, roles, especialidades);
    c.innerHTML = `<section class="panel"><div class="panel-cab"><input type="search" class="form-control" style="max-width:300px" id="buscarUsr" placeholder="Buscar por nombre, correo o rol" aria-label="Buscar usuario"></div>
        <div class="table-responsive"><table class="table tabla">
        <thead><tr><th>Usuario</th><th>Rol</th><th>Teléfono</th><th>Último acceso</th><th>Estado</th></tr></thead>
        <tbody id="tbUsr"></tbody></table></div></section>`;
    const pintar = (q = '') => {
        q = q.toLowerCase();
        const f = usuarios.filter(u => `${u.nombre} ${u.email} ${u.nombre_rol} ${u.especialidad || ''}`.toLowerCase().includes(q));
        $('#tbUsr').innerHTML = f.map(u => `<tr class="clic" tabindex="0" data-id="${u.id_usuario}">
            <td><div class="persona"><span class="avatar">${esc(iniciales(...u.nombre.replace(/^(Dra?\.|Lic\.|Ing\.)\s*/, '').split(' ')))}</span><div><strong>${esc(u.nombre)}</strong><small>${esc(u.email)}</small></div></div></td>
            <td>${esc(u.nombre_rol)}${u.especialidad ? `<br><small class="text-secondary">${esc(u.especialidad)}</small>` : ''}</td>
            <td>${esc(u.telefono || '—')}</td>
            <td>${u.ultimo_acceso ? fmtFechaHora(u.ultimo_acceso) : '<span class="text-secondary">Nunca</span>'}</td>
            <td>${etiqueta(u.estado === 'activo' ? 'activo' : 'inactivo', u.estado === 'activo' ? 'Activo' : 'Desactivado')}</td></tr>`).join('')
            || `<tr><td colspan="5">${vacio('search', 'Ningún usuario coincide con la búsqueda.')}</td></tr>`;
        $$('#tbUsr tr.clic').forEach(tr => {
            const abrir = () => formUsuario(usuarios.find(u => u.id_usuario == tr.dataset.id), roles, especialidades);
            tr.addEventListener('click', abrir);
            tr.addEventListener('keydown', e => { if (e.key === 'Enter') abrir(); });
        });
    };
    $('#buscarUsr').addEventListener('input', e => pintar(e.target.value));
    pintar();
}

function formUsuario(u, roles, especialidades) {
    const v = u || { estado: 'activo' };
    abrirForm({
        titulo: u ? u.nombre : 'Crear usuario',
        boton: u ? 'Guardar cambios' : 'Crear usuario',
        cuerpo: `<input type="hidden" name="id_usuario" value="${esc(v.id_usuario || '')}"><div class="row g-3">
            ${campo({ nombre: 'nombre', etiqueta: 'Nombre completo', valor: v.nombre, req: true, attrs: 'maxlength="100"' })}
            ${campo({ nombre: 'email', etiqueta: 'Correo (usuario de acceso)', tipo: 'email', valor: v.email, req: true, attrs: 'maxlength="100"' })}
            ${selector({ nombre: 'id_rol', etiqueta: 'Rol', valor: v.id_rol, req: true, opciones: roles.map(r => ({ v: r.id_rol, t: r.nombre_rol })) })}
            ${campo({ nombre: 'telefono', etiqueta: 'Teléfono', tipo: 'tel', valor: v.telefono, attrs: 'maxlength="20"' })}
            <div class="col-12 form-text mt-1" id="descRol"></div>
            <div class="col-md-6 ${+v.id_rol === ROL.ESPECIALISTA ? '' : 'd-none'}" id="grupoEsp">
                <label class="form-label requerido" for="f_id_especialidad">Especialidad</label>
                <select class="form-select" id="f_id_especialidad" name="id_especialidad" ${+v.id_rol === ROL.ESPECIALISTA ? 'required' : 'disabled'}>
                    <option value="">Seleccione…</option>${especialidades.map(e => `<option value="${e.id_especialidad}" ${e.id_especialidad == v.id_especialidad ? 'selected' : ''}>${esc(e.nombre)}</option>`).join('')}</select></div>
            ${selector({ nombre: 'estado', etiqueta: 'Estado', valor: v.estado, req: true, vacio: null, opciones: [{ v: 'activo', t: 'Activo' }, { v: 'inactivo', t: 'Desactivado (no puede ingresar)' }] })}
            ${campo({ nombre: 'password', etiqueta: u ? 'Nueva contraseña' : 'Contraseña', tipo: 'password', req: !u, col: 'col-12', attrs: 'minlength="8" autocomplete="new-password"',
                ayuda: (u ? 'Déjela vacía para conservar la actual. ' : '') + 'Mínimo 8 caracteres, con letras y números. Se guarda cifrada con Bcrypt.' })}
        </div>`,
        alEnviar: async d => { const r = await api('usuario_guardar', d); cerrarForm(); aviso(r.mensaje); recargarVista(); },
    });
    const selRol = $('#f_id_rol'), selEsp = $('#f_id_especialidad');
    const actualizar = () => {
        const esEsp = +selRol.value === ROL.ESPECIALISTA;
        $('#grupoEsp').classList.toggle('d-none', !esEsp);
        selEsp.disabled = !esEsp; selEsp.required = esEsp;
        $('#descRol').textContent = roles.find(r => r.id_rol == selRol.value)?.descripcion || '';
    };
    selRol.addEventListener('change', actualizar);
    actualizar();
}

/* ================================================================
   BITÁCORA
   ================================================================ */
const NOMBRES_ACCION = {
    LOGIN: 'Inicio de sesión', LOGOUT: 'Cierre de sesión', LOGIN_FALLIDO: 'Intento de acceso fallido',
    REGISTRO_RESIDENTE: 'Registro de residente', ACTUALIZA_RESIDENTE: 'Edición de residente', CAMBIO_ESTADO_RESIDENTE: 'Cambio de estado de residente',
    REGISTRO_FAMILIAR: 'Registro de familiar', ACTUALIZA_FAMILIAR: 'Edición de familiar', ELIMINA_FAMILIAR: 'Eliminación de familiar',
    REGISTRO_HABITACION: 'Registro de habitación', ACTUALIZA_HABITACION: 'Edición de habitación', ELIMINA_HABITACION: 'Eliminación de habitación',
    EVALUACION_IMC: 'Evaluación médica', COTIZACION: 'Cotización de estancia',
    FICHA_PADECIMIENTO: 'Ficha médica: padecimiento', FICHA_ELIMINA_PADECIMIENTO: 'Ficha médica: padecimiento quitado', FICHA_MEDICAMENTO_PERMANENTE: 'Ficha médica: medicamento permanente',
    SOLICITUD_CREADA: 'Solicitud a especialista', SOLICITUD_PROGRAMADA: 'Cita programada', SOLICITUD_REPROGRAMADA: 'Cita reprogramada', SOLICITUD_CANCELADA: 'Solicitud cancelada',
    VISITA_INICIADA: 'Visita iniciada', VISITA_NOTAS: 'Visita: notas', VISITA_EXAMEN: 'Visita: examen solicitado', VISITA_QUITA_EXAMEN: 'Visita: examen quitado',
    VISITA_RECETA: 'Visita: medicamento recetado', VISITA_QUITA_RECETA: 'Visita: receta quitada', VISITA_FINALIZADA: 'Visita finalizada',
    CONFIGURACION: 'Cambio de configuración', CORREO_PRUEBA: 'Correo de prueba',
    REGISTRO_USUARIO: 'Creación de usuario', ACTUALIZA_USUARIO: 'Edición de usuario', INSTALACION: 'Instalación del sistema',
};

async function vistaBitacora(c) {
    c.innerHTML = `<section class="panel"><div class="panel-cab">
        <input type="search" class="form-control" style="max-width:320px" id="buscarBit" placeholder="Buscar por acción, usuario o detalle" aria-label="Buscar en la bitácora">
        <span class="text-secondary small ms-auto">Registro de solo lectura: las entradas no se pueden editar ni borrar.</span></div>
        <div class="table-responsive"><table class="table tabla"><thead><tr><th>Fecha y hora</th><th>Usuario</th><th>Acción</th><th>Tabla</th><th>Detalle</th><th>IP</th></tr></thead>
        <tbody id="tbBit"></tbody></table></div></section>`;
    let t;
    const cargar = async (q = '') => {
        const filas = await api('bitacora', null, q ? `&q=${encodeURIComponent(q)}` : '');
        $('#tbBit').innerHTML = filas.length ? filas.map(b => `<tr>
            <td class="text-nowrap">${fmtFechaHora(b.fecha_hora)}</td>
            <td>${esc(b.usuario)}${b.rol ? `<br><small class="text-secondary">${esc(nombreRol(b.rol))}</small>` : ''}</td>
            <td>${b.accion === 'LOGIN_FALLIDO' ? etiqueta('urgente', NOMBRES_ACCION[b.accion]) : esc(NOMBRES_ACCION[b.accion] || b.accion)}</td>
            <td class="text-secondary">${esc(b.tabla_afectada)}</td>
            <td class="det-bitacora">${detalleBitacora(b.detalles)}</td>
            <td class="text-secondary small">${esc(b.ip_origen || '')}</td></tr>`).join('')
            : `<tr><td colspan="6">${vacio('journal', 'No hay registros que coincidan.')}</td></tr>`;
    };
    $('#buscarBit').addEventListener('input', e => { clearTimeout(t); t = setTimeout(() => cargar(e.target.value.trim()), 300); });
    await cargar();
}
function detalleBitacora(d) {
    if (!d) return '';
    try {
        const o = JSON.parse(d);
        if (o && typeof o === 'object') return Object.entries(o).filter(([, v]) => v !== null && v !== '').map(([k, v]) => `${esc(k.replace(/_/g, ' '))}: <b>${esc(typeof v === 'object' ? JSON.stringify(v) : v)}</b>`).join('<br>');
    } catch (_) { /* texto plano */ }
    return esc(d);
}

/* ---------------- Arranque ----------------
   Se espera a DOMContentLoaded para que los módulos (atencion.js, …) ya estén cargados. */
document.addEventListener('DOMContentLoaded', () => {
    construirMenu();
    window.addEventListener('hashchange', navegar);
    navegar();
});
