/* Asilo Cabeza de Algodón — Servicios clínicos: laboratorio y farmacia.
   Depende de app.js (api, esc, etiqueta, abrirForm, confirmar…) y atencion.js (diaRelativo). */

const pct = n => `${Number(n || 0).toLocaleString('es-GT', { maximumFractionDigits: 2 })} %`;
const textoArea = ({ nombre, etiqueta: et, valor = '', req = false, filas = 4, attrs = '', ayuda = '' }) =>
    `<div class="col-12"><label class="form-label ${req ? 'requerido' : ''}" for="f_${nombre}">${et}</label>
        <textarea class="form-control" id="f_${nombre}" name="${nombre}" rows="${filas}" ${req ? 'required' : ''} ${attrs}>${esc(valor)}</textarea>
        ${ayuda ? `<div class="form-text">${ayuda}</div>` : ''}</div>`;
const personaFila = x => `<div class="persona"><span class="avatar">${esc(iniciales(x.residente_nombre, x.residente_apellido))}</span>
    <div><strong>${esc(x.residente_nombre)} ${esc(x.residente_apellido)}</strong><small>${x.numero_habitacion ? `Hab. ${esc(x.numero_habitacion)}` : 'Sin habitación'}</small></div></div>`;

/** Barra de filtros con búsqueda y botones de estado; devuelve el HTML */
function barraFiltros(id, filtro, opciones, conteo, placeholder) {
    return `<div class="filtros w-100">
        <input type="search" class="form-control" id="buscar_${id}" placeholder="${placeholder}" aria-label="Buscar" value="${esc(filtro.q)}">
        <div class="btn-group flex-wrap" role="group" aria-label="Filtrar por estado">
        ${opciones.map(([v, t]) => `<input type="radio" class="btn-check" name="est_${id}" id="${id}_${v || 'todos'}" value="${v}" ${filtro.estado === v ? 'checked' : ''}>
            <label class="btn btn-outline-secondary btn-sm" for="${id}_${v || 'todos'}">${t} <span class="opacity-75">${conteo[v] ?? 0}</span></label>`).join('')}
        </div></div>`;
}
function activarFiltros(id, filtro, pintar) {
    $(`#buscar_${id}`).addEventListener('input', e => { filtro.q = e.target.value; pintar(); });
    $$(`input[name=est_${id}]`).forEach(i => i.addEventListener('change', () => { filtro.estado = i.value; pintar(); }));
}
const contar = (lista, estados) => Object.fromEntries([...estados.map(e => [e, lista.filter(x => x.estado === e).length]), ['', lista.length]]);

/* ================================================================
   LABORATORIO
   ================================================================ */
let filtroLab = { estado: 'Solicitado', q: '' };

