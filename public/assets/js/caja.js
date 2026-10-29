/* Asilo Cabeza de Algodón — Módulo de entradas, salidas y manejo de caja.
   Depende de app.js (api, esc, fmtQ, fmtFecha, campo, selector, abrirForm…) y servicios.js (textoArea, barraFiltros). */

const MESES_LARGOS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const nombreMes = p => `${MESES_LARGOS[+p.slice(5, 7) - 1]} ${p.slice(0, 4)}`;
const mesActual = () => hoy().slice(0, 7);
const finDeMes = p => { const [a, m] = p.split('-').map(Number); return `${p}-${String(new Date(a, m, 0).getDate()).padStart(2, '0')}`; };
const opcionesDe = lista => lista.map(x => ({ v: x, t: x }));
const METODOS = ['Efectivo', 'Depósito', 'Transferencia', 'Tarjeta', 'Cheque'];
const COLOR_PAGO = { Pendiente: 'urgente', Parcial: 'atencion', Cancelada: 'normal', 'Sin costo': 'neutro' };
const saldoEtiqueta = s => +s > 0 ? etiqueta('urgente', fmtQ(s)) : etiqueta('normal', 'Al día');

/** Barra horizontal proporcional para tablas de totales */
const barra = (valor, max) => `<div class="barra-mini" aria-hidden="true"><span style="width:${max > 0 ? Math.max(2, Math.round(valor / max * 100)) : 0}%"></span></div>`;

/** Campos comunes de un pago (forma, referencia, fecha) */
function camposPago(metodos = METODOS) {
    return `${selector({ nombre: 'metodo', etiqueta: 'Forma de pago', opciones: opcionesDe(metodos), valor: 'Efectivo', req: true, vacio: null })}
        ${campo({ nombre: 'fecha', etiqueta: 'Fecha', tipo: 'date', valor: hoy(), req: true, attrs: `max="${hoy()}"` })}
        ${campo({ nombre: 'referencia', etiqueta: 'No. de boleta o referencia', col: 'col-12', attrs: 'maxlength="60"', ayuda: 'Obligatorio si no es en efectivo' })}`;
}

/* ================================================================
   RESUMEN DE CAJA
   ================================================================ */
let mesCaja = null;

/* ¿Y si se registró un pago, donación o gasto por error? Se anula con motivo: deja de contar, pero no se borra */
const puedeAnular = () => puede(ROL.ADMIN, ROL.CONTADOR);
const botonAnular = (tipo, id) => puedeAnular() ? `<button class="btn btn-link btn-sm text-danger p-0" data-anular="${tipo}:${id}" title="Anular este registro">Anular</button>` : '';
const marcaAnulado = x => +x.anulado ? `<small class="d-block">${etiqueta('urgente', 'Anulado')} <span class="text-secondary">${esc(x.motivo_anulacion || '')}${x.anulado_por_nombre ? ` (${esc(x.anulado_por_nombre)})` : ''}</span></small>` : '';
function activarAnular(raiz, descripcion) {
    $$('[data-anular]', raiz).forEach(b => b.addEventListener('click', () => {
        const [tipo, id] = b.dataset.anular.split(':');
        abrirForm({
            titulo: 'Anular registro',
            boton: 'Anular',
            cuerpo: `<p>${descripcion(tipo, id)}</p>
                <div class="row g-3">${textoArea({ nombre: 'motivo', etiqueta: 'Motivo de la anulación', req: true, filas: 2, attrs: 'maxlength="255" minlength="10" placeholder="Ej.: se registró dos veces el mismo depósito"' })}</div>
                <p class="small text-secondary mt-3 mb-0"><i class="bi bi-info-circle me-1"></i>El registro no se borra: queda tachado, con el motivo y su nombre, y deja de contar en saldos, caja y reportes. Esta acción no se puede deshacer.</p>`,
            alEnviar: async d => { const res = await api('caja_anular', { tipo, id, motivo: d.motivo }); cerrarForm(); aviso(res.mensaje); recargarVista(); },
        });
        $('#modalFormBoton').className = 'btn btn-danger';
        $('#modalForm').addEventListener('hidden.bs.modal', () => { $('#modalFormBoton').className = 'btn btn-primary'; }, { once: true });
    }));
}

