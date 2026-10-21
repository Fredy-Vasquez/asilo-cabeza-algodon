/* Asilo Cabeza de Algodón — Módulo de reportes (los 7 informes del enunciado).
   Cada reporte se filtra por residente y rango de fechas, se imprime / guarda en PDF con el navegador
   y su tabla principal se exporta a CSV (se abre en Excel). Depende de app.js y caja.js. */

const ICONO_REPORTE = {
    costos_citas: 'bar-chart-line', analisis_medico: 'clipboard2-pulse', cobros: 'wallet2', pagos_fundacion: 'bank',
    entradas: 'cash-stack', examenes: 'eyedropper', medicamentos: 'capsule',
};
let estadoReporte = { tipo: null, id_residente: '', desde: '', hasta: '' };
let ultimoReporte = null;

const tablaHTML = (encabezados, filas, pie = null, clase = '') => `<div class="table-responsive"><table class="table tabla tabla-reporte ${clase}">
    <thead><tr>${encabezados.map(h => `<th class="${h.d ? 'text-end' : ''}">${esc(h.t ?? h)}</th>`).join('')}</tr></thead>
    <tbody>${filas.length ? filas.join('') : `<tr><td colspan="${encabezados.length}" class="text-secondary">Sin registros en este rango.</td></tr>`}</tbody>
    ${pie ? `<tfoot><tr class="fila-total">${pie}</tr></tfoot>` : ''}</table></div>`;
const td = (v, der = false) => `<td class="${der ? 'text-end' : ''}">${v}</td>`;
const rangoTexto = f => f.desde || f.hasta ? `${f.desde ? fmtFecha(f.desde) : 'el inicio'} al ${f.hasta ? fmtFecha(f.hasta) : 'hoy'}` : 'Todo el historial';

/** Rangos rápidos */
function rangoRapido(clave) {
    const h = hoy(), [a, m] = h.split('-').map(Number);
    const pad = n => String(n).padStart(2, '0');
    if (clave === 'mes') return { desde: `${a}-${pad(m)}-01`, hasta: h };
    if (clave === 'anterior') { const d = new Date(a, m - 2, 1); const p = `${d.getFullYear()}-${pad(d.getMonth() + 1)}`; return { desde: `${p}-01`, hasta: finDeMes(p) }; }
    if (clave === 'anio') return { desde: `${a}-01-01`, hasta: h };
    return { desde: '', hasta: '' };
}