async function vistaLaboratorio(c) {
    if (puede(ROL.ADMIN, ROL.LABORATORIO)) {
        $('#accionesVista').innerHTML = `<a class="btn btn-outline-primary" href="#examenes"><i class="bi bi-list-check me-1"></i> Catálogo de exámenes</a>`;
    }
    const { examenes } = await api('laboratorio');
    const conteo = contar(examenes, ['Solicitado', 'Realizado']);
    c.innerHTML = `${conteo.Solicitado ? `<div class="banda-aviso"><i class="bi bi-eyedropper"></i> Hay <strong>${conteo.Solicitado}</strong> examen(es) esperando resultado.</div>` : ''}
    <section class="panel"><div class="panel-cab">${barraFiltros('lab', filtroLab, [['Solicitado', 'Por realizar'], ['Realizado', 'Con resultado'], ['', 'Todos']], conteo, 'Buscar por residente, examen o médico')}</div>
        <div class="table-responsive"><table class="table tabla">
        <thead><tr><th>Residente</th><th>Examen</th><th>Lo solicitó</th><th class="text-end">Cargo al familiar</th><th>Resultado</th></tr></thead>
        <tbody id="tbLab"></tbody></table></div></section>`;

    const pintar = () => {
        const q = filtroLab.q.toLowerCase();
        const filas = examenes.filter(x => (!filtroLab.estado || x.estado === filtroLab.estado) &&
            (!q || `${x.residente_nombre} ${x.residente_apellido} ${x.examen} ${x.medico}`.toLowerCase().includes(q)));
        $('#tbLab').innerHTML = filas.length ? filas.map(x => `<tr>
            <td>${personaFila(x)}</td>
            <td><strong>${esc(x.examen)}</strong>${x.indicaciones ? `<small class="d-block text-secondary">${esc(x.indicaciones)}</small>` : ''}</td>
            <td>${esc(x.medico)}<small class="d-block text-secondary">${esc(x.especialidad)}, ${fmtFecha(x.fecha_visita)}</small></td>
            <td class="text-end text-nowrap"><strong>${fmtQ(x.precio_neto)}</strong><small class="d-block text-secondary">de ${fmtQ(x.precio)}, desc. ${pct(x.descuento_pct)}</small></td>
            <td>${x.estado === 'Realizado'
                ? `<div class="resultado-lab small"><i class="bi bi-check2-circle"></i> ${esc(x.resultado)}</div><small class="text-secondary">${esc(x.laboratorista || '')}, ${fmtFechaHora(x.fecha_resultado)}</small>`
                : `<button class="btn btn-primary btn-sm text-nowrap" data-res="${x.id_examen_visita}"><i class="bi bi-pencil-square me-1"></i>Registrar resultado</button>`}</td></tr>`).join('')
            : `<tr><td colspan="5">${vacio('eyedropper', examenes.length ? 'No hay exámenes con este filtro.' : 'Los especialistas todavía no han solicitado exámenes.')}</td></tr>`;
        $$('#tbLab [data-res]').forEach(b => b.addEventListener('click', () => formResultado(examenes.find(x => x.id_examen_visita == b.dataset.res))));
    };
    activarFiltros('lab', filtroLab, pintar);
    pintar();
}

function formResultado(x) {
    abrirForm({
        titulo: 'Registrar resultado',
        boton: 'Guardar resultado',
        cuerpo: `<div class="resumen-form mb-3">
                <div><span>Residente</span><strong>${esc(x.residente_nombre)} ${esc(x.residente_apellido)}</strong></div>
                <div><span>Examen</span><strong>${esc(x.examen)}</strong></div>
                <div><span>Solicitado por</span><strong>${esc(x.medico)} (${esc(x.especialidad)})</strong></div>
                ${x.indicaciones ? `<div><span>Indicaciones</span><strong>${esc(x.indicaciones)}</strong></div>` : ''}
            </div>
            <div class="row g-3">${textoArea({ nombre: 'resultado', etiqueta: 'Resultado del examen', req: true, filas: 5, attrs: 'maxlength="4000" minlength="3"',
                ayuda: 'Valores obtenidos, rangos de referencia y observaciones. El especialista lo verá en la ficha médica.' })}</div>
            <p class="small text-secondary mt-3 mb-0"><i class="bi bi-receipt me-1"></i>Al guardar se cargarán <strong>${fmtQ(x.precio_neto)}</strong> a la cuenta del familiar
                (precio ${fmtQ(x.precio)} menos ${pct(x.descuento_pct)} de la fundación). El resultado no se puede modificar después.</p>`,
        alEnviar: async d => {
            const r = await api('laboratorio_resultado', { id_examen_visita: x.id_examen_visita, resultado: d.resultado });
            cerrarForm(); aviso(r.mensaje); recargarVista();
        },
    });
}

/* Catálogo de exámenes */
async function vistaExamenes(c) {
    $('#accionesVista').innerHTML = `<button class="btn btn-primary" id="btnNuevoEx"><i class="bi bi-plus-lg me-1"></i> Nuevo examen</button>`;
    $('#btnNuevoEx').onclick = () => formExamen();
    const lista = await api('examenes_catalogo');
    c.innerHTML = `<section class="panel"><div class="table-responsive"><table class="table tabla">
        <thead><tr><th>Examen</th><th class="text-end">Precio</th><th class="text-end">Veces solicitado</th><th>Estado</th><th></th></tr></thead><tbody>
        ${lista.length ? lista.map(e => `<tr class="${e.activo == 1 ? '' : 'opacity-50'}"><td><strong>${esc(e.nombre)}</strong></td><td class="text-end">${fmtQ(e.precio)}</td>
            <td class="text-end">${e.veces}</td><td>${e.activo == 1 ? etiqueta('activo', 'Disponible') : etiqueta('inactivo', 'No disponible')}</td>
            <td class="text-end"><button class="btn btn-link btn-sm" data-ed="${e.id_examen}" aria-label="Editar ${esc(e.nombre)}"><i class="bi bi-pencil"></i></button></td></tr>`).join('')
            : `<tr><td colspan="5">${vacio('list-check', 'El catálogo está vacío.')}</td></tr>`}
        </tbody></table></div></section>`;
    $$('[data-ed]', c).forEach(b => b.addEventListener('click', () => formExamen(lista.find(e => e.id_examen == b.dataset.ed))));
}

