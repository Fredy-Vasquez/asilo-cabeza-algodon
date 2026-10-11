/* ============================================================
   Asilo Cabeza de Algodón — Módulo de atención médica
   Solicitudes (médico general → fundación), agenda del especialista,
   visita médica, acompañamientos del enfermero y configuración del correo.
   Usa las utilidades de app.js (api, esc, abrirForm, aviso…).
   ============================================================ */
'use strict';

const COLOR_SOLICITUD = { Pendiente: 'atencion', Programada: 'activo', Atendida: 'neutro', Cancelada: 'inactivo' };
const etiquetaSolicitud = e => etiqueta(COLOR_SOLICITUD[e] || 'neutro', e);
const etiquetaPrioridad = p => p === 'Urgente' ? etiqueta('urgente', 'Urgente') : '<span class="text-secondary">Normal</span>';
const DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

function diaRelativo(f) {
    const d = new Date(String(f).slice(0, 10) + 'T00:00:00');
    const h = new Date(hoy() + 'T00:00:00');
    const dif = Math.round((d - h) / 86400000);
    if (dif === 0) return 'Hoy';
    if (dif === 1) return 'Mañana';
    if (dif === -1) return 'Ayer';
    return `${DIAS[d.getDay()].replace(/^./, c => c.toUpperCase())} ${fmtFecha(f)}`;
}
const hora = f => String(f).slice(11, 16);

/** Muestra el resultado del correo al familiar después de crear o programar */
function avisoCorreo(c) {
    if (!c) return;
    if (c.estado === 'Enviado') aviso(`Se avisó por correo al familiar (${c.destinatario}).`);
    else if (c.estado === 'Sin correo') aviso('El familiar no tiene correo registrado; avísele por teléfono.', 'aviso');
    else aviso(`No se pudo enviar el correo al familiar: ${c.detalle}`, 'aviso');
}

/** Fila de cita para el inicio */
function itemCita(s) {
    const tag = puede(ROL.ADMIN, ROL.MEDICO, ROL.FUNDACION, ROL.ESPECIALISTA) ? 'button' : 'div';
    return `<${tag} class="cita-item" data-sol="${s.id_solicitud}" ${tag === 'button' ? 'type="button"' : ''}>
        <div class="cita-hora"><span>${diaRelativo(s.fecha_programada)}</span><strong>${hora(s.fecha_programada)}</strong></div>
        <div class="min-w-0"><strong>${esc(s.residente_nombre)} ${esc(s.residente_apellido)}</strong>
        <p>${esc(s.especialidad)} con ${esc(s.medico_asignado)}${s.enfermero ? `<br>Acompaña: ${esc(s.enfermero)}` : ''}</p></div></${tag}>`;
}
document.addEventListener('click', e => {
    const b = e.target.closest('button.cita-item');
    if (!b) return;
    if (puede(ROL.ESPECIALISTA)) location.hash = `#visita?s=${b.dataset.sol}`;
    else abrirSolicitud(+b.dataset.sol);
});

/** Visita en el historial del expediente (ficha médica completa del enunciado) */
function itemVisita(v) {
    const examenes = v.examenes.map(x => `<li><strong>${esc(x.nombre)}</strong>: ${x.estado === 'Realizado'
        ? esc(x.resultado) : '<span class="text-secondary">pendiente de resultado</span>'}</li>`).join('');
    const recetas = v.recetas.map(m => `<li><strong>${esc(m.nombre_comercial)}</strong> x ${m.cantidad}: ${esc(m.indicaciones)}${m.duracion_dias ? `, durante ${m.duracion_dias} días` : ''}
        <span class="text-secondary">(${m.estado === 'Entregado' ? 'entregado' : 'por entregar'})</span></li>`).join('');
    return `<li><div class="fila"><strong>${fmtFecha(v.fecha_visita)}: ${esc(v.especialidad)}</strong>${v.estado === 'Finalizada' ? etiqueta('neutro', 'Finalizada') : etiqueta('atencion', 'En curso')}</div>
        <div class="small text-secondary">Médico tratante: ${esc(v.medico)}</div>
        <dl class="visita-dl">
            <dt>Motivo</dt><dd>${esc(v.motivo)}</dd>
            ${v.diagnostico ? `<dt>Diagnóstico</dt><dd>${esc(v.diagnostico)}</dd>` : ''}
            ${examenes ? `<dt>Exámenes</dt><dd><ul>${examenes}</ul></dd>` : ''}
            ${recetas ? `<dt>Medicamentos</dt><dd><ul>${recetas}</ul></dd>` : ''}
            ${v.observaciones ? `<dt>Observaciones</dt><dd>${esc(v.observaciones)}</dd>` : ''}
        </dl></li>`;
}

/* ================================================================
   SOLICITUDES (médico general, fundación, administrador)
   ================================================================ */
let filtroSolicitudes = { estado: '', q: '' };

