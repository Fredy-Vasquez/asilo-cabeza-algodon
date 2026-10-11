"""
Pruebas automatizadas de la API — Asilo Cabeza de Algodón
Ejecutar con el sistema levantado:  python tests/prueba_api.py [http://localhost/asilo-cabeza-algodon/public]
Requiere: pip install requests   (y la base recién importada desde database/schema.sql)
"""
import re, sys, datetime, requests

BASE = (sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8080').rstrip('/')
CLAVE = 'Asilo2026!'
resultados = []

def caso(cid, modulo, descripcion, esperado, ok, obtenido=''):
    resultados.append((cid, modulo, descripcion, esperado, 'Aprobado' if ok else 'Fallido', obtenido))
    print(f"{'✔' if ok else '✘'} {cid} {descripcion} -> {obtenido}")

class Cliente:
    def __init__(self):
        self.s = requests.Session()
        html = self.s.get(f'{BASE}/login.php').text
        self.csrf = re.search(r'csrf-token" content="([a-f0-9]+)', html).group(1)
    def post(self, accion, datos=None, csrf=True):
        h = {'X-CSRF-Token': self.csrf} if csrf else {}
        r = self.s.post(f'{BASE}/api.php?action={accion}', json=datos or {}, headers=h)
        return r.status_code, r.json()
    def get(self, accion, **params):
        r = self.s.get(f'{BASE}/api.php', params={'action': accion, **params})
        return r.status_code, r.json()

def sesion(usuario):
    c = Cliente()
    c.post('login', {'email': f'{usuario}@asilo.com', 'password': CLAVE})
    return c

# ---------- 1. Autenticación y seguridad ----------
c = Cliente()
st, j = c.post('login', {'email': 'admin@asilo.com', 'password': 'malaclave'})
caso('PA-01', 'Seguridad', 'Login con contraseña incorrecta', 'Rechazado (401)', st == 401, f'{st} {j.get("message")}')
for u in ['admin', 'medico', 'contador', 'enfermera', 'fundacion', 'cardiologo', 'laboratorio', 'farmacia']:
    st, j = Cliente().post('login', {'email': f'{u}@asilo.com', 'password': CLAVE})
    caso(f'PA-02.{u}', 'Seguridad', f'Login correcto de {u}', '200 y datos del usuario', st == 200 and j['status'] == 'success', f'{st} rol={j.get("data", {}).get("rol") or j.get("data", {}).get("nombre_rol")}')
st, j = Cliente().get('residentes')
caso('PA-03', 'Seguridad', 'Acceso a la API sin sesión', 'Rechazado (401)', st == 401, f'{st}')
adm = sesion('admin')
st, j = adm.post('habitacion_guardar', {'numero': 'X1', 'capacidad': 1}, csrf=False)
caso('PA-04', 'Seguridad', 'Operación de escritura sin token CSRF', 'Rechazado (403/419)', st in (403, 419), f'{st} {j.get("message")}')
cont = sesion('contador')
st, j = cont.get('residente_expediente', id_residente=1)
caso('PA-05', 'RBAC', 'Contador intenta ver ficha médica', 'Rechazado (403)', st == 403, f'{st}')
enf = sesion('enfermera')
st, j = enf.post('solicitud_crear', {'id_residente': 1, 'id_especialidad': 1, 'motivo': 'x'})
caso('PA-06', 'RBAC', 'Enfermero intenta crear solicitud', 'Rechazado (403)', st == 403, f'{st}')
st, j = adm.get('accion_inexistente')
caso('PA-07', 'API', 'Acción inexistente', '404', st == 404, f'{st}')

# ---------- 2. Ficha médica ----------
med = sesion('medico')
st, j = med.get('residente_expediente', id_residente=1)
d = j.get('data', {})
caso('PA-08', 'Ficha médica', 'Expediente incluye padecimientos, permanentes y visitas', 'Claves presentes',
     st == 200 and all(k in d for k in ('padecimientos', 'medicamentos_permanentes', 'visitas', 'evaluaciones')), f'{st} claves={len(d)}')
st, j = med.post('padecimiento_guardar', {'id_residente': 1, 'tipo': 'Psicopatología', 'descripcion': 'Ansiedad leve (prueba)'})
caso('PA-09', 'Ficha médica', 'Registrar psicopatología', '200', st == 200, f'{st} {j.get("message", "")}')
st, j = med.post('padecimiento_guardar', {'id_residente': 1, 'tipo': 'Psicopatología', 'descripcion': ''})
caso('PA-10', 'Ficha médica', 'Psicopatología sin descripción', 'Rechazado (422)', st == 422, f'{st} {j.get("message")}')

# ---------- 3. Evaluación / microservicio IMC ----------
st, j = med.post('evaluacion_guardar', {'id_residente': 1, 'peso_kg': 70, 'altura_m': 1.65, 'presion_sistolica': 150, 'presion_diastolica': 95})
caso('PA-11', 'Evaluación', 'Evaluación con IMC (microservicio)', '200 con IMC calculado', st == 200, f'{st} {str(j.get("data"))[:90]}')
st, j = med.post('evaluacion_guardar', {'id_residente': 1, 'peso_kg': 500, 'altura_m': 1.65})
caso('PA-12', 'Evaluación', 'Peso fuera de rango (500 kg)', 'Rechazado (422)', st == 422, f'{st} {j.get("message")}')

# ---------- 4. Solicitud médica ----------
st, j = med.get('solicitudes_opciones')
esp = {e['nombre']: int(e['id_especialidad']) for e in j['data']['especialidades']}
id_cardio = next(v for k, v in esp.items() if k.startswith('Cardio'))
id_enf = int(j['data']['enfermeros'][0]['id_usuario'])
st, j = med.post('solicitud_crear', {'id_residente': 1, 'id_especialidad': id_cardio, 'id_enfermero': id_enf,
                                     'motivo': 'Dolor torácico al esfuerzo (prueba)', 'prioridad': 'Urgente'})
id_sol = j.get('data', {}).get('id')
caso('PA-13', 'Solicitudes', 'Médico general crea solicitud con enfermero', '200 y correo intentado', st == 200 and id_sol, f'{st} id={id_sol} correo={j.get("data", {}).get("correo", {}).get("estado")}')
st, j = med.post('solicitud_crear', {'id_residente': 1, 'id_especialidad': id_cardio, 'motivo': ''})
caso('PA-14', 'Solicitudes', 'Solicitud sin motivo', 'Rechazado (422)', st == 422, f'{st} {j.get("message")}')

# ---------- 5. Programación por la fundación ----------
fun = sesion('fundacion')
manana = (datetime.date.today() + datetime.timedelta(days=2)).isoformat()
st, j = fun.post('solicitud_programar', {'id_solicitud': id_sol, 'id_medico_asignado': 8, 'fecha_programada': f'{manana}T09:00'})
caso('PA-15', 'Fundación', 'Asignar médico de otra especialidad', 'Rechazado (422)', st == 422, f'{st} {j.get("message")}')
st, j = fun.post('solicitud_programar', {'id_solicitud': id_sol, 'id_medico_asignado': 7, 'fecha_programada': f'{manana}T20:00'})
caso('PA-16', 'Fundación', 'Programar fuera de horario (20:00)', 'Rechazado (422)', st == 422, f'{st} {j.get("message")}')
st, j = fun.post('solicitud_programar', {'id_solicitud': id_sol, 'id_medico_asignado': 7, 'fecha_programada': '2020-01-01T09:00'})
caso('PA-17', 'Fundación', 'Programar en el pasado', 'Rechazado (422)', st == 422, f'{st} {j.get("message")}')
st, j = fun.post('solicitud_programar', {'id_solicitud': id_sol, 'id_medico_asignado': 7, 'fecha_programada': f'{manana}T09:00'})
caso('PA-18', 'Fundación', 'Programar cita válida', '200 y correo al familiar', st == 200, f'{st} correo={j.get("data", {}).get("correo", {}).get("estado")}')
st, j = med.post('solicitud_crear', {'id_residente': 2, 'id_especialidad': id_cardio, 'motivo': 'Control (prueba choque)'})
id_sol2 = j['data']['id']
st, j = fun.post('solicitud_programar', {'id_solicitud': id_sol2, 'id_medico_asignado': 7, 'fecha_programada': f'{manana}T09:30'})
caso('PA-19', 'Fundación', 'Choque de horario del médico (30 min después)', 'Rechazado (409)', st == 409, f'{st} {j.get("message")}')

# ---------- 6. Visita del especialista ----------
otro = sesion('psiquiatra')
st, j = otro.post('visita_iniciar', {'id_solicitud': id_sol})
caso('PA-20', 'Visita', 'Otro especialista intenta atender la cita', 'Rechazado (403)', st == 403, f'{st} {j.get("message")}')
car = sesion('cardiologo')
st, j = car.post('visita_iniciar', {'id_solicitud': id_sol})
id_vis = j.get('data', {}).get('id_visita')
caso('PA-21', 'Visita', 'Especialista asignado inicia la visita', '200', st == 200 and id_vis, f'{st} visita={id_vis}')
st, j = car.get('visita_detalle', id_solicitud=id_sol)
det = j['data']
caso('PA-22', 'Visita', 'Detalle muestra ficha, historial y catálogos', 'Claves presentes',
     all(k in det for k in ('padecimientos', 'historial', 'catalogo_examenes', 'catalogo_medicamentos')), f'descuento={det.get("descuento_pct")}%')
ex = int(det['catalogo_examenes'][0]['id_examen'])
st, j = car.post('visita_examen_agregar', {'id_visita': id_vis, 'id_examen': ex})
caso('PA-23', 'Visita', 'Solicitar examen de laboratorio', '200', st == 200, f'{st} {j.get("data", {}).get("mensaje")}')
st, j = car.post('visita_examen_agregar', {'id_visita': id_vis, 'id_examen': ex})
caso('PA-24', 'Visita', 'Solicitar el mismo examen dos veces', 'Rechazado (409)', st == 409, f'{st} {j.get("message")}')
medi = int(det['catalogo_medicamentos'][0]['id_medicamento'])
st, j = car.post('visita_receta_agregar', {'id_visita': id_vis, 'id_medicamento': medi, 'cantidad': 30, 'indicaciones': '1 cada 24 h', 'duracion_dias': 30})
caso('PA-25', 'Visita', 'Recetar medicamento', '200', st == 200, f'{st} {j.get("data", {}).get("mensaje")}')
st, j = car.post('visita_receta_agregar', {'id_visita': id_vis, 'id_medicamento': medi, 'cantidad': 0, 'indicaciones': 'x'})
caso('PA-26', 'Visita', 'Receta con cantidad 0', 'Rechazado (422)', st == 422, f'{st} {j.get("message")}')
st, j = car.post('visita_finalizar', {'id_visita': id_vis, 'diagnostico': ''})
caso('PA-27', 'Visita', 'Finalizar sin diagnóstico', 'Rechazado (422)', st == 422, f'{st} {j.get("message")}')
st, j = car.post('visita_finalizar', {'id_visita': id_vis, 'diagnostico': 'Angina estable (prueba)', 'observaciones': 'Control en 1 mes'})
caso('PA-28', 'Visita', 'Finalizar visita con diagnóstico', '200 y cargo de consulta', st == 200, f'{st} {j.get("data", {}).get("mensaje", "")[:60]}')
st, j = car.post('visita_guardar', {'id_visita': id_vis, 'diagnostico': 'cambio'})
caso('PA-29', 'Visita', 'Modificar visita ya finalizada', 'Rechazado (409)', st == 409, f'{st} {j.get("message")}')
st, j = med.get('residente_expediente', id_residente=1)
vis = [v for v in j['data']['visitas'] if int(v.get('id_visita', 0)) == int(id_vis)]
caso('PA-30', 'Ficha médica', 'La visita queda en el historial del residente', 'Aparece en expediente', bool(vis), f'visitas={len(j["data"]["visitas"])}')

# ---------- 7. Cancelación ----------
st, j = fun.post('solicitud_cancelar', {'id_solicitud': id_sol2, 'motivo': 'Familiar trasladó al residente (prueba)'})
caso('PA-31', 'Solicitudes', 'Cancelar solicitud pendiente', '200', st == 200, f'{st}')
st, j = fun.post('solicitud_cancelar', {'id_solicitud': id_sol, 'motivo': 'x'})
caso('PA-32', 'Solicitudes', 'Cancelar solicitud ya atendida', 'Rechazado (409)', st == 409, f'{st} {j.get("message")}')

# ---------- 8. Cotizador / administración ----------
st, j = cont.get('cotizador_catalogo')
caso('PA-33', 'Caja', 'Catálogo del cotizador (microservicio)', '200', st == 200, f'{st}')
st, j = adm.get('bitacora')
acciones = {b.get('accion') for b in j.get('data', [])}
caso('PA-34', 'Auditoría', 'Bitácora registra el flujo completo', 'SOLICITUD_CREADA y VISITA_FINALIZADA',
     {'SOLICITUD_CREADA', 'VISITA_FINALIZADA'} <= acciones, f'{len(j.get("data", []))} registros')

ap = sum(1 for r in resultados if r[4] == 'Aprobado')
print(f'\nResultado: {ap}/{len(resultados)} casos aprobados')
with open('tests/resultados.csv', 'w', encoding='utf-8') as f:
    f.write('id,modulo,caso,esperado,resultado,obtenido\n')
    for r in resultados:
        f.write(','.join('"' + str(x).replace('"', "'") + '"' for x in r) + '\n')