function formExamen(e = null) {
    abrirForm({
        titulo: e ? 'Editar examen' : 'Nuevo examen',
        cuerpo: `<div class="row g-3">
            ${campo({ nombre: 'nombre', etiqueta: 'Nombre del examen', valor: e?.nombre, req: true, col: 'col-12', attrs: 'maxlength="120"' })}
            ${campo({ nombre: 'precio', etiqueta: 'Precio de lista (Q)', tipo: 'number', valor: e?.precio, req: true, attrs: 'min="0" max="50000" step="0.01"',
                ayuda: 'El descuento de la fundación se aplica al cobrar' })}
            <div class="col-md-6 d-flex align-items-end"><div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" id="f_activo" name="activo" ${!e || e.activo == 1 ? 'checked' : ''}>
                <label class="form-check-label" for="f_activo">Disponible para solicitar</label></div></div></div>`,
        alEnviar: async d => {
            const r = await api('examen_guardar', { ...d, id_examen: e?.id_examen });
            cerrarForm(); aviso(r.mensaje); recargarVista();
        },
    });
}

/* ================================================================
   FARMACIA: recetas por entregar
   ================================================================ */
let filtroFarm = { estado: 'Pendiente', q: '' };

function bandaStock(bajo) {
    if (!bajo.length) return '';
    return `<div class="banda-aviso alerta"><i class="bi bi-exclamation-triangle"></i><div>Existencia baja:
        ${bajo.map(m => `<strong>${esc(m.nombre_comercial)}</strong> (${m.stock} de mínimo ${m.stock_minimo})`).join(', ')}.
        ${puede(ROL.ADMIN, ROL.FARMACIA) ? '<a href="#inventario">Registrar ingreso</a>' : ''}</div></div>`;
}

async function vistaFarmacia(c) {
    $('#accionesVista').innerHTML = `<a class="btn btn-outline-primary" href="#inventario"><i class="bi bi-box-seam me-1"></i> Inventario</a>`;
    const { recetas, stock_bajo } = await api('farmacia');
    const conteo = contar(recetas, ['Pendiente', 'Entregado']);
    c.innerHTML = `${bandaStock(stock_bajo)}
    ${conteo.Pendiente ? `<div class="banda-aviso"><i class="bi bi-capsule"></i> Hay <strong>${conteo.Pendiente}</strong> medicamento(s) recetado(s) por entregar.</div>` : ''}
    <section class="panel"><div class="panel-cab">${barraFiltros('farm', filtroFarm, [['Pendiente', 'Por entregar'], ['Entregado', 'Entregados'], ['', 'Todos']], conteo, 'Buscar por residente, medicamento o médico')}</div>
        <div class="table-responsive"><table class="table tabla">
        <thead><tr><th>Residente</th><th>Medicamento</th><th>Recetó</th><th class="text-end">Existencia</th><th class="text-end">Cargo al familiar</th><th></th></tr></thead>
        <tbody id="tbFarm"></tbody></table></div></section>`;

    const pintar = () => {
        const q = filtroFarm.q.toLowerCase();
        const filas = recetas.filter(x => (!filtroFarm.estado || x.estado === filtroFarm.estado) &&
            (!q || `${x.residente_nombre} ${x.residente_apellido} ${x.nombre_comercial} ${x.medico}`.toLowerCase().includes(q)));
        $('#tbFarm').innerHTML = filas.length ? filas.map(x => {
            const falta = x.estado === 'Pendiente' && +x.stock < +x.cantidad;
            return `<tr>
            <td>${personaFila(x)}</td>
            <td><strong>${esc(x.nombre_comercial)}</strong> × ${x.cantidad}<small class="d-block text-secondary">${esc(x.presentacion)}. ${esc(x.indicaciones)}${x.duracion_dias ? `, por ${x.duracion_dias} días` : ''}</small></td>
            <td>${esc(x.medico)}<small class="d-block text-secondary">${esc(x.especialidad)}, ${fmtFecha(x.fecha_visita)}</small></td>
            <td class="text-end">${x.estado === 'Pendiente' ? (falta ? etiqueta('urgente', `Solo ${x.stock}`) : `${x.stock}`) : '<span class="text-secondary">—</span>'}</td>
            <td class="text-end text-nowrap"><strong>${fmtQ(x.total_neto)}</strong><small class="d-block text-secondary">${x.cantidad} × ${fmtQ(x.precio_unitario)}, desc. ${pct(x.descuento_pct)}</small></td>
            <td class="text-end">${x.estado === 'Entregado'
                ? `${etiqueta('normal', 'Entregado')}<small class="d-block text-secondary">${esc(x.farmaceutico || '')}, ${fmtFechaHora(x.fecha_entrega)}</small>`
                : `<button class="btn btn-primary btn-sm text-nowrap" data-ent="${x.id_receta}" ${falta ? 'disabled title="No hay suficiente existencia"' : ''}><i class="bi bi-bag-check me-1"></i>Entregar</button>`}</td></tr>`;
        }).join('')
            : `<tr><td colspan="6">${vacio('capsule', recetas.length ? 'No hay recetas con este filtro.' : 'Los especialistas todavía no han recetado medicamentos.')}</td></tr>`;
        $$('#tbFarm [data-ent]').forEach(b => b.addEventListener('click', () => entregarReceta(recetas.find(x => x.id_receta == b.dataset.ent))));
    };
    activarFiltros('farm', filtroFarm, pintar);
    pintar();
}