async function vistaSolicitudes(c, params) {
    if (params.get('estado') !== null) filtroSolicitudes.estado = params.get('estado');
    else if (!filtroSolicitudes.estado) filtroSolicitudes.estado = puede(ROL.FUNDACION) ? 'Pendiente' : '';
    if (puede(ROL.ADMIN, ROL.MEDICO)) {
        $('#accionesVista').innerHTML = `<button class="btn btn-primary" id="btnNuevaSol"><i class="bi bi-send me-1"></i> Nueva solicitud</button>`;
        $('#btnNuevaSol').onclick = () => formSolicitud();
    }
    const { solicitudes, conteo } = await api('solicitudes');
    conteo[''] = solicitudes.length;
    const filtros = [['Pendiente', 'Pendientes'], ['Programada', 'Programadas'], ['Atendida', 'Atendidas'], ['Cancelada', 'Canceladas'], ['', 'Todas']];

    c.innerHTML = `${puede(ROL.FUNDACION) && conteo.Pendiente ? `<div class="banda-aviso"><i class="bi bi-inbox"></i> Hay <strong>${conteo.Pendiente}</strong> solicitud(es) del asilo esperando médico y horario.</div>` : ''}
    <section class="panel"><div class="panel-cab"><div class="filtros w-100">
        <input type="search" class="form-control" id="buscarSol" placeholder="Buscar por residente, especialidad o médico" aria-label="Buscar solicitud" value="${esc(filtroSolicitudes.q)}">
        <div class="btn-group flex-wrap" role="group" aria-label="Filtrar por estado">
        ${filtros.map(([v, t]) => `<input type="radio" class="btn-check" name="estSol" id="sol_${v || 'todas'}" value="${v}" ${filtroSolicitudes.estado === v ? 'checked' : ''}>
            <label class="btn btn-outline-secondary btn-sm" for="sol_${v || 'todas'}">${t} <span class="opacity-75">${conteo[v] ?? 0}</span></label>`).join('')}
        </div></div></div>
        <div class="table-responsive"><table class="table tabla">
        <thead><tr><th>Residente</th><th>Especialidad</th><th>Prioridad</th><th>Fecha</th><th>Médico asignado</th><th>Estado</th></tr></thead>
        <tbody id="tbSol"></tbody></table></div></section>`;

    const pintar = () => {
        const q = filtroSolicitudes.q.toLowerCase();
        const filas = solicitudes.filter(s => (!filtroSolicitudes.estado || s.estado === filtroSolicitudes.estado) &&
            (!q || `${s.residente_nombre} ${s.residente_apellido} ${s.especialidad} ${s.medico_asignado || ''}`.toLowerCase().includes(q)));
        $('#tbSol').innerHTML = filas.length ? filas.map(s => `<tr class="clic" tabindex="0" data-id="${s.id_solicitud}">
            <td><div class="persona"><span class="avatar">${esc(iniciales(s.residente_nombre, s.residente_apellido))}</span>
                <div><strong>${esc(s.residente_nombre)} ${esc(s.residente_apellido)}</strong><small>${s.numero_habitacion ? `Hab. ${esc(s.numero_habitacion)}, ` : ''}remite ${esc(s.medico_general)}</small></div></div></td>
            <td>${esc(s.especialidad)}</td>
            <td>${etiquetaPrioridad(s.prioridad)}</td>
            <td>${s.fecha_programada && s.estado !== 'Cancelada' ? `<strong>${diaRelativo(s.fecha_programada)}</strong>, ${hora(s.fecha_programada)}` : `<span class="text-secondary">Solicitada el ${fmtFecha(s.fecha_solicitud)}</span>`}</td>
            <td>${s.medico_asignado ? esc(s.medico_asignado) : '<span class="text-secondary">Por asignar</span>'}</td>
            <td>${etiquetaSolicitud(s.estado)}</td></tr>`).join('')
            : `<tr><td colspan="6">${vacio('inbox', solicitudes.length ? 'No hay solicitudes con este filtro.' : 'Todavía no hay solicitudes de atención.',
                !solicitudes.length && puede(ROL.ADMIN, ROL.MEDICO) ? '<button class="btn btn-primary btn-sm" onclick="formSolicitud()">Crear la primera</button>' : '')}</td></tr>`;
        $$('#tbSol tr.clic').forEach(tr => {
            tr.addEventListener('click', () => abrirSolicitud(+tr.dataset.id, solicitudes));
            tr.addEventListener('keydown', e => { if (e.key === 'Enter') abrirSolicitud(+tr.dataset.id, solicitudes); });
        });
    };
    $('#buscarSol').addEventListener('input', e => { filtroSolicitudes.q = e.target.value; pintar(); });
    $$('input[name=estSol]').forEach(i => i.addEventListener('change', () => { filtroSolicitudes.estado = i.value; pintar(); }));
    pintar();
}

let cacheOpcionesSol = null;
async function opcionesSolicitud() {
    cacheOpcionesSol = cacheOpcionesSol || await api('solicitudes_opciones');
    return cacheOpcionesSol;
}

/** El médico general remite al residente a una especialidad */
async function formSolicitud(idResidente = null) {
    let op, residentes;
    try { [op, residentes] = await Promise.all([opcionesSolicitud(), api('residentes')]); }
    catch (ex) { aviso(ex.message, 'error'); return; }
    residentes = residentes.filter(r => r.estado !== 'inactivo');
    abrirForm({
        titulo: 'Remitir a especialista',
        boton: 'Enviar a la fundación',
        cuerpo: `<div class="row g-3">
            ${selector({ nombre: 'id_residente', etiqueta: 'Residente', valor: idResidente, req: true, col: 'col-12',
                opciones: residentes.map(r => ({ v: r.id_residente, t: `${r.apellido}, ${r.nombre}${r.numero_habitacion ? ` (hab. ${r.numero_habitacion})` : ''}` })) })}
            ${selector({ nombre: 'id_especialidad', etiqueta: 'Especialidad', req: true,
                opciones: op.especialidades.map(e => ({ v: e.id_especialidad, t: e.nombre })) })}
            ${selector({ nombre: 'prioridad', etiqueta: 'Prioridad', valor: 'Normal', req: true, vacio: null, opciones: [{ v: 'Normal', t: 'Normal' }, { v: 'Urgente', t: 'Urgente' }] })}
            ${selector({ nombre: 'id_enfermero', etiqueta: 'Enfermero(a) acompañante', col: 'col-12', vacio: 'Asignar después',
                opciones: op.enfermeros.map(e => ({ v: e.id_usuario, t: e.nombre })) })}
            <div class="col-12"><label class="form-label requerido" for="f_motivo_sol">Motivo de la remisión</label>
                <textarea class="form-control" id="f_motivo_sol" name="motivo" rows="3" maxlength="2000" required placeholder="Síntomas, hallazgos de la evaluación y qué se espera del especialista"></textarea>
                <div class="form-text">El familiar responsable recibirá un correo con el motivo y el estado del residente.</div></div>
        </div>`,
        alEnviar: async d => {
            const res = await api('solicitud_crear', d);
            cerrarForm(); aviso(res.mensaje); avisoCorreo(res.correo);
            if (location.hash.startsWith('#solicitudes')) recargarVista(); else location.hash = '#solicitudes?estado=Pendiente';
            bootstrap.Offcanvas.getInstance('#panelExpediente')?.hide();
        },
    });
}

