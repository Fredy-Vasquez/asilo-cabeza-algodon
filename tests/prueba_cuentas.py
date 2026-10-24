"""
Pruebas de casos límite ("¿y si…?") de cuentas y contraseñas — Asilo Cabeza de Algodón
Ejecutar con el sistema levantado y la base recién importada:
    python tests/prueba_cuentas.py [http://localhost/asilo-cabeza-algodon/public]
Requiere: pip install requests
"""
import re, sys, json, requests

BASE = (sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8080').rstrip('/')
CLAVE = 'Asilo2026!'
resultados = []

def caso(cid, descripcion, esperado, ok, obtenido=''):
    resultados.append({'id': cid, 'caso': descripcion, 'esperado': esperado, 'resultado': 'Aprobado' if ok else 'Fallido', 'obtenido': str(obtenido)})
    print(f"{'✔' if ok else '✘'} {cid} {descripcion} -> {obtenido}")

class Cliente:
    def __init__(self):
        self.s = requests.Session()
        html = self.s.get(f'{BASE}/login.php').text
        self.csrf = re.search(r'csrf-token" content="([a-f0-9]+)', html).group(1)
    def post(self, accion, datos=None):
        r = self.s.post(f'{BASE}/api.php?action={accion}', json=datos or {}, headers={'X-CSRF-Token': self.csrf})
        return r.status_code, r.json()
    def get(self, accion, **params):
        r = self.s.get(f'{BASE}/api.php', params={'action': accion, **params})
        return r.status_code, r.json()

def sesion(email, clave=CLAVE):
    c = Cliente()
    st, j = c.post('login', {'email': email, 'password': clave})
    return c, st, j

admin, _, _ = sesion('admin@asilo.com')

# ---------- ¿Y si alguien olvida su contraseña? ----------
an = Cliente()
st, j = an.post('olvide_clave', {'email': 'noexiste@asilo.com'})
generico = j.get('data', {}).get('mensaje', '')
caso('CU-01', '¿Olvidó su contraseña? con un correo que no existe', 'Mismo mensaje genérico (no revela cuentas)', st == 200 and 'Si el correo está registrado' in generico, st)
st, j = an.post('olvide_clave', {'email': 'medico@asilo.com'})
caso('CU-02', '¿Olvidó su contraseña? con un correo registrado', 'Mismo mensaje genérico', st == 200 and j['data']['mensaje'] == generico, st)
st, j = an.post('olvide_clave', {'email': 'medico@asilo.com'})
st, u = admin.get('usuarios')
pend = [s for s in u['data']['solicitudes_clave'] if s['email'] == 'medico@asilo.com']
caso('CU-03', 'Pedirlo dos veces no duplica la solicitud', '1 solicitud pendiente', len(pend) == 1, len(pend))
st, j = an.post('olvide_clave', {'email': 'medico@asilo.com'})
caso('CU-04', 'Más de 3 solicitudes en 10 minutos desde el mismo navegador', 'Rechazado (429)', st == 429, f'{st} {j.get("message")}')
st, j = an.post('olvide_clave', {'email': 'no-es-correo'})
caso('CU-05', 'Correo con formato inválido', 'Rechazado (422 o 429)', st in (422, 429), st)
st, d = admin.get('dashboard')
caso('CU-06', 'El administrador ve el aviso en el inicio', 'solicitudes_clave = 1', d['data']['solicitudes_clave'] == 1, d['data']['solicitudes_clave'])

# ---------- ¿Y si el admin le genera una temporal mientras está conectado? ----------
medico_viejo, _, _ = sesion('medico@asilo.com')
st, _ = medico_viejo.get('dashboard')
caso('CU-07', 'El médico trabaja normalmente antes del cambio', '200', st == 200, st)
id_med = pend[0]['id_usuario']
st, r = admin.post('usuario_restablecer', {'id_usuario': id_med, 'enviar_correo': False})
temporal = r.get('data', {}).get('clave_temporal', '')
caso('CU-08', 'El admin genera una contraseña temporal', 'Clave con letras y números', st == 200 and re.match(r'^[A-Za-z]+-\d{4}$', temporal), temporal)
st, _ = medico_viejo.get('dashboard')
caso('CU-09', 'La sesión abierta del médico se cierra sola', '401', st == 401, st)
st, u = admin.get('usuarios')
caso('CU-10', 'La solicitud queda atendida', '0 pendientes', len(u['data']['solicitudes_clave']) == 0, len(u['data']['solicitudes_clave']))
_, st, _ = sesion('medico@asilo.com', CLAVE)
caso('CU-11', 'La contraseña anterior ya no sirve', '401', st == 401, st)

# ---------- ¿Y si entra con la temporal e intenta saltarse el cambio? ----------
med, st, j = sesion('medico@asilo.com', temporal)
caso('CU-12', 'Ingreso con la contraseña temporal', 'debe_cambiar = true', st == 200 and j['data']['debe_cambiar'] is True, j.get('data', {}).get('debe_cambiar'))
st, j = med.get('residentes')
caso('CU-13', 'Intenta usar el sistema sin cambiarla', 'Bloqueado (403)', st == 403, f'{st} {j.get("message")}')
r = med.s.get(f'{BASE}/index.php', allow_redirects=False)
caso('CU-14', 'Intenta abrir el panel principal', 'Redirige a cambiar-clave.php', r.status_code == 302 and 'cambiar-clave.php' in r.headers.get('Location', ''), r.headers.get('Location'))
st, j = med.post('cambiar_clave', {'actual': 'otra', 'nueva': 'Nueva2026x', 'confirmar': 'Nueva2026x'})
caso('CU-15', 'Cambio con la temporal equivocada', 'Rechazado (422)', st == 422, j.get('message'))
st, j = med.post('cambiar_clave', {'actual': temporal, 'nueva': 'corta1', 'confirmar': 'corta1'})
caso('CU-16', 'Contraseña nueva débil (menos de 8)', 'Rechazado (422)', st == 422, j.get('message'))
st, j = med.post('cambiar_clave', {'actual': temporal, 'nueva': 'soloLetrasAqui', 'confirmar': 'soloLetrasAqui'})
caso('CU-17', 'Contraseña nueva sin números', 'Rechazado (422)', st == 422, j.get('message'))
st, j = med.post('cambiar_clave', {'actual': temporal, 'nueva': 'Nueva2026x', 'confirmar': 'Nueva2026y'})
caso('CU-18', 'La confirmación no coincide', 'Rechazado (422)', st == 422, j.get('message'))
st, j = med.post('cambiar_clave', {'actual': temporal, 'nueva': temporal, 'confirmar': temporal})
caso('CU-19', 'Repetir la misma temporal como nueva', 'Rechazado (422)', st == 422, j.get('message'))
st, j = med.post('cambiar_clave', {'actual': temporal, 'nueva': CLAVE, 'confirmar': CLAVE})
caso('CU-20', 'Cambio correcto', '200', st == 200, j.get('data', {}).get('mensaje') or j.get('message'))
st, _ = med.get('residentes')
caso('CU-21', 'Después del cambio sigue en su sesión y puede trabajar', '200', st == 200, st)

# ---------- ¿Y si alguien intenta adivinar la contraseña? ----------
for i in range(4):
    _, st, j = sesion('enfermera@asilo.com', f'mala{i}')
caso('CU-22', 'Al 4.º intento fallido se le advierte', 'Mensaje con intentos restantes', 'Le quedan 1' in j.get('message', ''), j.get('message'))
_, st, j = sesion('enfermera@asilo.com', 'mala5')
caso('CU-23', '5.º intento fallido bloquea la cuenta', 'Bloqueado (429)', st == 429, j.get('message', '')[:70])
_, st, j = sesion('enfermera@asilo.com', CLAVE)
caso('CU-24', 'Ni con la contraseña correcta entra mientras está bloqueada', 'Bloqueado (429)', st == 429, st)
st, u = admin.get('usuarios')
enf = next(x for x in u['data']['usuarios'] if x['email'] == 'enfermera@asilo.com')
caso('CU-25', 'El admin ve la cuenta como bloqueada', 'bloqueado_hasta con fecha', bool(enf['bloqueado_hasta']), enf['bloqueado_hasta'])
st, r = admin.post('usuario_restablecer', {'id_usuario': enf['id_usuario']})
enf_c, st, j = sesion('enfermera@asilo.com', r['data']['clave_temporal'])
caso('CU-26', 'Restablecer la contraseña desbloquea la cuenta', 'Ingresa (200)', st == 200, st)
enf_c.post('cambiar_clave', {'actual': r['data']['clave_temporal'], 'nueva': CLAVE, 'confirmar': CLAVE})
_, st, _ = sesion('farmacia@asilo.com', 'mala')
_, st2, _ = sesion('farmacia@asilo.com', CLAVE)
caso('CU-27', 'Un intento fallido seguido de uno correcto reinicia el contador', 'Ingresa (200)', st2 == 200, st2)

# ---------- ¿Y si el admin desactiva o cambia de rol a alguien conectado? ----------
cont, _, _ = sesion('contador@asilo.com')
st, u = admin.get('usuarios')
cu = next(x for x in u['data']['usuarios'] if x['email'] == 'contador@asilo.com')
datos = {k: cu[k] for k in ('id_usuario', 'nombre', 'email', 'id_rol', 'telefono', 'id_especialidad')}
st, _ = admin.post('usuario_guardar', {**datos, 'estado': 'inactivo'})
st, j = cont.get('cuentas')
caso('CU-28', 'Desactivar a un usuario conectado cierra su sesión', '401', st == 401, f'{st} {j.get("message")}')
_, st, j = sesion('contador@asilo.com')
caso('CU-29', 'Un usuario desactivado no puede ingresar', '403', st == 403, j.get('message'))
admin.post('usuario_guardar', {**datos, 'estado': 'activo'})
cont, _, _ = sesion('contador@asilo.com')
st, _ = admin.post('usuario_guardar', {**datos, 'estado': 'activo', 'id_rol': 4})
st, _ = cont.get('cuentas')
caso('CU-30', 'Cambiarle el rol cierra su sesión (no conserva permisos viejos)', '401', st == 401, st)
admin.post('usuario_guardar', {**datos, 'estado': 'activo', 'id_rol': 3})
cont, _, _ = sesion('contador@asilo.com')
st, _ = admin.post('usuario_guardar', {**datos, 'estado': 'activo', 'telefono': '5555-0000'})
st, _ = cont.get('cuentas')
caso('CU-31', 'Editar solo el teléfono NO le cierra la sesión', '200', st == 200, st)

# ---------- ¿Y si el admin se restablece a sí mismo o crea usuarios? ----------
st, u = admin.get('usuarios')
yo = next(x for x in u['data']['usuarios'] if x['email'] == 'admin@asilo.com')
st, j = admin.post('usuario_restablecer', {'id_usuario': yo['id_usuario']})
caso('CU-32', 'El admin intenta generarse una temporal a sí mismo', 'Rechazado (409)', st == 409, j.get('message'))
st, j = admin.post('usuario_guardar', {'nombre': 'Usuario Prueba', 'email': 'prueba.cuentas@asilo.com', 'id_rol': 4, 'estado': 'activo', 'password': 'Inicial2026'})
nuevo, st, j = sesion('prueba.cuentas@asilo.com', 'Inicial2026')
caso('CU-33', 'Un usuario nuevo debe cambiar la contraseña al primer ingreso', 'debe_cambiar = true', j['data']['debe_cambiar'] is True, j['data']['debe_cambiar'])
st, j = admin.post('cambiar_clave', {'actual': 'incorrecta', 'nueva': 'Otra2026x', 'confirmar': 'Otra2026x'})
caso('CU-34', '"Cambiar mi contraseña" con la actual equivocada', 'Rechazado (422)', st == 422, j.get('message'))
st, j = admin.post('cambiar_clave', {'actual': CLAVE, 'nueva': 'Otra2026x', 'confirmar': 'Otra2026x'})
st2, _ = admin.get('dashboard')
caso('CU-35', 'Al cambiar su propia contraseña conserva su sesión actual', '200 y sigue conectado', st == 200 and st2 == 200, f'{st}/{st2}')
admin.post('cambiar_clave', {'actual': 'Otra2026x', 'nueva': CLAVE, 'confirmar': CLAVE})

# ---------- Descartar ----------
Cliente().post('olvide_clave', {'email': 'fundacion@asilo.com'})
st, u = admin.get('usuarios')
s = u['data']['solicitudes_clave'][0]
st, j = admin.post('solicitud_clave_descartar', {'id_solicitud_clave': s['id_solicitud_clave']})
st2, j2 = admin.post('solicitud_clave_descartar', {'id_solicitud_clave': s['id_solicitud_clave']})
caso('CU-36', 'Descartar una solicitud (y no se puede descartar dos veces)', '200 y luego 409', st == 200 and st2 == 409, f'{st}/{st2}')
_, st, _ = sesion('fundacion@asilo.com')
caso('CU-37', 'Descartar no cambia la contraseña del usuario', 'Ingresa (200)', st == 200, st)

aprobados = sum(1 for r in resultados if r['resultado'] == 'Aprobado')
print(f'\nResultado: {aprobados}/{len(resultados)} casos aprobados')
with open('tests/resultados_cuentas.json', 'w', encoding='utf-8') as f:
    json.dump(resultados, f, ensure_ascii=False, indent=2)
print('Recuerde volver a importar database/schema.sql para limpiar los datos de prueba.')
sys.exit(0 if aprobados == len(resultados) else 1)