async function vistaReportes(c, params) {
    const op = await api('reportes_opciones');
    if (params.get('t') && op.reportes.some(r => r.tipo === params.get('t'))) estadoReporte.tipo = params.get('t');
    if (!op.reportes.some(r => r.tipo === estadoReporte.tipo)) estadoReporte.tipo = op.reportes[0]?.tipo || null;

    c.innerHTML = `<div class="no-imprimir">
        <div class="tarjetas-reporte" role="tablist" aria-label="Reportes disponibles">
        ${op.reportes.map(r => `<button type="button" role="tab" class="tarjeta-reporte ${r.tipo === estadoReporte.tipo ? 'activa' : ''}" data-tipo="${r.tipo}" aria-selected="${r.tipo === estadoReporte.tipo}">
            <i class="bi bi-${ICONO_REPORTE[r.tipo]}"></i><strong>${esc(r.titulo)}</strong><span>${esc(r.descripcion)}</span></button>`).join('')}
        </div>
        <form class="panel mb-4" id="formReporte" novalidate><div class="panel-cuerpo"><div class="row g-3 align-items-end">
            <div class="col-md-4"><label class="form-label" for="f_rep_res" id="lblRes">Residente</label>
                <select class="form-select" id="f_rep_res"><option value="">Todos los residentes</option>
                ${op.residentes.map(r => `<option value="${r.id}" ${String(r.id) === String(estadoReporte.id_residente) ? 'selected' : ''}>${esc(r.nombre)}${r.estado === 'inactivo' ? ' (de baja)' : ''}</option>`).join('')}</select></div>
            <div class="col-6 col-md-2"><label class="form-label" for="f_rep_desde">Desde</label><input type="date" class="form-control" id="f_rep_desde" value="${estadoReporte.desde}"></div>
            <div class="col-6 col-md-2"><label class="form-label" for="f_rep_hasta">Hasta</label><input type="date" class="form-control" id="f_rep_hasta" value="${estadoReporte.hasta}"></div>
            <div class="col-md-4 d-flex gap-2"><button class="btn btn-primary flex-grow-1" type="submit"><i class="bi bi-file-earmark-bar-graph me-1"></i>Generar reporte</button></div>
            <div class="col-12 d-flex flex-wrap gap-2 small"><span class="text-secondary">Rango rápido:</span>
                ${[['mes', 'Este mes'], ['anterior', 'Mes anterior'], ['anio', 'Este año'], ['todo', 'Todo']].map(([k, t]) => `<button type="button" class="btn btn-link btn-sm p-0" data-rango="${k}">${t}</button>`).join(' · ')}</div>
        </div></div></form></div>
        <div id="hojaReporte">${vacio('file-earmark-bar-graph', 'Elija un reporte y presione «Generar reporte».')}</div>`;

    const actualizarRequisito = () => {
        const r = op.reportes.find(x => x.tipo === estadoReporte.tipo);
        const sel = $('#f_rep_res');
        sel.required = !!r?.requiere_residente;
        sel.options[0].textContent = r?.requiere_residente ? 'Seleccione un residente…' : 'Todos los residentes';
        $('#lblRes').classList.toggle('requerido', !!r?.requiere_residente);
        $('#f_rep_res').disabled = r?.tipo === 'entradas';
        $('#lblRes').classList.toggle('opacity-50', r?.tipo === 'entradas');
    };
    $$('.tarjeta-reporte', c).forEach(b => b.addEventListener('click', () => {
        estadoReporte.tipo = b.dataset.tipo;
        $$('.tarjeta-reporte', c).forEach(x => { x.classList.toggle('activa', x === b); x.setAttribute('aria-selected', x === b); });
        actualizarRequisito();
        $('#hojaReporte').innerHTML = vacio(ICONO_REPORTE[b.dataset.tipo], 'Ajuste los filtros y presione «Generar reporte».');
    }));
    $$('[data-rango]', c).forEach(b => b.addEventListener('click', () => {
        const r = rangoRapido(b.dataset.rango);
        $('#f_rep_desde').value = r.desde; $('#f_rep_hasta').value = r.hasta;
    }));
    $('#formReporte').addEventListener('submit', async e => {
        e.preventDefault();
        estadoReporte.id_residente = $('#f_rep_res').value;
        estadoReporte.desde = $('#f_rep_desde').value;
        estadoReporte.hasta = $('#f_rep_hasta').value;
        if ($('#f_rep_res').required && !estadoReporte.id_residente) { aviso('Este reporte es por paciente: seleccione un residente.', 'error'); $('#f_rep_res').focus(); return; }
        await generarReporte();
    });
    actualizarRequisito();
    if (estadoReporte.tipo && (estadoReporte.id_residente || !op.reportes.find(x => x.tipo === estadoReporte.tipo)?.requiere_residente)) await generarReporte();
}

