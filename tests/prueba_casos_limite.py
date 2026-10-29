"""
Pruebas de casos límite ("¿y si…?") de datos, caja y laboratorio — Asilo Cabeza de Algodón
Ejecutar con el sistema levantado y la base recién importada:
    python tests/prueba_casos_limite.py [http://localhost/asilo-cabeza-algodon/public]
Requiere: pip install requests
"""
import re, sys, json, datetime, calendar, requests

BASE = (sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8080').rstrip('/')
CLAVE = 'Asilo2026!'
resultados = []

def caso(cid, descripcion, esperado, ok, obtenido=''):
    resultados.append({'id': cid, 'caso': descripcion, 'esperado': esperado, 'resultado': 'Aprobado' if ok else 'Fallido', 'obtenido': str(obtenido)})
    print(f"{'✔' if ok else '✘'} {cid} {descripcion} -> {obtenido}")

class Cliente:
    def __init__(self, usuario):
        self.s = requests.Session()
        self.csrf = re.search(r'csrf-token" content="([a-f0-9]+)', self.s.get(f'{BASE}/login.php').text).group(1)
        self.post('login', {'email': f'{usuario}@asilo.com', 'password': CLAVE})
    def post(self, accion, datos=None):
        r = self.s.post(f'{BASE}/api.php?action={accion}', json=datos or {}, headers={'X-CSRF-Token': self.csrf})
        return r.status_code, r.json()
    def get(self, accion, **params):
        r = self.s.get(f'{BASE}/api.php', params={'action': accion, **params})
        return r.status_code, r.json()

admin, conta, lab = Cliente('admin'), Cliente('contador'), Cliente('laboratorio')
fam = lambda **k: {'nombre': 'Rosa', 'apellido': 'Prueba Límite', 'telefono': '5512-3487', 'parentesco': 'Hija', 'documento_id': '9999888830101', **k}
msg = lambda j: j.get('message') or j.get('data', {}).get('mensaje', '')

# ---------- ¿Y si escriben letras en el DPI? ----------
st, j = admin.post('familiar_guardar', fam(documento_id='12345ABC67890'))
caso('CL-01', 'DPI con letras', 'Rechazado (422)', st == 422, msg(j))
st, j = admin.post('familiar_guardar', fam(documento_id='999988883010'))
caso('CL-02', 'DPI con 12 dígitos', 'Rechazado (422)', st == 422, msg(j))
st, j = admin.post('familiar_guardar', fam(documento_id='9999888810101'))
caso('CL-03', 'DPI con dígito verificador incorrecto', 'Rechazado (422)', st == 422, msg(j))
st, j = admin.post('familiar_guardar', fam(documento_id='9999888832399'))
caso('CL-04', 'DPI con departamento inexistente (23)', 'Rechazado (422)', st == 422, msg(j))
st, j = admin.post('familiar_guardar', fam(telefono='55AB-3487'))
caso('CL-05', 'Teléfono con letras', 'Rechazado (422)', st == 422, msg(j))
st, j = admin.post('familiar_guardar', fam(telefono='551-3487'))
caso('CL-06', 'Teléfono con 7 dígitos', 'Rechazado (422)', st == 422, msg(j))
st, j = admin.post('familiar_guardar', fam(nombre='Rosa2'))
caso('CL-07', 'Nombre con números', 'Rechazado (422)', st == 422, msg(j))
st, j = admin.post('familiar_guardar', fam(documento_id='9999 88883 0101', telefono='+502 5512 3487'))
idf = j.get('data', {}).get('id')
_, lista = admin.get('familiares')
f = next((x for x in lista['data'] if x['id_familiar'] == idf), {})
caso('CL-08', 'DPI con espacios y teléfono con +502 válidos', 'Se guarda limpio: 13 dígitos y 5512-3487', st == 200 and f.get('documento_id') == '9999888830101' and f.get('telefono') == '5512-3487', f"{f.get('documento_id')} / {f.get('telefono')}")
st, j = admin.post('familiar_guardar', fam(documento_id='9999888830101', nombre='Otra'))
caso('CL-09', 'Mismo DPI en otro familiar', 'Rechazado (409)', st == 409, msg(j))
st, j = admin.post('residente_guardar', {'nombre': 'Juan', 'apellido': 'Límite', 'fecha_nacimiento': '1945-03-10', 'genero': 'M', 'id_familiar': 1,
                                         'id_habitacion': 3, 'id_plan': 1, 'motivo_ingreso': 'Prueba de DPI', 'documento_id': 'DPI-ABC'})
caso('CL-10', 'Residente con DPI con letras', 'Rechazado (422)', st == 422, msg(j))

# ---------- ¿Y si el residente ingresa a mitad de mes? ----------
hoy = datetime.date.today()
periodo = hoy.strftime('%Y-%m')
dia = max(2, min(15, hoy.day))
ingreso = hoy.replace(day=dia).isoformat()
st, j = admin.post('residente_guardar', {'nombre': 'Juan', 'apellido': 'Mitad de Mes', 'fecha_nacimiento': '1945-03-10', 'genero': 'M', 'id_familiar': 1,
                                         'id_habitacion': 3, 'id_plan': 1, 'motivo_ingreso': 'Prueba de cuota proporcional', 'fecha_ingreso': ingreso})
idr = j.get('data', {}).get('id')
_, pv = conta.get('cuotas_preview', periodo=periodo)
fila = next((x for x in pv['data']['residentes'] if x['id_residente'] == idr), {})
dias_mes = calendar.monthrange(hoy.year, hoy.month)[1]
esperado = round(float(fila.get('cuota_completa', 0)) * (dias_mes - dia + 1) / dias_mes, 2)
caso('CL-11', f'Ingresó el día {dia}: la vista previa calcula cuota proporcional', f'{dias_mes - dia + 1} de {dias_mes} días = Q {esperado}',
     fila.get('proporcional') is True and abs(fila.get('monto', 0) - esperado) < 0.01, f"Q {fila.get('monto')} de Q {fila.get('cuota_completa')}")
otros = [x for x in pv['data']['residentes'] if x['id_residente'] != idr and x['fecha_ingreso'][:7] != periodo]
caso('CL-12', 'Residentes que ingresaron antes de este mes pagan el mes completo', 'Sin cuota proporcional', all(not x['proporcional'] for x in otros), f'{len(otros)} residentes')
st, j = conta.post('cuotas_generar', {'periodo': periodo})
_, cta = conta.get('cuenta_estado', id_residente=idr)
cuota = next((m for m in cta['data']['movimientos'] if m['tipo'] == 'Cuota mensual'), {})
caso('CL-13', 'Al generar cuotas se carga el monto proporcional con su explicación', 'Concepto "Cuota proporcional"',
     abs(float(cuota.get('monto', 0)) - esperado) < 0.01 and 'proporcional' in cuota.get('concepto', ''), cuota.get('concepto'))

# ---------- ¿Y si en caja se registra un pago por error? ----------
_, c0 = conta.get('cuenta_estado', id_residente=4)
saldo0 = c0['data']['saldo']
st, j = conta.post('pago_registrar', {'id_residente': 4, 'monto': 100, 'metodo': 'Efectivo'})
idp = j['data']['id']
_, c1 = conta.get('cuenta_estado', id_residente=4)
caso('CL-14', 'Pago de Q 100 registrado', 'Saldo baja Q 100', round(saldo0 - c1['data']['saldo'], 2) == 100, f"{saldo0} -> {c1['data']['saldo']}")
st, j = conta.post('caja_anular', {'tipo': 'pago', 'id': idp, 'motivo': 'error'})
caso('CL-15', 'Anular sin explicar el motivo', 'Rechazado (422)', st == 422, msg(j))
_, r0 = conta.get('caja_resumen')
st, j = conta.post('caja_anular', {'tipo': 'pago', 'id': idp, 'motivo': 'Se registró dos veces el mismo pago en efectivo'})
_, c2 = conta.get('cuenta_estado', id_residente=4)
mov = next(m for m in c2['data']['movimientos'] if m['clase'] == 'pago' and m['id'] == idp)
caso('CL-16', 'Anular el pago con motivo', 'Saldo vuelve al anterior y el pago sigue visible como anulado',
     st == 200 and c2['data']['saldo'] == saldo0 and int(mov['anulado']) == 1, f"saldo {c2['data']['saldo']}, anulado={mov['anulado']}")
_, r1 = conta.get('caja_resumen')
caso('CL-17', 'El resumen de caja ya no cuenta el pago anulado', 'Cobros bajan Q 100',
     round(r0['data']['entradas']['cobros_familiares'] - r1['data']['entradas']['cobros_familiares'], 2) == 100,
     f"{r0['data']['entradas']['cobros_familiares']} -> {r1['data']['entradas']['cobros_familiares']}")
st, j = conta.post('caja_anular', {'tipo': 'pago', 'id': idp, 'motivo': 'Intento de anular por segunda vez'})
caso('CL-18', 'Anular dos veces el mismo pago', 'Rechazado (409)', st == 409, msg(j))
_, rep = conta.get('reporte', tipo='cobros', id_residente=4, desde='2020-01-01', hasta=hoy.isoformat())
pagos_rep = [p for x in rep['data']['datos'].get('pacientes', []) for p in x.get('pagos', [])]
caso('CL-19', 'El reporte de cobros no incluye el pago anulado', 'Aparecen los demás pagos, no el anulado', len(pagos_rep) > 0 and all(int(p.get('id_pago')) != idp for p in pagos_rep), f'{len(pagos_rep)} pago(s) válidos en el reporte')
st, j = conta.post('donacion_registrar', {'tipo_donante': 'Particular', 'donante': 'Prueba', 'monto': 50, 'tipo_donacion': 'Monetaria'})
idd = j.get('data', {}).get('id')
st, j = conta.post('caja_anular', {'tipo': 'donacion', 'id': idd, 'motivo': 'La donación era para otra institución'})
caso('CL-20', 'Anular una donación', 'Aceptado', st == 200, msg(j))
_, g = conta.get('gastos')
idg = g['data']['gastos'][0]['id_gasto']
st, j = Cliente('medico').post('caja_anular', {'tipo': 'gasto', 'id': idg, 'motivo': 'El médico intenta anular un gasto'})
caso('CL-21', 'El médico intenta anular un gasto', 'Sin permiso (403)', st == 403, msg(j))

# ---------- ¿Y si el laboratorio escribe mal un resultado? ----------
_, l = lab.get('laboratorio')
hecho = next(x for x in l['data']['examenes'] if x['estado'] == 'Realizado')
pendiente = next(x for x in l['data']['examenes'] if x['estado'] == 'Solicitado')
original = hecho['resultado']
st, j = lab.post('laboratorio_corregir', {'id_examen_visita': hecho['id_examen_visita'], 'resultado': 'Valor corregido', 'motivo': 'corto'})
caso('CL-22', 'Corregir sin motivo suficiente', 'Rechazado (422)', st == 422, msg(j))
st, j = lab.post('laboratorio_corregir', {'id_examen_visita': hecho['id_examen_visita'], 'resultado': original, 'motivo': 'Se intenta guardar el mismo texto'})
caso('CL-23', 'Corregir con el mismo resultado', 'Rechazado (422)', st == 422, msg(j))
st, j = lab.post('laboratorio_corregir', {'id_examen_visita': pendiente['id_examen_visita'], 'resultado': 'Algo', 'motivo': 'Examen que aún no tiene resultado'})
caso('CL-24', 'Corregir un examen sin resultado', 'Rechazado (409)', st == 409, msg(j))
st, j = conta.post('laboratorio_corregir', {'id_examen_visita': hecho['id_examen_visita'], 'resultado': 'X', 'motivo': 'El contador intenta corregir'})
caso('CL-25', 'El contador intenta corregir un resultado', 'Sin permiso (403)', st == 403, msg(j))
_, cuentas0 = conta.get('cuentas')
st, j = lab.post('laboratorio_corregir', {'id_examen_visita': hecho['id_examen_visita'], 'resultado': 'Ritmo sinusal normal (corregido)', 'motivo': 'Se anotó el resultado de otro paciente por error'})
_, l2 = lab.get('laboratorio')
ex = next(x for x in l2['data']['examenes'] if x['id_examen_visita'] == hecho['id_examen_visita'])
caso('CL-26', 'Corregir con motivo', 'Resultado nuevo, el original guardado y quién corrigió',
     st == 200 and ex['resultado'].endswith('(corregido)') and ex['resultado_original'] == original and ex['corregido_por_nombre'], f"original guardado: {ex['resultado_original'][:30]}…")
_, cuentas1 = conta.get('cuentas')
caso('CL-27', 'La corrección no cambia lo cobrado al familiar', 'Mismo total por cobrar', cuentas0['data']['por_cobrar'] == cuentas1['data']['por_cobrar'], cuentas1['data']['por_cobrar'])

aprobados = sum(1 for r in resultados if r['resultado'] == 'Aprobado')
print(f'\nResultado: {aprobados}/{len(resultados)} casos aprobados')
with open('tests/resultados_casos_limite.json', 'w', encoding='utf-8') as fh:
    json.dump(resultados, fh, ensure_ascii=False, indent=2)
print('Recuerde volver a importar database/schema.sql para limpiar los datos de prueba.')
sys.exit(0 if aprobados == len(resultados) else 1)