async function vistaCaja(c) {
    mesCaja = mesCaja || mesActual();
    $('#accionesVista').innerHTML = `<label class="visually-hidden" for="mesCaja">Mes</label>
        <input type="month" class="form-control" id="mesCaja" value="${mesCaja}" max="${mesActual()}">`;
    $('#mesCaja').addEventListener('change', e => { if (e.target.value) { mesCaja = e.target.value; recargarVista(); } });
    const d = await api('caja_resumen', null, `&desde=${mesCaja}-01&hasta=${finDeMes(mesCaja)}`);
    const maxG = Math.max(0, ...d.gastos_por_categoria.map(g => +g.total));
    const maxD = Math.max(0, ...d.donaciones_por_tipo.map(x => +x.total));
    c.innerHTML = `
    <p class="text-secondary mb-3">Movimientos de dinero de <strong>${nombreMes(mesCaja)}</strong>.</p>
    <div class="cifras">
        <div><div class="cifra texto-vida">${fmtQ(d.entradas.total)}</div><p>entradas (cobros y donaciones)</p></div>
        <div><div class="cifra texto-achiote">${fmtQ(d.salidas.total)}</div><p>salidas (gastos y pagos a la fundación)</p></div>
        <div><div class="cifra">${fmtQ(d.balance)}</div><p>balance del mes</p></div>
        <div><a class="cifra-enlace" href="#cuentas"><div class="cifra">${fmtQ(d.por_cobrar)}</div><p>por cobrar a familiares (total)</p></a></div>
        <div><a class="cifra-enlace" href="#fundacion-pagos"><div class="cifra">${fmtQ(d.deuda_fundacion)}</div><p>pendiente de pagar a la fundación</p></a></div>
    </div>
    <div class="row g-4">
        <div class="col-lg-6"><section class="panel h-100"><div class="panel-cab"><h2>Entradas</h2></div>
            <table class="table tabla mb-0"><tbody>
                <tr><td>Cobros a familiares</td><td class="text-end">${fmtQ(d.entradas.cobros_familiares)}</td></tr>
                <tr><td>Donaciones en dinero</td><td class="text-end">${fmtQ(d.entradas.donaciones)}</td></tr>
                <tr class="fila-total"><td>Total</td><td class="text-end">${fmtQ(d.entradas.total)}</td></tr></tbody></table>
            <div class="panel-cuerpo"><h3 class="subtitulo">Donaciones por tipo de donante</h3>
            ${d.donaciones_por_tipo.length ? `<table class="table tabla-totales"><tbody>${d.donaciones_por_tipo.map(x => `<tr>
                <td>${esc(x.tipo_donante)}${x.tipo_donacion === 'En especie' ? ' <span class="text-secondary small">(en especie)</span>' : ''}</td>
                <td class="w-50">${barra(+x.total, maxD)}</td><td class="text-end text-nowrap">${fmtQ(x.total)}</td></tr>`).join('')}</tbody></table>
                ${d.donaciones_especie > 0 ? `<p class="small text-secondary mb-0">Las donaciones en especie (${fmtQ(d.donaciones_especie)} estimados) no suman al dinero en caja.</p>` : ''}`
                : vacio('gift', 'Sin donaciones en este mes.', '<a class="btn btn-outline-primary btn-sm" href="#donaciones">Registrar donación</a>')}</div>
        </section></div>
        <div class="col-lg-6"><section class="panel h-100"><div class="panel-cab"><h2>Salidas</h2></div>
            <table class="table tabla mb-0"><tbody>
                <tr><td>Gastos del asilo</td><td class="text-end">${fmtQ(d.salidas.gastos)}</td></tr>
                <tr><td>Pagos a la fundación</td><td class="text-end">${fmtQ(d.salidas.pagos_fundacion)}</td></tr>
                <tr class="fila-total"><td>Total</td><td class="text-end">${fmtQ(d.salidas.total)}</td></tr></tbody></table>
            <div class="panel-cuerpo"><h3 class="subtitulo">Gastos por categoría</h3>
            ${d.gastos_por_categoria.length ? `<table class="table tabla-totales"><tbody>${d.gastos_por_categoria.map(g => `<tr>
                <td>${esc(g.categoria)}</td><td class="w-50">${barra(+g.total, maxG)}</td><td class="text-end text-nowrap">${fmtQ(g.total)}</td></tr>`).join('')}</tbody></table>`
                : vacio('receipt', 'Sin gastos en este mes.', '<a class="btn btn-outline-primary btn-sm" href="#gastos">Registrar gasto</a>')}</div>
        </section></div>
        <div class="col-12"><section class="panel"><div class="panel-cab"><h2>Cargado a las cuentas de los familiares</h2><span class="ms-auto text-secondary small">${fmtQ(d.cargado_familiares)} en el mes</span></div>
            ${d.cargos_por_tipo.length ? `<div class="table-responsive"><table class="table tabla mb-0"><thead><tr><th>Concepto</th><th class="text-end">Cargos</th><th class="text-end">Descuento de la fundación</th><th class="text-end">Total cargado</th></tr></thead><tbody>
            ${d.cargos_por_tipo.map(x => `<tr><td>${esc(x.tipo)}</td><td class="text-end">${x.cantidad}</td><td class="text-end">${+x.descuento ? fmtQ(x.descuento) : '<span class="text-secondary">—</span>'}</td><td class="text-end">${fmtQ(x.total)}</td></tr>`).join('')}
            </tbody></table></div>` : vacio('journal-text', 'No se ha cargado nada a las cuentas en este mes.', puede(ROL.ADMIN, ROL.CONTADOR) ? '<a class="btn btn-outline-primary btn-sm" href="#cuentas">Generar cuotas del mes</a>' : '')}
        </section></div>
    </div>`;
}