async function generarReporte() {
    const h = $('#hojaReporte');
    h.innerHTML = '<div class="cargando"><div class="spinner-border text-success" role="status"><span class="visually-hidden">Generando…</span></div></div>';
    const q = new URLSearchParams({ tipo: estadoReporte.tipo });
    if (estadoReporte.id_residente && estadoReporte.tipo !== 'entradas') q.set('id_residente', estadoReporte.id_residente);
    if (estadoReporte.desde) q.set('desde', estadoReporte.desde);
    if (estadoReporte.hasta) q.set('hasta', estadoReporte.hasta);
    try {
        const r = await api('reporte', null, '&' + q.toString());
        ultimoReporte = r;
        const cuerpo = RENDER_REPORTE[r.tipo](r.datos);
        h.innerHTML = `<article class="hoja-reporte panel">
            <header class="hoja-cab">
                <div class="d-flex align-items-start gap-3"><img class="hoja-logo" src="assets/img/manos-algodon.svg" alt="">
                    <div><p class="hoja-asilo">${esc(r.asilo)}</p><h2>${esc(r.titulo)}</h2>
                    <p class="hoja-filtros">${r.filtros.residente ? `<strong>Residente:</strong> ${esc(r.filtros.residente)} · ` : ''}<strong>Período:</strong> ${rangoTexto(r.filtros)}</p></div></div>
                <div class="hoja-acciones no-imprimir">
                    <button class="btn btn-outline-primary btn-sm" id="btnCsv"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Exportar CSV</button>
                    <button class="btn btn-primary btn-sm" id="btnPdf"><i class="bi bi-printer me-1"></i>Imprimir / PDF</button></div>
            </header>
            <div class="hoja-cuerpo">${cuerpo}</div>
            <footer class="hoja-pie">Generado el ${fmtFechaHora(r.generado.fecha)} por ${esc(r.generado.por)} · Sistema de administración del ${esc(r.asilo)}</footer>
        </article>`;
        $('#btnPdf').onclick = () => window.print();
        $('#btnCsv').onclick = () => exportarCsv(r);
    } catch (ex) {
        h.innerHTML = `<div class="alert alert-danger">${esc(ex.message)}</div>`;
    }
}

/* ---------- Cifras de encabezado de cada reporte ---------- */
const cifrasReporte = items => `<div class="cifras cifras-reporte">${items.map(([v, t]) => `<div><div class="cifra">${v}</div><p>${t}</p></div>`).join('')}</div>`;