/** Detalle de la solicitud con las acciones que permite el rol */
async function abrirSolicitud(id, lista = null) {
    let s = lista?.find(x => x.id_solicitud == id);
    if (!s) {
        try { s = (await api('solicitudes')).solicitudes.find(x => x.id_solicitud == id); } catch (ex) { aviso(ex.message, 'error'); return; }
    }
    if (!s) { aviso('La solicitud ya no está disponible.', 'error'); return; }
    const abierta = ['Pendiente', 'Programada'].includes(s.estado);
    const acciones = [];
    if (abierta && puede(ROL.ADMIN, ROL.FUNDACION)) acciones.push(`<button class="btn btn-primary btn-sm" id="solProgramar"><i class="bi bi-calendar-plus me-1"></i>${s.estado === 'Programada' ? 'Reprogramar' : 'Asignar médico y horario'}</button>`);
    if (s.id_visita && puede(ROL.ADMIN, ROL.MEDICO)) acciones.push(`<a class="btn btn-outline-primary btn-sm" href="#visita?s=${s.id_solicitud}"><i class="bi bi-clipboard2-pulse me-1"></i>Ver visita</a>`);
    if (abierta && !s.id_visita && puede(ROL.ADMIN, ROL.MEDICO, ROL.FUNDACION)) acciones.push(`<button class="btn btn-outline-danger btn-sm" id="solCancelar">Cancelar solicitud</button>`);

    $('#expTitulo').textContent = 'Solicitud de atención';
    $('#expCuerpo').innerHTML = `
        <div class="exp-cab"><span class="avatar avatar-grande">${esc(iniciales(s.residente_nombre, s.residente_apellido))}</span>
            <div><h3>${esc(s.residente_nombre)} ${esc(s.residente_apellido)}</h3><div class="text-secondary">${s.residente_edad} años${s.numero_habitacion ? `, habitación ${esc(s.numero_habitacion)}` : ''}</div>
            <div class="mt-1 d-flex gap-2">${etiquetaSolicitud(s.estado)}${s.prioridad === 'Urgente' ? etiqueta('urgente', 'Urgente') : ''}</div></div></div>
        <div class="d-flex flex-wrap gap-2 mb-4">${acciones.join('')}</div>
        <dl class="datos">
            <div><dt>Especialidad</dt><dd>${esc(s.especialidad)}</dd></div>
            <div><dt>Remitido por</dt><dd>${esc(s.medico_general)}<br><span class="fw-normal text-secondary">el ${fmtFechaHora(s.fecha_solicitud)}</span></dd></div>
            <div style="grid-column:1/-1"><dt>Motivo</dt><dd class="fw-normal">${esc(s.motivo)}</dd></div>
            <div><dt>Médico asignado</dt><dd>${s.medico_asignado ? esc(s.medico_asignado) : 'Por asignar'}</dd></div>
            <div><dt>Fecha y hora</dt><dd>${s.fecha_programada ? `${diaRelativo(s.fecha_programada)}, ${hora(s.fecha_programada)}` : 'Por asignar'}</dd></div>
            <div><dt>Enfermero(a) acompañante</dt><dd>${s.enfermero ? `${esc(s.enfermero)}<br><span class="fw-normal text-secondary">${esc(s.enfermero_telefono || '')}</span>` : 'Sin asignar'}</dd></div>
            <div><dt>Consulta (precio de lista)</dt><dd>${fmtQ(s.costo_consulta)}</dd></div>
            ${s.notas_fundacion ? `<div style="grid-column:1/-1"><dt>Notas de la fundación</dt><dd class="fw-normal pre">${esc(s.notas_fundacion)}</dd></div>` : ''}
        </dl>`;
    panelExp.show();
    $('#solProgramar')?.addEventListener('click', () => formProgramar(s));
    $('#solCancelar')?.addEventListener('click', () => formCancelar(s));
}