async function entregarReceta(x) {
    const ok = await confirmar('Entregar medicamento',
        `${x.nombre_comercial} × ${x.cantidad} para ${x.residente_nombre} ${x.residente_apellido}. ` +
        `Se descontarán ${x.cantidad} del inventario (quedarán ${x.stock - x.cantidad}) y se cargarán ${fmtQ(x.total_neto)} a la cuenta del familiar.`,
        'Confirmar entrega', 'primary');
    if (!ok) return;
    try {
        const r = await api('farmacia_entregar', { id_receta: x.id_receta });
        aviso(r.mensaje, r.mensaje.includes('Atención') ? 'aviso' : 'ok');
        recargarVista();
    } catch (ex) { aviso(ex.message, 'error'); }
}

/* ================================================================
   FARMACIA: inventario de medicamentos
   ================================================================ */
let filtroInv = { q: '' };

async function vistaInventario(c) {
    $('#accionesVista').innerHTML = `<button class="btn btn-primary" id="btnNuevoMed"><i class="bi bi-plus-lg me-1"></i> Nuevo medicamento</button>`;
    $('#btnNuevoMed').onclick = () => formMedicamento();
    const { medicamentos, stock_bajo } = await api('inventario');
    c.innerHTML = `${bandaStock(stock_bajo)}
    <section class="panel"><div class="panel-cab"><input type="search" class="form-control" id="buscarInv" placeholder="Buscar por nombre o componente" aria-label="Buscar medicamento" value="${esc(filtroInv.q)}"></div>
        <div class="table-responsive"><table class="table tabla">
        <thead><tr><th>Medicamento</th><th>Presentación</th><th class="text-end">Precio</th><th class="text-end">Existencia</th><th class="text-end">Por entregar</th><th></th></tr></thead>
        <tbody id="tbInv"></tbody></table></div></section>`;
    const pintar = () => {
        const q = filtroInv.q.toLowerCase();
        const filas = medicamentos.filter(m => !q || `${m.nombre_comercial} ${m.componente_activo}`.toLowerCase().includes(q));
        $('#tbInv').innerHTML = filas.length ? filas.map(m => {
            const bajo = m.activo == 1 && +m.stock <= +m.stock_minimo;
            return `<tr class="${m.activo == 1 ? '' : 'opacity-50'}">
            <td><strong>${esc(m.nombre_comercial)}</strong><small class="d-block text-secondary">${esc(m.componente_activo)}${m.activo == 1 ? '' : ' · no disponible'}</small></td>
            <td>${esc(m.presentacion)}</td>
            <td class="text-end">${fmtQ(m.precio)}</td>
            <td class="text-end">${bajo ? etiqueta('urgente', `${m.stock}`) : m.stock}<small class="d-block text-secondary">mínimo ${m.stock_minimo}</small></td>
            <td class="text-end">${+m.comprometido || '<span class="text-secondary">0</span>'}</td>
            <td class="text-end text-nowrap"><button class="btn btn-outline-primary btn-sm" data-ing="${m.id_medicamento}"><i class="bi bi-box-arrow-in-down me-1"></i>Ingreso</button>
                <button class="btn btn-link btn-sm" data-ed="${m.id_medicamento}" aria-label="Editar ${esc(m.nombre_comercial)}"><i class="bi bi-pencil"></i></button></td></tr>`;
        }).join('') : `<tr><td colspan="6">${vacio('box-seam', 'No hay medicamentos con esa búsqueda.')}</td></tr>`;
        $$('#tbInv [data-ing]').forEach(b => b.addEventListener('click', () => formIngreso(medicamentos.find(m => m.id_medicamento == b.dataset.ing))));
        $$('#tbInv [data-ed]').forEach(b => b.addEventListener('click', () => formMedicamento(medicamentos.find(m => m.id_medicamento == b.dataset.ed))));
    };
    $('#buscarInv').addEventListener('input', e => { filtroInv.q = e.target.value; pintar(); });
    pintar();
}