const RENDER_REPORTE = {
    /* 1. Costos de cada cita por paciente */
    costos_citas(d) {
        const max = Math.max(0, ...d.por_paciente.map(p => p.total));
        const maxE = Math.max(0, ...d.por_especialidad.map(p => p.total));
        const item = (nombre, bruto, neto, cobrado, extra = '') => `<li class="${cobrado ? '' : 'pendiente'}"><span>${esc(nombre)}${extra}${cobrado ? '' : ' <em>(pendiente, no cobrado)</em>'}</span>
            <span class="text-nowrap">${+bruto !== +neto ? `<s>${fmtQ(bruto)}</s> ` : ''}${fmtQ(neto)}</span></li>`;
        return cifrasReporte([[d.resumen.citas, 'citas'], [fmtQ(d.resumen.total), 'costo total para los familiares'], [fmtQ(d.resumen.promedio), 'promedio por cita'], [fmtQ(d.resumen.ahorro), 'ahorro por el descuento de la fundación']]) +
        `<div class="row g-4 mb-4"><div class="col-xxl-7"><h3 class="subtitulo">Costo por paciente</h3>
            ${tablaHTML(['Paciente', { t: 'Citas', d: 1 }, { t: 'Consultas', d: 1 }, { t: 'Exámenes', d: 1 }, { t: 'Medicamentos', d: 1 }, { t: 'Total', d: 1 }],
                d.por_paciente.map(p => `<tr>${td(`${esc(p.paciente)}${barra(p.total, max)}`)}${td(p.citas, 1)}${td(fmtQ(p.consultas), 1)}${td(fmtQ(p.examenes), 1)}${td(fmtQ(p.medicamentos), 1)}${td(`<strong>${fmtQ(p.total)}</strong>`, 1)}</tr>`))}</div>
        <div class="col-xxl-5"><h3 class="subtitulo">Por especialidad</h3>
            ${tablaHTML(['Especialidad', { t: 'Citas', d: 1 }, { t: 'Total', d: 1 }],
                d.por_especialidad.map(p => `<tr>${td(`${esc(p.especialidad)}${barra(p.total, maxE)}`)}${td(p.citas, 1)}${td(fmtQ(p.total), 1)}</tr>`))}</div></div>
        <h3 class="subtitulo">Detalle de cada cita</h3>
        ${d.citas.length ? d.citas.map(c => `<section class="cita-reporte">
            <div class="d-flex flex-wrap justify-content-between gap-2"><div><strong>${esc(c.nombre)} ${esc(c.apellido)}</strong> · ${esc(c.especialidad)} con ${esc(c.medico)}
                <small class="d-block text-secondary">${fmtFecha(c.fecha_visita)} · ${c.estado === 'Finalizada' ? 'Visita finalizada' : 'Visita en curso'}${c.diagnostico ? ` · Diagnóstico: ${esc(c.diagnostico)}` : ''}</small></div>
                <div class="text-end"><strong class="fs-5">${fmtQ(c.total)}</strong><small class="d-block text-secondary">de ${fmtQ(c.total_bruto)}, ahorro ${fmtQ(c.ahorro)}</small></div></div>
            <ul class="lista-costos">${item('Consulta de ' + c.especialidad, c.consulta.bruto, c.consulta.neto || c.consulta.bruto * (1 - c.descuento_pct / 100), c.consulta.cobrada)}
                ${c.examenes.map(x => item('Examen: ' + x.nombre, x.precio, x.neto, x.cobrado)).join('')}
                ${c.medicamentos.map(x => item(`Medicamento: ${x.nombre}`, x.bruto, x.neto, x.cobrado, ` × ${x.cantidad}`)).join('')}</ul></section>`).join('')
            : '<p class="text-secondary">No hay citas en este rango.</p>'}`;
    },

    /* 2. Análisis médico por paciente */
    analisis_medico(d) {
        const r = d.residente;
        return `<div class="ficha-reporte">
            <dl class="dl-reporte"><dt>Residente</dt><dd><strong>${esc(r.nombre)} ${esc(r.apellido)}</strong>, ${r.edad} años, ${esc(GENERO[r.genero] || r.genero || '')}</dd>
                <dt>DPI</dt><dd>${esc(r.documento_id || '—')}</dd>
                <dt>Ingreso</dt><dd>${fmtFecha(r.fecha_ingreso)} · Hab. ${esc(r.numero_habitacion || '—')} · ${esc(r.nombre_plan || 'Sin plan')} · ${esc(TEXTO_ESTADO[r.estado] || r.estado)}</dd>
                <dt>Familiar</dt><dd>${esc(r.familiar_nombre)} ${esc(r.familiar_apellido)} (${esc(r.familiar_parentesco || '')}), ${esc(r.familiar_telefono || '')}</dd>
                <dt>Motivo de ingreso</dt><dd class="pre">${esc(r.motivo_ingreso || '—')}</dd>
                ${r.observaciones ? `<dt>Observaciones</dt><dd class="pre">${esc(r.observaciones)}</dd>` : ''}</dl></div>
        <div class="row g-4"><div class="col-lg-6"><h3 class="subtitulo">Padecimientos y psicopatologías</h3>
            ${tablaHTML(['Tipo', 'Descripción', 'Estado', 'Diagnóstico'], d.padecimientos.map(p => `<tr>${td(esc(p.tipo))}${td(esc(p.descripcion))}${td(esc(p.estado))}${td(fmtFecha(p.fecha_diagnostico))}</tr>`))}</div>
        <div class="col-lg-6"><h3 class="subtitulo">Medicamentos permanentes (de cajón)</h3>
            ${tablaHTML(['Medicamento', 'Dosis', 'Frecuencia', 'Desde'], d.permanentes.filter(m => m.activo == 1).map(m => `<tr>${td(esc(m.nombre_comercial))}${td(esc(m.dosis))}${td(esc(m.frecuencia))}${td(fmtFecha(m.fecha_inicio))}</tr>`))}</div></div>
        <h3 class="subtitulo mt-4">Evaluaciones físicas</h3>
        ${tablaHTML(['Fecha', { t: 'Peso', d: 1 }, { t: 'IMC', d: 1 }, 'Presión', { t: 'Glucosa', d: 1 }, 'Alerta', 'Observación'],
            d.evaluaciones.map(e => `<tr>${td(fmtFecha(e.fecha_evaluacion))}${td(`${e.peso_kg} kg`, 1)}${td(`${e.imc}`, 1)}${td(e.presion_sistolica ? `${e.presion_sistolica}/${e.presion_diastolica}` : '—')}${td(e.glucosa ?? '—', 1)}${td(esc(TEXTO_ALERTA[e.nivel_alerta] || e.nivel_alerta))}${td(esc(e.diagnostico || ''))}</tr>`))}
        <h3 class="subtitulo mt-4">Visitas médicas</h3>
        ${d.visitas.length ? d.visitas.map(v => `<section class="cita-reporte"><strong>${fmtFecha(v.fecha_visita)} · ${esc(v.especialidad)} con ${esc(v.medico)}</strong>
            <dl class="dl-reporte mt-2"><dt>Motivo</dt><dd>${esc(v.motivo)}</dd><dt>Diagnóstico</dt><dd>${esc(v.diagnostico || 'Visita en curso')}</dd>
            ${v.observaciones ? `<dt>Observaciones</dt><dd>${esc(v.observaciones)}</dd>` : ''}
            ${v.examenes.length ? `<dt>Exámenes</dt><dd>${v.examenes.map(x => `${esc(x.nombre)}: ${x.estado === 'Realizado' ? esc(x.resultado) : '<em>pendiente</em>'}`).join('<br>')}</dd>` : ''}
            ${v.recetas.length ? `<dt>Medicamentos</dt><dd>${v.recetas.map(m => `${esc(m.nombre_comercial)} × ${m.cantidad}: ${esc(m.indicaciones)}${m.duracion_dias ? `, ${m.duracion_dias} días` : ''}`).join('<br>')}</dd>` : ''}</dl></section>`).join('')
            : '<p class="text-secondary">Sin visitas en este rango.</p>'}`;
    },

    /* 3. Cobros por paciente por rango de fechas */
    cobros(d) {
        const s = d.resumen;
        return cifrasReporte([[fmtQ(s.cargos), 'cargado a las cuentas'], [fmtQ(s.gastos_medicos), 'de gastos médicos'], [fmtQ(s.pagos), 'pagado por los familiares'], [fmtQ(s.saldo), 'saldo pendiente al cierre']]) +
        (d.pacientes.length ? d.pacientes.map(p => `<section class="cita-reporte">
            <div class="d-flex flex-wrap justify-content-between gap-2 mb-2"><strong class="fs-6">${esc(p.paciente)}</strong>
                <span>Cargado ${fmtQ(p.total_cargos)} · Pagado ${fmtQ(p.total_pagos)} · <strong>Saldo ${fmtQ(p.saldo)}</strong></span></div>
            ${tablaHTML(['Fecha', 'Tipo', 'Concepto', { t: 'Precio', d: 1 }, { t: 'Descuento', d: 1 }, { t: 'Cargo', d: 1 }],
                p.cargos.map(c => `<tr>${td(fmtFecha(c.fecha))}${td(esc(c.tipo))}${td(esc(c.concepto))}${td(fmtQ(c.monto_bruto), 1)}${td(+c.descuento ? fmtQ(c.descuento) : '—', 1)}${td(fmtQ(c.monto), 1)}</tr>`),
                `<td colspan="3">Total cargado (gastos médicos: ${fmtQ(p.gastos_medicos)})</td><td></td><td class="text-end">${fmtQ(p.descuentos)}</td><td class="text-end">${fmtQ(p.total_cargos)}</td>`)}
            ${p.pagos.length ? tablaHTML(['Fecha del pago', 'Forma', 'Referencia', { t: 'Monto', d: 1 }],
                p.pagos.map(x => `<tr>${td(fmtFecha(x.fecha))}${td(esc(x.metodo))}${td(esc(x.referencia || '—'))}${td(fmtQ(x.monto), 1)}</tr>`),
                `<td colspan="3">Total pagado</td><td class="text-end">${fmtQ(p.total_pagos)}</td>`) : '<p class="small text-secondary">Sin pagos en este rango.</p>'}</section>`).join('')
            : '<p class="text-secondary">No hay cobros en este rango.</p>');
    },

    /* 4. Pagos a la fundación */
    pagos_fundacion(d) {
        const s = d.resumen;
        return cifrasReporte([[fmtQ(s.pagado), `pagado en ${s.pagos} pago(s)`], [fmtQ(s.pendiente), 'pendiente de pagar'], [s.citas_pendientes, 'citas no canceladas completamente']]) +
        `<h3 class="subtitulo">Pagos realizados</h3>
        ${tablaHTML(['Fecha', 'Cita', 'Residente', 'Forma', 'Referencia', { t: 'Monto', d: 1 }],
            d.pagos.map(p => `<tr>${td(fmtFecha(p.fecha))}${td(`${esc(p.especialidad)}, ${fmtFecha(p.fecha_visita)}<small class="d-block text-secondary">${esc(p.medico)}</small>`)}${td(`${esc(p.nombre)} ${esc(p.apellido)}`)}${td(esc(p.metodo))}${td(esc(p.referencia || '—'))}${td(fmtQ(p.monto), 1)}</tr>`),
            `<td colspan="5">Total pagado</td><td class="text-end">${fmtQ(s.pagado)}</td>`)}
        ${Object.keys(d.por_mes).length > 1 ? `<h3 class="subtitulo mt-3">Por mes</h3>${tablaHTML(['Mes', { t: 'Pagado', d: 1 }], Object.entries(d.por_mes).map(([m, v]) => `<tr>${td(nombreMes(m))}${td(fmtQ(v), 1)}</tr>`))}` : ''}
        <h3 class="subtitulo mt-4">Citas aún no canceladas completamente</h3>
        ${tablaHTML(['Cita', 'Residente', { t: 'Total', d: 1 }, { t: 'Pagado', d: 1 }, { t: 'Pendiente', d: 1 }, 'Estado'],
            d.pendientes.map(c => `<tr>${td(`${esc(c.especialidad)}, ${fmtFecha(c.fecha_visita)}`)}${td(`${esc(c.residente_nombre)} ${esc(c.residente_apellido)}`)}${td(fmtQ(c.adeudado), 1)}${td(fmtQ(c.pagado), 1)}${td(`<strong>${fmtQ(c.saldo)}</strong>`, 1)}${td(esc(c.estado_pago))}</tr>`),
            d.pendientes.length ? `<td colspan="4">Total pendiente</td><td class="text-end">${fmtQ(s.pendiente)}</td><td></td>` : null)}`;
    },

    /* 5. Entradas: donaciones y cobros */
    entradas(d) {
        const s = d.resumen;
        const maxT = Math.max(0, ...Object.values(d.por_tipo).map(x => x.dinero));
        return cifrasReporte([[fmtQ(s.total), 'entradas de dinero'], [fmtQ(s.donaciones), 'donaciones en dinero'], [fmtQ(s.cobros), 'cobros a familiares'], [fmtQ(s.especie), 'donado en especie (estimado)']]) +
        `<div class="row g-4 mb-3"><div class="col-lg-6"><h3 class="subtitulo">Donaciones por tipo de donante</h3>
            ${tablaHTML(['Tipo de donante', { t: 'Donaciones', d: 1 }, { t: 'En dinero', d: 1 }, { t: 'En especie', d: 1 }],
                Object.entries(d.por_tipo).map(([t, x]) => `<tr>${td(`${esc(t)}${barra(x.dinero, maxT)}`)}${td(x.cantidad, 1)}${td(fmtQ(x.dinero), 1)}${td(x.especie ? fmtQ(x.especie) : '—', 1)}</tr>`))}</div>
        <div class="col-lg-6"><h3 class="subtitulo">Entradas por mes</h3>
            ${tablaHTML(['Mes', { t: 'Donaciones', d: 1 }, { t: 'Cobros', d: 1 }, { t: 'Total', d: 1 }],
                Object.entries(d.por_mes).map(([m, x]) => `<tr>${td(nombreMes(m))}${td(fmtQ(x.donaciones || 0), 1)}${td(fmtQ(x.cobros || 0), 1)}${td(fmtQ((x.donaciones || 0) + (x.cobros || 0)), 1)}</tr>`))}</div></div>
        <h3 class="subtitulo">Detalle de donaciones</h3>
        ${tablaHTML(['Fecha', 'Donante', 'Tipo de donante', 'Tipo', 'Descripción', { t: 'Monto', d: 1 }],
            d.donaciones.map(x => `<tr>${td(fmtFecha(x.fecha))}${td(esc(x.donante))}${td(esc(x.tipo_donante))}${td(esc(x.tipo_donacion))}${td(esc(x.descripcion || ''))}${td(fmtQ(x.monto), 1)}</tr>`))}
        <h3 class="subtitulo mt-3">Detalle de cobros a familiares</h3>
        ${tablaHTML(['Fecha', 'Residente', 'Familiar', 'Forma', 'Referencia', { t: 'Monto', d: 1 }],
            d.cobros.map(x => `<tr>${td(fmtFecha(x.fecha))}${td(`${esc(x.nombre)} ${esc(x.apellido)}`)}${td(esc(x.familiar))}${td(esc(x.metodo))}${td(esc(x.referencia || '—'))}${td(fmtQ(x.monto), 1)}</tr>`),
            `<td colspan="5">Total cobrado</td><td class="text-end">${fmtQ(s.cobros)}</td>`)}`;
    },

    /* 6. Exámenes realizados por paciente */
    examenes(d) {
        const s = d.resumen;
        return cifrasReporte([[s.cantidad, 'exámenes realizados'], [s.pacientes, 'pacientes'], [fmtQ(s.costo), 'costo para los familiares']]) +
        (Object.keys(d.por_tipo).length ? `<p class="small text-secondary">${Object.entries(d.por_tipo).map(([k, v]) => `${esc(k)}: ${v}`).join(' · ')}</p>` : '') +
        tablaHTML(['Fecha', 'Paciente', 'Examen', 'Resultado', 'Solicitó', 'Realizó', { t: 'Costo', d: 1 }],
            d.examenes.map(e => `<tr>${td(fmtFecha(e.fecha_resultado))}${td(`${esc(e.nombre)} ${esc(e.apellido)}`)}${td(`<strong>${esc(e.examen)}</strong>`)}${td(`<span class="pre">${esc(e.resultado)}</span>`)}${td(`${esc(e.medico)}<small class="d-block text-secondary">${esc(e.especialidad)}</small>`)}${td(esc(e.laboratorista || '—'))}${td(fmtQ(e.neto), 1)}</tr>`),
            `<td colspan="6">Total</td><td class="text-end">${fmtQ(s.costo)}</td>`);
    },

    /* 7. Medicamentos aplicados por paciente */
    medicamentos(d) {
        const s = d.resumen;
        return cifrasReporte([[s.entregas, 'entregas de farmacia'], [s.unidades, 'unidades entregadas'], [fmtQ(s.costo), 'costo para los familiares'], [s.permanentes, 'medicamentos permanentes vigentes']]) +
        `<h3 class="subtitulo">Entregados por farmacia (recetados en visitas)</h3>
        ${tablaHTML(['Entrega', 'Paciente', 'Medicamento', { t: 'Cantidad', d: 1 }, 'Cómo tomarlo', 'Recetó', { t: 'Costo', d: 1 }],
            d.entregados.map(m => `<tr>${td(fmtFecha(m.fecha_entrega))}${td(`${esc(m.nombre)} ${esc(m.apellido)}`)}${td(`<strong>${esc(m.nombre_comercial)}</strong><small class="d-block text-secondary">${esc(m.presentacion)}</small>`)}${td(m.cantidad, 1)}${td(`${esc(m.indicaciones)}${m.duracion_dias ? `, ${m.duracion_dias} días` : ''}`)}${td(`${esc(m.medico)}<small class="d-block text-secondary">${esc(m.especialidad)}</small>`)}${td(fmtQ(m.neto), 1)}</tr>`),
            `<td colspan="6">Total</td><td class="text-end">${fmtQ(s.costo)}</td>`)}
        <h3 class="subtitulo mt-4">Medicamentos permanentes (de cajón) vigentes</h3>
        ${tablaHTML(['Paciente', 'Medicamento', 'Dosis', 'Frecuencia', 'Indicaciones', 'Desde'],
            d.permanentes.map(m => `<tr>${td(`${esc(m.nombre)} ${esc(m.apellido)}`)}${td(esc(m.nombre_comercial))}${td(esc(m.dosis))}${td(esc(m.frecuencia))}${td(esc(m.indicaciones || ''))}${td(fmtFecha(m.fecha_inicio))}</tr>`))}`;
    },
};