/* ================================================================
   CUENTAS DE LOS FAMILIARES
   ================================================================ */
let filtroCuentas = { estado: 'deben', q: '' };

async function vistaCuentas(c) {
    $('#accionesVista').innerHTML = `<button class="btn btn-primary" id="btnCuotas"><i class="bi bi-calendar-plus me-1"></i> Generar cuotas del mes</button>`;
    $('#btnCuotas').onclick = () => formCuotas();
    const { cuentas, por_cobrar } = await api('cuentas');
    const conteo = { deben: cuentas.filter(x => +x.saldo > 0).length, aldia: cuentas.filter(x => +x.saldo <= 0).length, '': cuentas.length };
    c.innerHTML = `<div class="cifras">
            <div><div class="cifra">${fmtQ(por_cobrar)}</div><p>por cobrar en total</p></div>
            <div><div class="cifra">${conteo.deben}</div><p>familias con saldo pendiente</p></div>
            <div><div class="cifra">${conteo.aldia}</div><p>cuentas al día</p></div></div>
        <section class="panel"><div class="panel-cab">${barraFiltros('cta', filtroCuentas, [['deben', 'Con saldo'], ['aldia', 'Al día'], ['', 'Todas']], conteo, 'Buscar por residente o familiar')}</div>
        <div class="table-responsive"><table class="table tabla">
        <thead><tr><th>Residente</th><th>Familiar responsable</th><th class="text-end">Cargado</th><th class="text-end">Pagado</th><th class="text-end">Saldo</th><th></th></tr></thead>
        <tbody id="tbCta"></tbody></table></div></section>`;
    const pintar = () => {
        const q = filtroCuentas.q.toLowerCase();
        const filas = cuentas.filter(x => (filtroCuentas.estado === '' || (filtroCuentas.estado === 'deben' ? +x.saldo > 0 : +x.saldo <= 0)) &&
            (!q || `${x.nombre} ${x.apellido} ${x.familiar_nombre} ${x.familiar_apellido}`.toLowerCase().includes(q)));
        $('#tbCta').innerHTML = filas.length ? filas.map(x => `<tr class="clic" tabindex="0" data-id="${x.id_residente}">
            <td>${personaFila({ residente_nombre: x.nombre, residente_apellido: x.apellido, numero_habitacion: x.numero_habitacion })}</td>
            <td>${esc(x.familiar_nombre)} ${esc(x.familiar_apellido)}<small class="d-block text-secondary">${esc(x.familiar_telefono || '')}${x.ultimo_pago ? ` · último pago ${fmtFecha(x.ultimo_pago)}` : ' · sin pagos'}</small></td>
            <td class="text-end">${fmtQ(x.cargado)}</td><td class="text-end">${fmtQ(x.pagado)}</td>
            <td class="text-end">${saldoEtiqueta(x.saldo)}</td>
            <td class="text-end text-nowrap">${+x.saldo > 0 ? `<button class="btn btn-primary btn-sm" data-pagar="${x.id_residente}"><i class="bi bi-cash-coin me-1"></i>Registrar pago</button>` : ''}</td></tr>`).join('')
            : `<tr><td colspan="6">${vacio('wallet2', 'No hay cuentas con este filtro.')}</td></tr>`;
        $$('#tbCta tr.clic').forEach(tr => {
            tr.addEventListener('click', e => { if (!e.target.closest('[data-pagar]')) location.hash = `#cuenta?r=${tr.dataset.id}`; });
            tr.addEventListener('keydown', e => { if (e.key === 'Enter') location.hash = `#cuenta?r=${tr.dataset.id}`; });
        });
        $$('#tbCta [data-pagar]').forEach(b => b.addEventListener('click', () => {
            const x = cuentas.find(k => k.id_residente == b.dataset.pagar);
            formPago({ id_residente: x.id_residente, nombre: `${x.nombre} ${x.apellido}`, familiar: `${x.familiar_nombre} ${x.familiar_apellido}`, saldo: x.saldo });
        }));
    };
    activarFiltros('cta', filtroCuentas, pintar);
    pintar();
}

function formPago(x) {
    abrirForm({
        titulo: 'Registrar pago del familiar',
        boton: 'Registrar pago',
        cuerpo: `<div class="resumen-form mb-3">
                <div><span>Residente</span><strong>${esc(x.nombre)}</strong></div>
                <div><span>Paga</span><strong>${esc(x.familiar)}</strong></div>
                <div><span>Saldo pendiente</span><strong>${fmtQ(x.saldo)}</strong></div></div>
            <div class="row g-3">
            ${campo({ nombre: 'monto', etiqueta: 'Monto (Q)', tipo: 'number', valor: (+x.saldo).toFixed(2), req: true, attrs: `min="0.01" max="${(+x.saldo).toFixed(2)}" step="0.01"`,
                ayuda: 'Puede ser un abono parcial' })}
            ${camposPago()}
            ${campo({ nombre: 'observaciones', etiqueta: 'Observaciones', col: 'col-12', attrs: 'maxlength="255"' })}</div>`,
        alEnviar: async d => {
            const r = await api('pago_registrar', { ...d, id_residente: x.id_residente });
            cerrarForm(); aviso(r.mensaje); recargarVista();
        },
    });
}