/** La fundación asigna el médico especialista y el horario */
async function formProgramar(s) {
    let op;
    try { op = await opcionesSolicitud(); } catch (ex) { aviso(ex.message, 'error'); return; }
    const medicos = op.especialistas.filter(m => m.id_especialidad == s.id_especialidad);
    const ahora = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    const valorFecha = s.fecha_programada ? s.fecha_programada.slice(0, 16).replace(' ', 'T') : '';
    abrirForm({
        titulo: `${s.estado === 'Programada' ? 'Reprogramar' : 'Programar'} cita: ${s.residente_nombre} ${s.residente_apellido}`,
        boton: s.estado === 'Programada' ? 'Guardar cambio' : 'Programar cita',
        cuerpo: `<input type="hidden" name="id_solicitud" value="${s.id_solicitud}">
        <div class="resumen-solicitud"><div><strong>${esc(s.especialidad)}</strong>${s.prioridad === 'Urgente' ? ' ' + etiqueta('urgente', 'Urgente') : ''}</div><p class="mb-0">${esc(s.motivo)}</p></div>
        ${!medicos.length ? `<div class="ayuda-vacia mb-3">No hay médicos de ${esc(s.especialidad)} registrados. El administrador debe crear uno en <strong>Usuarios</strong> con el rol «Médico especialista».</div>` : ''}
        <div class="row g-3">
            ${selector({ nombre: 'id_medico_asignado', etiqueta: 'Médico especialista', valor: s.id_medico_asignado, req: true, col: 'col-12',
                opciones: medicos.map(m => ({ v: m.id_usuario, t: `${m.nombre}${m.telefono ? `, tel. ${m.telefono}` : ''}` })) })}
            ${campo({ nombre: 'fecha_programada', etiqueta: 'Fecha y hora', tipo: 'datetime-local', valor: valorFecha, req: true, attrs: `min="${ahora}"`, ayuda: 'Atención de 7:00 a 18:00' })}
            ${selector({ nombre: 'id_enfermero', etiqueta: 'Enfermero(a) acompañante', valor: s.id_enfermero, vacio: 'Sin asignar',
                opciones: op.enfermeros.map(e => ({ v: e.id_usuario, t: e.nombre })) })}
            <div class="col-12"><label class="form-label" for="f_notas_f">Notas para el asilo</label>
                <textarea class="form-control" id="f_notas_f" name="notas_fundacion" rows="2" maxlength="1000" placeholder="Clínica, preparación, documentos a llevar…">${esc(s.notas_fundacion || '')}</textarea></div>
        </div>
        <p class="form-text mt-3 mb-0">Al guardar se avisa por correo al familiar con el médico, la fecha y la hora.</p>`,
        alEnviar: async d => {
            const res = await api('solicitud_programar', d);
            cerrarForm(); panelExp.hide(); aviso(res.mensaje); avisoCorreo(res.correo); recargarVista();
        },
    });
}

function formCancelar(s) {
    abrirForm({
        titulo: `Cancelar solicitud de ${s.residente_nombre}`,
        boton: 'Cancelar solicitud',
        cuerpo: `<input type="hidden" name="id_solicitud" value="${s.id_solicitud}">
            <label class="form-label requerido" for="f_mot_can">Motivo de la cancelación</label>
            <textarea class="form-control" id="f_mot_can" name="motivo" rows="2" maxlength="500" required placeholder="Ej.: el residente mejoró, se duplicó la solicitud…"></textarea>`,
        alEnviar: async d => {
            const res = await api('solicitud_cancelar', d);
            cerrarForm(); panelExp.hide(); aviso(res.mensaje); recargarVista();
        },
    });
}

/* ================================================================
   AGENDA DEL MÉDICO ESPECIALISTA
   ================================================================ */
async function vistaAgenda(c) {
    const { solicitudes } = await api('solicitudes');
    const proximas = solicitudes.filter(s => s.estado === 'Programada').sort((a, b) => a.fecha_programada.localeCompare(b.fecha_programada));
    const atendidas = solicitudes.filter(s => s.estado === 'Atendida');
    const porDia = {};
    proximas.forEach(s => (porDia[s.fecha_programada.slice(0, 10)] = porDia[s.fecha_programada.slice(0, 10)] || []).push(s));

    c.innerHTML = `<div class="row g-4"><div class="col-xl-7">
        <section class="panel"><div class="panel-cab"><h2>Citas por atender</h2><span class="ms-auto text-secondary small">${proximas.length} cita(s)</span></div>
        <div class="panel-cuerpo">${proximas.length ? Object.entries(porDia).map(([dia, lista]) => `<div class="agenda-dia"><h3>${diaRelativo(dia)}</h3>
            ${lista.map(s => `<a class="agenda-cita ${s.prioridad === 'Urgente' ? 'urgente' : ''}" href="#visita?s=${s.id_solicitud}">
                <div class="cita-hora"><strong>${hora(s.fecha_programada)}</strong></div>
                <div class="flex-grow-1 min-w-0"><div class="d-flex gap-2 align-items-center"><strong>${esc(s.residente_nombre)} ${esc(s.residente_apellido)}</strong>${s.prioridad === 'Urgente' ? etiqueta('urgente', 'Urgente') : ''}${s.visita_estado === 'En curso' ? etiqueta('atencion', 'En curso') : ''}</div>
                <p>${esc(s.motivo)}</p><p class="small">${s.residente_edad} años, remite ${esc(s.medico_general)}${s.enfermero ? `, acompaña ${esc(s.enfermero)}` : ''}</p></div>
                <span class="btn btn-primary btn-sm align-self-center">${s.visita_estado === 'En curso' ? 'Continuar' : 'Atender'}</span></a>`).join('')}</div>`).join('')
            : vacio('calendar-check', 'No tiene citas programadas. La fundación le asignará pacientes.')}</div></section></div>
        <div class="col-xl-5"><section class="panel"><div class="panel-cab"><h2>Atendidas</h2></div>
            ${atendidas.length ? `<div class="table-responsive"><table class="table tabla"><thead><tr><th>Fecha</th><th>Residente</th><th></th></tr></thead><tbody>
            ${atendidas.map(s => `<tr><td>${fmtFecha(s.fecha_programada)}</td><td>${esc(s.residente_nombre)} ${esc(s.residente_apellido)}</td>
                <td class="text-end"><a href="#visita?s=${s.id_solicitud}">Ver</a></td></tr>`).join('')}</tbody></table></div>`
            : vacio('clipboard2-check', 'Aún no ha atendido visitas.')}</section></div></div>`;
}

/* ================================================================
   VISITA MÉDICA (pantalla de trabajo del especialista)
   ================================================================ */