/* ---------- Exportar a CSV (tabla principal de cada reporte) ---------- */
const FILAS_CSV = {
    costos_citas: d => [['Fecha', 'Paciente', 'Especialidad', 'Médico', 'Consulta', 'Exámenes', 'Medicamentos', 'Total', 'Precio sin descuento', 'Ahorro'],
        ...d.citas.map(c => [c.fecha_visita.slice(0, 10), `${c.nombre} ${c.apellido}`, c.especialidad, c.medico, c.consulta.neto, c.total_examenes, c.total_medicamentos, c.total, c.total_bruto, c.ahorro])],
    analisis_medico: d => [['Fecha', 'Especialidad', 'Médico', 'Motivo', 'Diagnóstico', 'Observaciones'],
        ...d.visitas.map(v => [v.fecha_visita.slice(0, 10), v.especialidad, v.medico, v.motivo, v.diagnostico || '', v.observaciones || ''])],
    cobros: d => [['Paciente', 'Fecha', 'Tipo', 'Concepto', 'Precio', 'Descuento', 'Cargo', 'Pago'],
        ...d.pacientes.flatMap(p => [...p.cargos.map(c => [p.paciente, c.fecha, c.tipo, c.concepto, c.monto_bruto, c.descuento, c.monto, '']),
            ...p.pagos.map(x => [p.paciente, x.fecha, 'Pago', `${x.metodo} ${x.referencia || ''}`.trim(), '', '', '', x.monto])])],
    pagos_fundacion: d => [['Fecha', 'Residente', 'Especialidad', 'Fecha de la cita', 'Forma', 'Referencia', 'Monto'],
        ...d.pagos.map(p => [p.fecha, `${p.nombre} ${p.apellido}`, p.especialidad, p.fecha_visita.slice(0, 10), p.metodo, p.referencia || '', p.monto])],
    entradas: d => [['Fecha', 'Origen', 'Detalle', 'Tipo', 'Monto'],
        ...d.donaciones.map(x => [x.fecha, `Donación: ${x.tipo_donante}`, x.donante, x.tipo_donacion, x.monto]),
        ...d.cobros.map(x => [x.fecha, 'Cobro a familiar', `${x.nombre} ${x.apellido}`, x.metodo, x.monto])],
    examenes: d => [['Fecha', 'Paciente', 'Examen', 'Resultado', 'Solicitó', 'Realizó', 'Costo'],
        ...d.examenes.map(e => [(e.fecha_resultado || '').slice(0, 10), `${e.nombre} ${e.apellido}`, e.examen, e.resultado, e.medico, e.laboratorista || '', e.neto])],
    medicamentos: d => [['Entrega', 'Paciente', 'Medicamento', 'Cantidad', 'Indicaciones', 'Recetó', 'Costo'],
        ...d.entregados.map(m => [(m.fecha_entrega || '').slice(0, 10), `${m.nombre} ${m.apellido}`, m.nombre_comercial, m.cantidad, m.indicaciones, m.medico, m.neto])],
};

function exportarCsv(r) {
    const filas = FILAS_CSV[r.tipo](r.datos);
    const celda = v => { const s = String(v ?? ''); return /[",\n;]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s; };
    const csv = '﻿' + filas.map(f => f.map(celda).join(',')).join('\r\n');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    a.download = `${r.tipo}_${hoy()}.csv`;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
    aviso('Archivo CSV descargado. Ábralo con Excel.');
}