async function formCuotas(periodo = mesActual()) {
    const d = await api('cuotas_preview', null, `&periodo=${periodo}`);
    const pend = d.residentes.filter(r => !+r.ya_tiene);
    const total = pend.reduce((s, r) => s + r.monto, 0);
    abrirForm({
        titulo: 'Generar cuotas mensuales',
        boton: pend.length ? `Generar ${pend.length} cuota(s)` : 'Generar',
        cuerpo: `<div class="row g-3 mb-3">${campo({ nombre: 'periodo', etiqueta: 'Mes', tipo: 'month', valor: d.periodo, req: true, col: 'col-sm-6' })}</div>
            <p class="small text-secondary">La cuota de cada residente es el costo de su plan más el recargo de su habitación. No lleva el descuento de la fundación,
                que solo aplica a consultas, exámenes y medicamentos. Si un residente ya tiene la cuota de ese mes, no se duplica.
                Quien ingresó a mitad de mes paga solo los días desde su ingreso (cuota proporcional); quien ingresó el día 1 paga el mes completo.</p>
            <div class="table-responsive"><table class="table tabla"><thead><tr><th>Residente</th><th>Plan</th><th class="text-end">Habitación</th><th class="text-end">Cuota</th></tr></thead><tbody>
            ${d.residentes.map(r => `<tr class="${+r.ya_tiene ? 'opacity-50' : ''}"><td>${esc(r.nombre)} ${esc(r.apellido)}${+r.ya_tiene ? ' <span class="small">(ya generada)</span>' : ''}</td>
                <td>${esc(r.nombre_plan || 'Sin plan')}<small class="d-block text-secondary">${fmtQ(r.costo_plan)}</small></td>
                <td class="text-end">${+r.costo_habitacion ? `${esc(r.tipo_habitacion)} +${fmtQ(r.costo_habitacion)}` : '—'}</td>
                <td class="text-end">${fmtQ(r.monto)}${r.proporcional ? `<small class="d-block text-secondary">proporcional: ${r.dias} de ${r.dias_mes} días<br>(ingresó el ${fmtFecha(r.fecha_ingreso)}; mes completo ${fmtQ(r.cuota_completa)})</small>` : ''}</td></tr>`).join('')}
            </tbody><tfoot><tr class="fila-total"><td colspan="3">Total a generar en ${nombreMes(d.periodo)}</td><td class="text-end">${fmtQ(total)}</td></tr></tfoot></table></div>`,
        alEnviar: async f => {
            if (f.periodo !== d.periodo) { cerrarForm(); setTimeout(() => formCuotas(f.periodo), 350); return; }
            const r = await api('cuotas_generar', { periodo: f.periodo });
            cerrarForm(); aviso(r.mensaje, r.generadas ? 'ok' : 'aviso'); recargarVista();
        },
    });
    $('#f_periodo')?.addEventListener('change', e => { if (e.target.value && e.target.value !== d.periodo) { cerrarForm(); setTimeout(() => formCuotas(e.target.value), 350); } });
}