async function vistaVisita(c, params) {
    const idSol = +params.get('s');
    if (!idSol) { location.hash = '#agenda'; return; }
    const d = await api('visita_detalle', null, `&id_solicitud=${idSol}`);
    const { solicitud: s, visita: v, residente: r } = d;
    const esMio = puede(ROL.ADMIN) || (puede(ROL.ESPECIALISTA) && +s.id_medico_asignado === +U.id);
    const editable = esMio && v && v.estado === 'En curso';
    $('#tituloVista').textContent = `Visita: ${s.residente_nombre} ${s.residente_apellido}`;
    $('#accionesVista').innerHTML = `<a class="btn btn-link text-secondary" href="${puede(ROL.ESPECIALISTA) ? '#agenda' : '#solicitudes'}"><i class="bi bi-arrow-left me-1"></i>Volver</a>`;

    const alergias = d.padecimientos.filter(p => p.tipo === 'Alergia' && p.estado !== 'Resuelto');
    const activos = d.padecimientos.filter(p => p.tipo !== 'Alergia' && p.estado !== 'Resuelto');
    const perm = d.permanentes.filter(m => +m.activo);
    const ev = d.evaluaciones[0];

    // Costos con el descuento de la fundación
    const pct = v ? +v.descuento_pct : +d.descuento_pct;
    const neto = x => x * (1 - pct / 100);
    const totExam = v ? v.examenes.reduce((t, x) => t + +x.precio, 0) : 0;
    const totMed = v ? v.recetas.reduce((t, m) => t + m.cantidad * m.precio_unitario, 0) : 0;
    const totCons = v ? +v.costo_consulta : +s.costo_consulta;

    const columnaFicha = `
        <section class="panel mb-4"><div class="panel-cab"><h2>Solicitud del médico general</h2>${etiquetaPrioridad(s.prioridad)}</div><div class="panel-cuerpo">
            <p class="mb-2">${esc(s.motivo)}</p>
            <p class="small text-secondary mb-0">Remite ${esc(s.medico_general)} el ${fmtFecha(s.fecha_solicitud)}. ${s.fecha_programada ? `Cita: ${diaRelativo(s.fecha_programada)}, ${hora(s.fecha_programada)}.` : ''}
            ${s.enfermero ? `Acompaña ${esc(s.enfermero)}.` : ''}</p>
            ${s.notas_fundacion ? `<p class="small mt-2 mb-0 pre"><strong>Fundación:</strong> ${esc(s.notas_fundacion)}</p>` : ''}</div></section>
        <section class="panel mb-4"><div class="panel-cab"><h2>Ficha médica</h2><span class="ms-auto small text-secondary">${r.edad} años, ${esc(GENERO[r.genero])}</span></div><div class="panel-cuerpo">
            ${alergias.length ? `<div class="aviso-alergia"><i class="bi bi-exclamation-triangle-fill"></i> Alergia: <strong>${alergias.map(a => esc(a.descripcion)).join(', ')}</strong></div>` : ''}
            <h3 class="h6">Padecimientos y psicopatologías</h3>
            ${activos.length ? `<ul class="lista-simple">${activos.map(p => `<li><strong>${esc(p.descripcion)}</strong> <span class="text-secondary">${esc(p.tipo)}, ${esc(p.estado.toLowerCase())}</span></li>`).join('')}</ul>` : '<p class="text-secondary">Ninguno registrado.</p>'}
            <h3 class="h6 mt-3">Medicamentos permanentes</h3>
            ${perm.length ? `<ul class="lista-simple">${perm.map(m => `<li><strong>${esc(m.nombre_comercial)}</strong> <span class="text-secondary">${esc(m.dosis)}, ${esc(m.frecuencia.toLowerCase())}</span></li>`).join('')}</ul>` : '<p class="text-secondary">Ninguno.</p>'}
            ${ev ? `<h3 class="h6 mt-3">Última evaluación (${fmtFecha(ev.fecha_evaluacion)})</h3>
                <div class="signos mt-0"><span>IMC <b>${ev.imc}</b></span>${ev.presion_sistolica ? `<span>PA <b>${ev.presion_sistolica}/${ev.presion_diastolica}</b></span>` : ''}${ev.glucosa ? `<span>Glucosa <b>${Math.round(ev.glucosa)}</b></span>` : ''}${ev.ritmo_cardiaco ? `<span>Pulso <b>${ev.ritmo_cardiaco}</b></span>` : ''} ${etiquetaAlerta(ev.nivel_alerta)}</div>` : ''}
            <p class="small text-secondary mt-3 mb-0"><strong>Motivo de ingreso al asilo:</strong> ${esc(r.motivo_ingreso || '—')}</p>
        </div></section>
        ${d.historial.length ? `<section class="panel"><div class="panel-cab"><h2>Visitas anteriores</h2></div><div class="panel-cuerpo"><ul class="linea-tiempo">${d.historial.map(itemVisita).join('')}</ul></div></section>` : ''}`;

    let columnaVisita;
    if (!v) {
        const puedeIniciar = esMio && s.estado === 'Programada';
        columnaVisita = `<section class="panel"><div class="panel-cuerpo text-center py-5">
            <i class="bi bi-clipboard2-pulse display-6 text-secondary"></i>
            <h2 class="h4 mt-3">${puedeIniciar ? 'Cuando el residente llegue, inicie la visita' : 'La visita aún no ha comenzado'}</h2>
            <p class="text-secondary">${puedeIniciar ? 'La solicitud se convertirá en visita médica y podrá registrar el diagnóstico, exámenes y medicamentos.' : `Estado de la solicitud: ${esc(s.estado.toLowerCase())}.`}</p>
            ${puedeIniciar ? '<button class="btn btn-primary btn-lg" id="btnIniciarVisita">Iniciar visita</button>' : ''}</div></section>`;
    } else {
        const filaExamen = x => `<li><div class="flex-grow-1 min-w-0"><strong>${esc(x.nombre)}</strong>${x.indicaciones ? ` <span class="text-secondary small">${esc(x.indicaciones)}</span>` : ''}
            ${x.estado === 'Realizado' ? `<div class="small resultado-lab"><i class="bi bi-check2-circle"></i> ${esc(x.resultado)}</div>` : ''}</div>
            <span class="text-secondary small text-nowrap">${fmtQ(neto(+x.precio))}</span>
            ${x.estado === 'Realizado' ? etiqueta('normal', 'Con resultado') : etiqueta('atencion', 'En laboratorio')}
            ${editable && x.estado === 'Solicitado' ? `<button class="btn btn-icono btn-sm" data-quitar-ex="${x.id_examen_visita}" aria-label="Quitar ${esc(x.nombre)}"><i class="bi bi-x-lg"></i></button>` : ''}</li>`;
        const filaReceta = m => `<li><div class="flex-grow-1 min-w-0"><strong>${esc(m.nombre_comercial)}</strong> x ${m.cantidad}
            <div class="small">${esc(m.indicaciones)}${m.duracion_dias ? `, durante ${m.duracion_dias} días` : ''}</div></div>
            <span class="text-secondary small text-nowrap">${fmtQ(neto(m.cantidad * m.precio_unitario))}</span>
            ${m.estado === 'Entregado' ? etiqueta('normal', 'Entregado') : etiqueta('atencion', 'En farmacia')}
            ${editable && m.estado === 'Pendiente' ? `<button class="btn btn-icono btn-sm" data-quitar-rec="${m.id_receta}" aria-label="Quitar ${esc(m.nombre_comercial)}"><i class="bi bi-x-lg"></i></button>` : ''}</li>`;

        columnaVisita = `<form class="panel mb-4" id="formNotas" novalidate><div class="panel-cab"><h2>Visita del ${fmtFechaHora(v.fecha_visita)}</h2>
                ${v.estado === 'Finalizada' ? etiqueta('neutro', 'Finalizada') : etiqueta('atencion', 'En curso')}<span class="ms-auto small text-secondary">${esc(v.medico)}</span></div>
            <div class="panel-cuerpo">
                <label class="form-label ${editable ? 'requerido' : ''}" for="f_diag_v">Diagnóstico</label>
                <textarea class="form-control mb-3" id="f_diag_v" name="diagnostico" rows="3" maxlength="4000" ${editable ? '' : 'readonly'}>${esc(v.diagnostico || '')}</textarea>
                <label class="form-label" for="f_obs_v">Observaciones médicas</label>
                <textarea class="form-control" id="f_obs_v" name="observaciones" rows="3" maxlength="4000" ${editable ? '' : 'readonly'}>${esc(v.observaciones || '')}</textarea>
                ${editable ? '<button class="btn btn-outline-primary btn-sm mt-3" type="submit">Guardar notas</button>' : ''}
            </div></form>

        <section class="panel mb-4"><div class="panel-cab"><h2>Exámenes de laboratorio</h2></div><div class="panel-cuerpo">
            ${v.examenes.length ? `<ul class="lista-ficha">${v.examenes.map(filaExamen).join('')}</ul>` : '<p class="text-secondary">Sin exámenes solicitados.</p>'}
            ${editable ? `<form class="row g-2 align-items-end mt-1" id="formExamen" novalidate>
                ${selector({ nombre: 'id_examen', etiqueta: 'Solicitar examen', req: true, col: 'col-md-6', opciones: d.catalogo_examenes.map(x => ({ v: x.id_examen, t: `${x.nombre} (${fmtQ(x.precio)})` })) })}
                ${campo({ nombre: 'indicaciones', etiqueta: 'Indicaciones', col: 'col-md-4', attrs: 'maxlength="255" placeholder="En ayunas…"' })}
                <div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit">Agregar</button></div></form>` : ''}
        </div></section>

        <section class="panel mb-4"><div class="panel-cab"><h2>Medicamentos recetados</h2></div><div class="panel-cuerpo">
            ${v.recetas.length ? `<ul class="lista-ficha">${v.recetas.map(filaReceta).join('')}</ul>` : '<p class="text-secondary">Sin medicamentos recetados.</p>'}
            ${editable ? `<form class="row g-2 align-items-end mt-1" id="formReceta" novalidate>
                ${selector({ nombre: 'id_medicamento', etiqueta: 'Medicamento', req: true, col: 'col-md-6', opciones: d.catalogo_medicamentos.map(m => ({ v: m.id_medicamento, t: `${m.nombre_comercial} (${m.presentacion}, ${fmtQ(m.precio)})` })) })}
                ${campo({ nombre: 'cantidad', etiqueta: 'Cantidad', tipo: 'number', valor: 1, req: true, col: 'col-4 col-md-2', attrs: 'min="1" max="100"' })}
                ${campo({ nombre: 'duracion_dias', etiqueta: 'Días', tipo: 'number', col: 'col-8 col-md-4', attrs: 'min="1" max="365" placeholder="Opcional"' })}
                ${campo({ nombre: 'indicaciones', etiqueta: 'Cómo tomarlo', req: true, col: 'col-md-10', attrs: 'maxlength="255" placeholder="1 tableta cada 8 horas con alimentos"' })}
                <div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit">Recetar</button></div></form>` : ''}
        </div></section>

        <section class="panel"><div class="panel-cab"><h2>Costo para el familiar</h2><span class="ms-auto small text-secondary">Descuento de la fundación: ${pct} %</span></div>
            <div class="panel-cuerpo"><ul class="desglose">
                <li><span>Consulta de ${esc(s.especialidad)}</span><span>${fmtQ(neto(totCons))}</span></li>
                <li><span>Exámenes (${v.examenes.length})</span><span>${fmtQ(neto(totExam))}</span></li>
                <li><span>Medicamentos (${v.recetas.length})</span><span>${fmtQ(neto(totMed))}</span></li>
                <li class="total"><span>Total</span><span>${fmtQ(neto(totCons + totExam + totMed))}</span></li></ul>
                <p class="small text-secondary mt-2 mb-0">Precio de lista ${fmtQ(totCons + totExam + totMed)}. La consulta se carga al finalizar; los exámenes y medicamentos, cuando el laboratorio y la farmacia los registran.</p>
                ${editable ? '<button class="btn btn-primary btn-lg w-100 mt-4" id="btnFinalizar">Finalizar visita</button>' : ''}
            </div></section>`;
    }

    c.innerHTML = `<div class="row g-4"><div class="col-xl-5 order-2 order-xl-1">${columnaFicha}</div><div class="col-xl-7 order-1 order-xl-2">${columnaVisita}</div></div>`;

    // Al refrescar la pantalla se conserva lo que el médico escribió y aún no guardó
    const accion = async (fn, datos, ok = null) => {
        const borrador = $('#formNotas') ? datosForm($('#formNotas')) : null;
        try {
            const res = await api(fn, datos); aviso(ok || res.mensaje);
            await vistaVisita(c, params);
            if (borrador && fn !== 'visita_finalizar' && $('#f_diag_v') && !$('#f_diag_v').readOnly) {
                $('#f_diag_v').value = borrador.diagnostico; $('#f_obs_v').value = borrador.observaciones;
            }
        } catch (ex) { aviso(ex.message, 'error'); }
    };
    $('#btnIniciarVisita')?.addEventListener('click', () => accion('visita_iniciar', { id_solicitud: idSol }));
    $('#formNotas')?.addEventListener('submit', e => { e.preventDefault(); if (editable) accion('visita_guardar', { id_visita: v.id_visita, ...datosForm(e.currentTarget) }); });
    $('#formExamen')?.addEventListener('submit', e => {
        e.preventDefault();
        if (!e.currentTarget.checkValidity()) { aviso('Seleccione el examen.', 'aviso'); return; }
        accion('visita_examen_agregar', { id_visita: v.id_visita, ...datosForm(e.currentTarget) });
    });
    $('#formReceta')?.addEventListener('submit', e => {
        e.preventDefault();
        if (!e.currentTarget.checkValidity()) { e.currentTarget.classList.add('was-validated'); aviso('Complete el medicamento, la cantidad y cómo tomarlo.', 'aviso'); return; }
        accion('visita_receta_agregar', { id_visita: v.id_visita, ...datosForm(e.currentTarget) });
    });
    $$('[data-quitar-ex]', c).forEach(b => b.addEventListener('click', () => accion('visita_examen_quitar', { id_examen_visita: b.dataset.quitarEx })));
    $$('[data-quitar-rec]', c).forEach(b => b.addEventListener('click', () => accion('visita_receta_quitar', { id_receta: b.dataset.quitarRec })));
    $('#btnFinalizar')?.addEventListener('click', async () => {
        const datos = datosForm($('#formNotas'));
        if (!datos.diagnostico) { aviso('Escriba el diagnóstico antes de finalizar.', 'aviso'); $('#f_diag_v').focus(); return; }
        if (!await confirmar('¿Finalizar la visita?', 'Ya no podrá modificar el diagnóstico, los exámenes ni la receta. La consulta se cargará a la cuenta del familiar.', 'Finalizar visita')) return;
        accion('visita_finalizar', { id_visita: v.id_visita, ...datos });
    });
}

/* ================================================================
   ACOMPAÑAMIENTOS DEL ENFERMERO(A)
   ================================================================ */
async function vistaAcompanamientos(c) {
    const { solicitudes } = await api('solicitudes');
    const prox = solicitudes.filter(s => s.estado === 'Programada').sort((a, b) => a.fecha_programada.localeCompare(b.fecha_programada));
    const pend = solicitudes.filter(s => s.estado === 'Pendiente');
    const hechas = solicitudes.filter(s => s.estado === 'Atendida');
    const tarjeta = s => `<div class="agenda-cita estatica ${s.prioridad === 'Urgente' ? 'urgente' : ''}">
        <div class="cita-hora">${s.fecha_programada ? `<span>${diaRelativo(s.fecha_programada)}</span><strong>${hora(s.fecha_programada)}</strong>` : '<span>Sin fecha</span>'}</div>
        <div class="flex-grow-1 min-w-0"><div class="d-flex gap-2 align-items-center"><strong>${esc(s.residente_nombre)} ${esc(s.residente_apellido)}</strong>${s.numero_habitacion ? `<span class="text-secondary small">Hab. ${esc(s.numero_habitacion)}</span>` : ''}${s.prioridad === 'Urgente' ? etiqueta('urgente', 'Urgente') : ''}</div>
        <p>${esc(s.especialidad)}${s.medico_asignado ? ` con ${esc(s.medico_asignado)}` : ', médico por asignar'}</p><p class="small">${esc(s.motivo)}</p>
        ${s.notas_fundacion ? `<p class="small pre"><strong>Fundación:</strong> ${esc(s.notas_fundacion)}</p>` : ''}</div></div>`;
    c.innerHTML = `<section class="panel mb-4"><div class="panel-cab"><h2>Próximas citas a las que acompaña</h2></div><div class="panel-cuerpo">
        ${prox.length ? prox.map(tarjeta).join('') : vacio('calendar', 'No tiene acompañamientos programados.')}</div></section>
        ${pend.length ? `<section class="panel mb-4"><div class="panel-cab"><h2>Esperando horario de la fundación</h2></div><div class="panel-cuerpo">${pend.map(tarjeta).join('')}</div></section>` : ''}
        <section class="panel"><div class="panel-cab"><h2>Acompañamientos realizados</h2></div>
        ${hechas.length ? `<div class="table-responsive"><table class="table tabla"><thead><tr><th>Fecha</th><th>Residente</th><th>Especialidad</th><th>Médico</th></tr></thead><tbody>
            ${hechas.map(s => `<tr><td>${fmtFecha(s.fecha_programada)}</td><td>${esc(s.residente_nombre)} ${esc(s.residente_apellido)}</td><td>${esc(s.especialidad)}</td><td>${esc(s.medico_asignado)}</td></tr>`).join('')}</tbody></table></div>`
            : vacio('clipboard2-check', 'Todavía no hay acompañamientos realizados.')}</section>`;
}

/* ================================================================
   CONFIGURACIÓN (administrador): descuento, datos del asilo, correo
   ================================================================ */
async function vistaConfiguracion(c) {
    const d = await api('configuracion');
    const cfg = d.config;
    c.innerHTML = `<div class="row g-4"><div class="col-xl-5">
        <form class="panel mb-4" id="formConfig" novalidate><div class="panel-cab"><h2>Datos generales</h2></div><div class="panel-cuerpo"><div class="row g-3">
            ${campo({ nombre: 'nombre_asilo', etiqueta: 'Nombre del asilo', valor: cfg.nombre_asilo, req: true, col: 'col-12', attrs: 'maxlength="120"' })}
            ${campo({ nombre: 'telefono_asilo', etiqueta: 'Teléfono', valor: cfg.telefono_asilo, attrs: 'maxlength="20"' })}
            ${campo({ nombre: 'descuento_fundacion_pct', etiqueta: 'Descuento de la fundación (%)', tipo: 'number', valor: cfg.descuento_fundacion_pct, req: true, attrs: 'min="0" max="90" step="0.5"',
                ayuda: 'Se aplica a consultas, exámenes y medicamentos' })}
        </div><button class="btn btn-primary mt-3" type="submit">Guardar configuración</button></div></form>

        <section class="panel"><div class="panel-cab"><h2>Correo a familiares</h2>${d.correo.listo ? etiqueta('activo', 'Listo') : etiqueta('atencion', 'Sin configurar')}</div><div class="panel-cuerpo">
            <p>${esc(d.correo.mensaje)}</p>
            ${d.correo.listo ? '' : `<ol class="small text-secondary ps-3"><li>En la carpeta <code>config</code>, copie <code>correo.ejemplo.php</code> con el nombre <code>correo.php</code>.</li>
                <li>Escriba su correo de Gmail y la contraseña de aplicación de 16 letras.</li><li>Cambie <code>CORREO_ACTIVO</code> a <code>true</code> y guarde. Luego recargue esta página.</li></ol>`}
            <form class="row g-2 align-items-end" id="formProbar" novalidate>
                ${campo({ nombre: 'para', etiqueta: 'Enviar correo de prueba a', tipo: 'email', valor: d.mi_correo.endsWith('@asilo.com') ? '' : d.mi_correo, req: true, col: 'col-sm-8', attrs: 'placeholder="su.correo@gmail.com"' })}
                <div class="col-sm-4"><button class="btn btn-outline-primary w-100 text-nowrap" type="submit" id="btnProbar">Enviar prueba</button></div></form>
            <button class="btn btn-link px-0 mt-2" id="btnDiag"><i class="bi bi-activity me-1"></i>Diagnosticar conexión con Gmail</button>
            <ul class="lista-simple mt-2" id="resDiag"></ul>
        </div></section></div>

        <div class="col-xl-7"><section class="panel"><div class="panel-cab"><h2>Correos enviados</h2><span class="ms-auto small text-secondary">Últimos 100</span></div>
            ${d.correos.length ? `<div class="table-responsive"><table class="table tabla"><thead><tr><th>Fecha</th><th>Estado</th><th>Para</th><th>Asunto</th></tr></thead><tbody>
            ${d.correos.map(n => `<tr><td class="text-nowrap">${fmtFechaHora(n.fecha)}</td>
                <td>${n.estado === 'Enviado' ? etiqueta('activo', 'Enviado') : n.estado === 'Sin correo' ? etiqueta('neutro', 'Sin correo') : etiqueta('urgente', 'Error')}</td>
                <td>${esc(n.destinatario)}</td><td>${esc(n.asunto)}${n.detalle_error ? `<div class="small text-secondary">${esc(n.detalle_error)}</div>` : ''}</td></tr>`).join('')}</tbody></table></div>`
            : vacio('envelope', 'Aún no se ha enviado ningún correo.')}</section></div></div>`;

    $('#formConfig').addEventListener('submit', async e => {
        e.preventDefault();
        if (!e.currentTarget.checkValidity()) { e.currentTarget.classList.add('was-validated'); return; }
        try { const r = await api('configuracion_guardar', datosForm(e.currentTarget)); aviso(r.mensaje); } catch (ex) { aviso(ex.message, 'error'); }
    });
    $('#btnDiag').addEventListener('click', async () => {
        const b = $('#btnDiag'); b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Revisando (puede tardar unos segundos)…';
        try {
            const r = await api('correo_diagnostico', {});
            $('#resDiag').innerHTML = r.map(x => `<li><i class="bi bi-${x.ok ? 'check-circle-fill text-success' : 'x-circle-fill text-danger'} me-1"></i><strong>${esc(x.prueba)}</strong><div class="small text-secondary">${esc(x.detalle)}</div></li>`).join('');
        } catch (ex) { aviso(ex.message, 'error'); }
        b.disabled = false; b.innerHTML = '<i class="bi bi-activity me-1"></i>Diagnosticar conexión con Gmail';
    });
    $('#formProbar').addEventListener('submit', async e => {
        e.preventDefault();
        if (!e.currentTarget.checkValidity()) { aviso('Escriba un correo válido.', 'aviso'); return; }
        const b = $('#btnProbar'); b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        try { const r = await api('correo_probar', datosForm(e.currentTarget)); aviso(r.mensaje); }
        catch (ex) { aviso(ex.message, 'error'); }
        await vistaConfiguracion(c);
    });
}