function formIngreso(m) {
    abrirForm({
        titulo: `Ingreso de inventario`,
        boton: 'Registrar ingreso',
        cuerpo: `<p class="mb-3"><strong>${esc(m.nombre_comercial)}</strong>, ${esc(m.presentacion)}. Existencia actual: <strong>${m.stock}</strong>.</p>
            <div class="row g-3">
            ${campo({ nombre: 'cantidad', etiqueta: 'Cantidad que ingresa', tipo: 'number', req: true, attrs: 'min="1" max="100000" step="1"' })}
            ${campo({ nombre: 'nota', etiqueta: 'Origen o nota', attrs: 'maxlength="255" placeholder="Compra, donación, factura…"' })}</div>`,
        alEnviar: async d => {
            const r = await api('inventario_ingreso', { ...d, id_medicamento: m.id_medicamento });
            cerrarForm(); aviso(r.mensaje); recargarVista();
        },
    });
}

function formMedicamento(m = null) {
    abrirForm({
        titulo: m ? 'Editar medicamento' : 'Nuevo medicamento',
        cuerpo: `<div class="row g-3">
            ${campo({ nombre: 'nombre_comercial', etiqueta: 'Nombre comercial', valor: m?.nombre_comercial, req: true, attrs: 'maxlength="100" placeholder="Losartán 50 mg"' })}
            ${campo({ nombre: 'componente_activo', etiqueta: 'Componente activo', valor: m?.componente_activo, req: true, attrs: 'maxlength="100"' })}
            ${campo({ nombre: 'presentacion', etiqueta: 'Presentación', valor: m?.presentacion, req: true, attrs: 'maxlength="60" placeholder="Caja 30 tabletas"' })}
            ${campo({ nombre: 'precio', etiqueta: 'Precio de lista (Q)', tipo: 'number', valor: m?.precio, req: true, attrs: 'min="0" max="50000" step="0.01"' })}
            ${m ? '' : campo({ nombre: 'stock', etiqueta: 'Existencia inicial', tipo: 'number', valor: 0, req: true, attrs: 'min="0" max="100000" step="1"' })}
            ${campo({ nombre: 'stock_minimo', etiqueta: 'Existencia mínima', tipo: 'number', valor: m?.stock_minimo ?? 5, req: true, attrs: 'min="0" max="10000" step="1"', ayuda: 'Por debajo de este número se muestra una alerta' })}
            <div class="col-12"><div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="f_activo" name="activo" ${!m || m.activo == 1 ? 'checked' : ''}>
                <label class="form-check-label" for="f_activo">Disponible para recetar</label></div></div>
            ${m ? '<p class="small text-secondary mb-0">La existencia se cambia con «Ingreso» o con cada entrega, para que quede registro en la bitácora.</p>' : ''}</div>`,
        alEnviar: async d => {
            const r = await api('medicamento_guardar', { ...d, id_medicamento: m?.id_medicamento });
            cerrarForm(); aviso(r.mensaje); cacheMedicamentos = null; recargarVista();
        },
    });
}