/* Estado de cuenta de un residente */
async function vistaCuenta(c, params) {
    const id = +params.get('r');
    if (!id) { location.hash = '#cuentas'; return; }
    const d = await api('cuenta_estado', null, `&id_residente=${id}`);
    const r = d.residente;
    $('#tituloVista').textContent = `Estado de cuenta: ${r.nombre} ${r.apellido}`;
    $('#accionesVista').innerHTML = `<a class="btn btn-link" href="#cuentas"><i class="bi bi-arrow-left me-1"></i>Volver</a>
        <button class="btn btn-outline-primary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir</button>
        ${+d.saldo > 0 ? '<button class="btn btn-primary" id="btnPagarCta"><i class="bi bi-cash-coin me-1"></i>Registrar pago</button>' : ''}`;
    $('#btnPagarCta')?.addEventListener('click', () => formPago({ id_residente: id, nombre: `${r.nombre} ${r.apellido}`, familiar: `${r.familiar_nombre} ${r.familiar_apellido}`, saldo: d.saldo }));
    c.innerHTML = `<section class="panel mb-4"><div class="panel-cuerpo d-flex flex-wrap gap-4 justify-content-between">
            <div><span class="text-secondary small d-block">Residente</span><strong>${esc(r.nombre)} ${esc(r.apellido)}</strong><small class="d-block">Hab. ${esc(r.numero_habitacion || '—')}, ${esc(r.nombre_plan || 'sin plan')}</small></div>
            <div><span class="text-secondary small d-block">Familiar responsable</span><strong>${esc(r.familiar_nombre)} ${esc(r.familiar_apellido)}</strong><small class="d-block">${esc(r.familiar_parentesco || '')} · ${esc(r.familiar_telefono || '')}</small></div>
            <div><span class="text-secondary small d-block">Fecha</span><strong>${fmtFecha(hoy())}</strong></div></div></section>
        <div class="cifras">
            <div><div class="cifra">${fmtQ(d.cargado)}</div><p>total cargado</p></div>
            <div><div class="cifra texto-vida">${fmtQ(d.descuentos)}</div><p>ahorro por el descuento de la fundación</p></div>
            <div><div class="cifra">${fmtQ(d.pagado)}</div><p>total pagado</p></div>
            <div><div class="cifra ${+d.saldo > 0 ? 'texto-achiote' : 'texto-vida'}">${fmtQ(d.saldo)}</div><p>${+d.saldo > 0 ? 'saldo pendiente' : 'cuenta al día'}</p></div></div>
        <section class="panel"><div class="table-responsive"><table class="table tabla mb-0">
            <thead><tr><th>Fecha</th><th>Concepto</th><th class="text-end">Cargo</th><th class="text-end">Pago</th><th class="text-end">Saldo</th><th class="no-imprimir"></th></tr></thead><tbody>
            ${d.movimientos.length ? d.movimientos.map(m => `<tr class="${+m.anulado ? 'fila-anulada' : ''}">
                <td class="text-nowrap">${fmtFecha(m.fecha)}</td>
                <td><span class="tipo-mov">${esc(m.tipo)}</span> ${esc(m.concepto)}${+m.descuento ? `<small class="d-block text-secondary">Precio ${fmtQ(m.monto_bruto)} menos ${fmtQ(m.descuento)} de descuento de la fundación</small>` : ''}${m.referencia ? `<small class="d-block text-secondary">Ref. ${esc(m.referencia)}</small>` : ''}${marcaAnulado(m)}</td>
                <td class="text-end">${m.clase === 'cargo' ? fmtQ(m.monto) : ''}</td>
                <td class="text-end texto-vida monto">${m.clase === 'pago' ? fmtQ(m.monto) : ''}</td>
                <td class="text-end"><strong>${fmtQ(m.saldo)}</strong></td>
                <td class="text-end no-imprimir">${m.clase === 'pago' && !+m.anulado ? botonAnular('pago', m.id) : ''}</td></tr>`).join('')
                : `<tr><td colspan="6">${vacio('journal', 'La cuenta no tiene movimientos.')}</td></tr>`}
            </tbody></table></div></section>`;
    activarAnular(c, (t, idPago) => { const m = d.movimientos.find(x => x.clase === 'pago' && x.id == idPago);
        return `Pago de <strong>${fmtQ(m.monto)}</strong> del ${fmtFecha(m.fecha)} (${esc(m.metodo)}${m.referencia ? `, ref. ${esc(m.referencia)}` : ''}). El saldo de la cuenta volverá a subir en ese monto.`; });
}

/* ================================================================
   CUENTA CON LA FUNDACIÓN (lo que el asilo debe por cada cita)
   ================================================================ */
let filtroFund = { estado: 'debe', q: '' };

async function vistaFundacionPagos(c) {
    const { citas, totales, pagos } = await api('fundacion_cuenta');
    const debe = x => x.estado_pago === 'Pendiente' || x.estado_pago === 'Parcial';
    const conteo = { debe: citas.filter(debe).length, Cancelada: citas.filter(x => x.estado_pago === 'Cancelada').length, '': citas.length };
    const puedePagar = puede(ROL.ADMIN, ROL.CONTADOR);
    c.innerHTML = `<p class="text-secondary mb-3">Por cada cita, el asilo debe a la fundación el precio especial de la consulta, los exámenes realizados y los medicamentos entregados.</p>
        <div class="cifras">
            <div><div class="cifra">${fmtQ(totales.adeudado)}</div><p>total de servicios de la fundación</p></div>
            <div><div class="cifra">${fmtQ(totales.pagado)}</div><p>pagado</p></div>
            <div><div class="cifra ${totales.saldo > 0 ? 'texto-achiote' : 'texto-vida'}">${fmtQ(totales.saldo)}</div><p>pendiente de pagar</p></div>
            <div><div class="cifra">${conteo.debe}</div><p>citas no canceladas completamente</p></div></div>
        <section class="panel mb-4"><div class="panel-cab">${barraFiltros('fun', filtroFund, [['debe', 'No canceladas'], ['Cancelada', 'Canceladas'], ['', 'Todas']], conteo, 'Buscar por residente, especialidad o médico')}</div>
        <div class="table-responsive"><table class="table tabla">
        <thead><tr><th>Cita</th><th>Residente</th><th class="text-end">Consulta</th><th class="text-end">Exámenes</th><th class="text-end">Medicamentos</th><th class="text-end">Total</th><th class="text-end">Pagado</th><th>Estado</th><th></th></tr></thead>
        <tbody id="tbFun"></tbody></table></div></section>
        <section class="panel"><div class="panel-cab"><h2>Pagos realizados a la fundación</h2></div>
        ${pagos.length ? `<div class="table-responsive"><table class="table tabla mb-0"><thead><tr><th>Fecha</th><th>Cita</th><th>Forma</th><th>Referencia</th><th class="text-end">Monto</th><th></th></tr></thead><tbody>
            ${pagos.map(p => { const ci = citas.find(x => x.id_visita == p.id_visita); return `<tr class="${+p.anulado ? 'fila-anulada' : ''}"><td>${fmtFecha(p.fecha)}</td>
                <td>${ci ? `${esc(ci.residente_nombre)} ${esc(ci.residente_apellido)}, ${esc(ci.especialidad)}` : `Visita ${p.id_visita}`}${marcaAnulado(p)}</td><td>${esc(p.metodo)}</td><td>${esc(p.referencia || '—')}</td><td class="text-end monto">${fmtQ(p.monto)}</td>
                <td class="text-end">${+p.anulado ? '' : botonAnular('pago_fundacion', p.id_pago_fundacion)}</td></tr>`; }).join('')}
            </tbody></table></div>` : vacio('bank', 'Todavía no se ha pagado nada a la fundación.')}</section>`;
    const pintar = () => {
        const q = filtroFund.q.toLowerCase();
        const filas = citas.filter(x => (filtroFund.estado === '' || (filtroFund.estado === 'debe' ? debe(x) : x.estado_pago === filtroFund.estado)) &&
            (!q || `${x.residente_nombre} ${x.residente_apellido} ${x.especialidad} ${x.medico}`.toLowerCase().includes(q)));
        $('#tbFun').innerHTML = filas.length ? filas.map(x => `<tr>
            <td class="text-nowrap">${fmtFecha(x.fecha_visita)}<small class="d-block text-secondary">${esc(x.especialidad)}, ${esc(x.medico)}</small></td>
            <td>${esc(x.residente_nombre)} ${esc(x.residente_apellido)}</td>
            <td class="text-end">${fmtQ(x.consulta)}</td><td class="text-end">${fmtQ(x.examenes)}</td><td class="text-end">${fmtQ(x.medicamentos)}</td>
            <td class="text-end"><strong>${fmtQ(x.adeudado)}</strong></td><td class="text-end">${fmtQ(x.pagado)}</td>
            <td>${etiqueta(COLOR_PAGO[x.estado_pago], x.estado_pago)}${+x.saldo > 0 && +x.pagado > 0 ? `<small class="d-block text-secondary">falta ${fmtQ(x.saldo)}</small>` : ''}</td>
            <td class="text-end">${puedePagar && +x.saldo > 0 ? `<button class="btn btn-primary btn-sm text-nowrap" data-pf="${x.id_visita}">Pagar</button>` : ''}</td></tr>`).join('')
            : `<tr><td colspan="9">${vacio('bank', citas.length ? 'No hay citas con este filtro.' : 'Aún no hay citas atendidas por la fundación.')}</td></tr>`;
        $$('#tbFun [data-pf]').forEach(b => b.addEventListener('click', () => formPagoFundacion(citas.find(x => x.id_visita == b.dataset.pf))));
    };
    activarFiltros('fun', filtroFund, pintar);
    pintar();
    activarAnular(c, (t, idP) => { const p = pagos.find(x => x.id_pago_fundacion == idP);
        return `Pago a la fundación de <strong>${fmtQ(p.monto)}</strong> del ${fmtFecha(p.fecha)}. La cita volverá a quedar pendiente por ese monto.`; });
}

function formPagoFundacion(x) {
    abrirForm({
        titulo: 'Pago a la fundación',
        boton: 'Registrar pago',
        cuerpo: `<div class="resumen-form mb-3">
                <div><span>Cita</span><strong>${esc(x.especialidad)}, ${fmtFecha(x.fecha_visita)}</strong></div>
                <div><span>Residente</span><strong>${esc(x.residente_nombre)} ${esc(x.residente_apellido)}</strong></div>
                <div><span>Total de la cita</span><strong>${fmtQ(x.adeudado)}</strong></div>
                <div><span>Pendiente</span><strong>${fmtQ(x.saldo)}</strong></div></div>
            <div class="row g-3">
            ${campo({ nombre: 'monto', etiqueta: 'Monto (Q)', tipo: 'number', valor: (+x.saldo).toFixed(2), req: true, attrs: `min="0.01" max="${(+x.saldo).toFixed(2)}" step="0.01"` })}
            ${camposPago(['Efectivo', 'Depósito', 'Transferencia', 'Cheque'])}</div>`,
        alEnviar: async d => {
            const r = await api('fundacion_pagar', { ...d, id_visita: x.id_visita });
            cerrarForm(); aviso(r.mensaje); recargarVista();
        },
    });
}

/* ================================================================
   DONACIONES Y GASTOS
   ================================================================ */
let rangoDon = { desde: '', hasta: '' };
let rangoGas = { desde: '', hasta: '' };

function filtroRango(id, rango) {
    return `<div class="filtros w-100 align-items-end">
        <div><label class="form-label small mb-1" for="${id}_desde">Desde</label><input type="date" class="form-control" id="${id}_desde" value="${rango.desde}"></div>
        <div><label class="form-label small mb-1" for="${id}_hasta">Hasta</label><input type="date" class="form-control" id="${id}_hasta" value="${rango.hasta}"></div>
        ${rango.desde || rango.hasta ? `<button class="btn btn-link btn-sm" id="${id}_limpiar">Ver todo</button>` : ''}</div>`;
}
function activarRango(id, rango) {
    const aplicar = () => { rango.desde = $(`#${id}_desde`).value; rango.hasta = $(`#${id}_hasta`).value; recargarVista(); };
    $(`#${id}_desde`).addEventListener('change', aplicar);
    $(`#${id}_hasta`).addEventListener('change', aplicar);
    $(`#${id}_limpiar`)?.addEventListener('click', () => { rango.desde = rango.hasta = ''; recargarVista(); });
}
const qsRango = r => `${r.desde ? `&desde=${r.desde}` : ''}${r.hasta ? `&hasta=${r.hasta}` : ''}`;

async function vistaDonaciones(c) {
    $('#accionesVista').innerHTML = `<button class="btn btn-primary" id="btnDon"><i class="bi bi-plus-lg me-1"></i> Registrar donación</button>`;
    const d = await api('donaciones', null, qsRango(rangoDon));
    $('#btnDon').onclick = () => formDonacion(d);
    const vigentes = d.donaciones.filter(x => !+x.anulado);
    const total = vigentes.filter(x => x.tipo_donacion === 'Monetaria').reduce((s, x) => s + +x.monto, 0);
    const especie = vigentes.filter(x => x.tipo_donacion === 'En especie').reduce((s, x) => s + +x.monto, 0);
    c.innerHTML = `<div class="cifras">
            <div><div class="cifra">${fmtQ(total)}</div><p>donado en dinero</p></div>
            ${d.tipos_donante.map(t => `<div><div class="cifra">${fmtQ(d.por_tipo[t] || 0)}</div><p>${esc(t.toLowerCase())}</p></div>`).join('')}</div>
        ${especie > 0 ? `<p class="small text-secondary">Además hay ${fmtQ(especie)} en donaciones en especie (valor estimado).</p>` : ''}
        <section class="panel"><div class="panel-cab">${filtroRango('don', rangoDon)}</div>
        <div class="table-responsive"><table class="table tabla mb-0"><thead><tr><th>Fecha</th><th>Donante</th><th>Tipo de donante</th><th>Detalle</th><th class="text-end">Monto</th><th></th></tr></thead><tbody>
        ${d.donaciones.length ? d.donaciones.map(x => `<tr class="${+x.anulado ? 'fila-anulada' : ''}"><td class="text-nowrap">${fmtFecha(x.fecha)}</td><td><strong>${esc(x.donante)}</strong>${x.comprobante ? `<small class="d-block text-secondary">Recibo ${esc(x.comprobante)}</small>` : ''}</td>
            <td>${esc(x.tipo_donante)}</td><td>${x.tipo_donacion === 'En especie' ? etiqueta('neutro', 'En especie') + ' ' : ''}${esc(x.descripcion || '')}${marcaAnulado(x)}</td>
            <td class="text-end monto">${fmtQ(x.monto)}</td><td class="text-end">${+x.anulado ? '' : botonAnular('donacion', x.id_donacion)}</td></tr>`).join('') : `<tr><td colspan="6">${vacio('gift', 'No hay donaciones en este rango.')}</td></tr>`}
        </tbody></table></div></section>`;
    activarRango('don', rangoDon);
    activarAnular(c, (t, idD) => { const x = d.donaciones.find(y => y.id_donacion == idD);
        return `Donación de <strong>${esc(x.donante)}</strong> por ${fmtQ(x.monto)} del ${fmtFecha(x.fecha)}.`; });
}

function formDonacion(d) {
    abrirForm({
        titulo: 'Registrar donación',
        cuerpo: `<div class="row g-3">
            ${selector({ nombre: 'tipo_donante', etiqueta: 'Tipo de donante', opciones: opcionesDe(d.tipos_donante), req: true })}
            ${selector({ nombre: 'tipo_donacion', etiqueta: 'Tipo de donación', opciones: opcionesDe(d.tipos_donacion), valor: 'Monetaria', req: true, vacio: null })}
            ${campo({ nombre: 'donante', etiqueta: 'Nombre del donante', req: true, col: 'col-12', attrs: 'maxlength="150"' })}
            ${campo({ nombre: 'monto', etiqueta: 'Monto (Q)', tipo: 'number', req: true, attrs: 'min="0.01" step="0.01"', ayuda: 'Si es en especie, su valor estimado' })}
            ${campo({ nombre: 'fecha', etiqueta: 'Fecha', tipo: 'date', valor: hoy(), req: true, attrs: `max="${hoy()}"` })}
            ${campo({ nombre: 'descripcion', etiqueta: 'Descripción', col: 'col-md-8', attrs: 'maxlength="255"', ayuda: 'Obligatoria si es en especie: qué se donó' })}
            ${campo({ nombre: 'comprobante', etiqueta: 'No. de recibo', col: 'col-md-4', attrs: 'maxlength="60"' })}</div>`,
        alEnviar: async f => {
            const r = await api('donacion_registrar', f);
            cerrarForm(); aviso(r.mensaje); recargarVista();
        },
    });
}

async function vistaGastos(c) {
    $('#accionesVista').innerHTML = `<button class="btn btn-primary" id="btnGas"><i class="bi bi-plus-lg me-1"></i> Registrar gasto</button>`;
    const d = await api('gastos', null, qsRango(rangoGas));
    $('#btnGas').onclick = () => formGasto(d);
    const total = d.gastos.filter(x => !+x.anulado).reduce((s, x) => s + +x.monto, 0);
    const cats = Object.entries(d.por_categoria);
    const max = Math.max(0, ...cats.map(([, v]) => v));
    c.innerHTML = `<div class="row g-4"><div class="col-xl-8">
        <section class="panel"><div class="panel-cab">${filtroRango('gas', rangoGas)}</div>
        <div class="table-responsive"><table class="table tabla mb-0"><thead><tr><th>Fecha</th><th>Categoría</th><th>Descripción</th><th class="text-end">Monto</th><th></th></tr></thead><tbody>
        ${d.gastos.length ? d.gastos.map(x => `<tr class="${+x.anulado ? 'fila-anulada' : ''}"><td class="text-nowrap">${fmtFecha(x.fecha)}</td><td>${esc(x.categoria)}</td>
            <td>${esc(x.descripcion)}${x.comprobante ? `<small class="d-block text-secondary">Factura ${esc(x.comprobante)}</small>` : ''}${marcaAnulado(x)}</td><td class="text-end monto">${fmtQ(x.monto)}</td>
            <td class="text-end">${+x.anulado ? '' : botonAnular('gasto', x.id_gasto)}</td></tr>`).join('')
            : `<tr><td colspan="5">${vacio('receipt', 'No hay gastos en este rango.')}</td></tr>`}
        </tbody>${d.gastos.length ? `<tfoot><tr class="fila-total"><td colspan="3">Total (sin anulados)</td><td class="text-end">${fmtQ(total)}</td><td></td></tr></tfoot>` : ''}</table></div></section></div>
        <div class="col-xl-4"><section class="panel"><div class="panel-cab"><h2>Por categoría</h2></div><div class="panel-cuerpo">
        ${cats.length ? `<table class="table tabla-totales mb-0"><tbody>${cats.map(([k, v]) => `<tr><td>${esc(k)}${barra(v, max)}</td><td class="text-end text-nowrap align-top">${fmtQ(v)}</td></tr>`).join('')}</tbody></table>`
            : vacio('pie-chart', 'Sin datos.')}</div></section></div></div>`;
    activarRango('gas', rangoGas);
    activarAnular(c, (t, idG) => { const x = d.gastos.find(y => y.id_gasto == idG);
        return `Gasto de <strong>${fmtQ(x.monto)}</strong> (${esc(x.categoria)}: ${esc(x.descripcion)}) del ${fmtFecha(x.fecha)}.`; });
}

function formGasto(d) {
    abrirForm({
        titulo: 'Registrar gasto',
        cuerpo: `<div class="row g-3">
            ${selector({ nombre: 'categoria', etiqueta: 'Categoría', opciones: opcionesDe(d.categorias), req: true })}
            ${campo({ nombre: 'monto', etiqueta: 'Monto (Q)', tipo: 'number', req: true, attrs: 'min="0.01" step="0.01"' })}
            ${campo({ nombre: 'descripcion', etiqueta: 'Descripción', req: true, col: 'col-12', attrs: 'maxlength="255" placeholder="Factura de energía de septiembre"' })}
            ${campo({ nombre: 'fecha', etiqueta: 'Fecha', tipo: 'date', valor: hoy(), req: true, attrs: `max="${hoy()}"` })}
            ${campo({ nombre: 'comprobante', etiqueta: 'No. de factura', attrs: 'maxlength="60"' })}</div>`,
        alEnviar: async f => {
            const r = await api('gasto_registrar', f);
            cerrarForm(); aviso(r.mensaje); recargarVista();
        },
    });
}
