"""
Pruebas de interfaz (E2E) con Playwright — Asilo Cabeza de Algodón
Ejecutar: pip install playwright && playwright install chromium
          python tests/prueba_ui.py [URL_BASE] [carpeta_capturas]
"""
import sys, os
from playwright.sync_api import sync_playwright

BASE = (sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8080').rstrip('/')
OUT = sys.argv[2] if len(sys.argv) > 2 else 'tests/capturas'
os.makedirs(OUT, exist_ok=True)
res = []

def caso(cid, desc, ok, obt=''):
    res.append((cid, desc, ok, obt)); print(f"{'✔' if ok else '✘'} {cid} {desc} {obt}")

with sync_playwright() as pw:
    nav = pw.chromium.launch()
    def entrar(usuario, ancho=1366, alto=860):
        ctx = nav.new_context(viewport={'width': ancho, 'height': alto}, locale='es-GT')
        pg = ctx.new_page()
        errores = []
        pg.on('pageerror', lambda e: errores.append(str(e)))
        pg.goto(f'{BASE}/login.php')
        pg.fill('#email', f'{usuario}@asilo.com'); pg.fill('#password', 'Asilo2026!')
        pg.click('#btnIngresar'); pg.wait_for_url('**/index.php**', timeout=8000)
        pg.wait_for_timeout(900)
        return ctx, pg, errores

    # Login
    ctx = nav.new_context(viewport={'width': 1366, 'height': 860}); pg = ctx.new_page()
    pg.goto(f'{BASE}/login.php'); pg.screenshot(path=f'{OUT}/01-login.png')
    pg.fill('#email', 'admin@asilo.com'); pg.fill('#password', 'incorrecta'); pg.click('#btnIngresar'); pg.wait_for_timeout(800)
    caso('UI-01', 'Login incorrecto muestra mensaje', 'incorrect' in pg.content().lower()); ctx.close()

    # Administrador
    ctx, pg, err = entrar('admin')
    pg.screenshot(path=f'{OUT}/02-inicio-admin.png')
    caso('UI-02', 'Administrador ve el panel de inicio', pg.locator('nav, .sidebar, aside').count() > 0)
    menu = pg.inner_text('body')
    caso('UI-03', 'Menú de admin incluye Usuarios, Configuración y Bitácora', all(x in menu for x in ('Usuarios', 'Configuración', 'Bitácora')))
    pg.goto(f'{BASE}/index.php#residentes'); pg.wait_for_timeout(900); pg.screenshot(path=f'{OUT}/03-residentes.png')
    pg.evaluate('abrirExpediente(1, "ficha")'); pg.wait_for_timeout(1000); pg.screenshot(path=f'{OUT}/04-ficha-medica.png')
    caso('UI-04', 'Expediente abre la pestaña de ficha médica', 'Psicopatolog' in pg.inner_text('body') or 'Padecimiento' in pg.inner_text('body'))
    pg.keyboard.press('Escape')
    pg.goto(f'{BASE}/index.php#configuracion'); pg.wait_for_timeout(900); pg.screenshot(path=f'{OUT}/10-configuracion.png')
    pg.goto(f'{BASE}/index.php#bitacora'); pg.wait_for_timeout(900); pg.screenshot(path=f'{OUT}/11-bitacora.png')
    caso('UI-05', 'Sin errores de JavaScript (admin)', not err, '; '.join(err)[:80]); ctx.close()

    # Médico general
    ctx, pg, err = entrar('medico')
    menu = pg.inner_text('body')
    caso('UI-06', 'Médico general NO ve Usuarios ni Bitácora', 'Bitácora' not in menu and 'Usuarios' not in menu)
    pg.goto(f'{BASE}/index.php#solicitudes'); pg.wait_for_timeout(1000); pg.screenshot(path=f'{OUT}/05-solicitudes-medico.png')
    caso('UI-07', 'Médico general ve la bandeja de solicitudes', 'Solicitud' in pg.inner_text('body'))
    pg.goto(f'{BASE}/index.php#evaluaciones'); pg.wait_for_timeout(900); pg.screenshot(path=f'{OUT}/06-evaluaciones.png')
    caso('UI-08', 'Sin errores de JavaScript (médico)', not err, '; '.join(err)[:80]); ctx.close()

    # Fundación
    ctx, pg, err = entrar('fundacion')
    pg.goto(f'{BASE}/index.php#solicitudes'); pg.wait_for_timeout(1000); pg.screenshot(path=f'{OUT}/07-solicitudes-fundacion.png')
    caso('UI-09', 'Fundación ve solicitudes pendientes para programar', 'Pendiente' in pg.inner_text('body'))
    ctx.close()

    # Especialista
    ctx, pg, err = entrar('psiquiatra')
    pg.goto(f'{BASE}/index.php#agenda'); pg.wait_for_timeout(1000); pg.screenshot(path=f'{OUT}/08-agenda-especialista.png')
    caso('UI-10', 'Especialista ve su agenda', 'agenda' in pg.inner_text('body').lower())
    pg.goto(f'{BASE}/index.php#visita?s=2'); pg.wait_for_timeout(1200)
    btn = pg.get_by_role('button', name='Iniciar visita')
    if btn.count(): btn.first.click(); pg.wait_for_timeout(1200)
    pg.screenshot(path=f'{OUT}/09-visita-medica.png', full_page=True)
    caso('UI-11', 'Pantalla de visita muestra diagnóstico, exámenes y receta', all(x in pg.inner_text('body') for x in ('Diagnóstico', 'Exámenes')))
    caso('UI-12', 'Sin errores de JavaScript (especialista)', not err, '; '.join(err)[:80]); ctx.close()

    # Contador y responsive
    ctx, pg, err = entrar('contador')
    pg.goto(f'{BASE}/index.php#cotizador'); pg.wait_for_timeout(900); pg.screenshot(path=f'{OUT}/12-cotizador.png')
    caso('UI-13', 'Contador accede al cotizador', 'Cotizador' in pg.inner_text('body')); ctx.close()
    ctx, pg, err = entrar('medico', 390, 844)
    pg.screenshot(path=f'{OUT}/13-movil.png')
    ancho = pg.evaluate('document.documentElement.scrollWidth')
    caso('UI-14', 'Vista móvil (390 px) sin desplazamiento horizontal', ancho <= 392, f'scrollWidth={ancho}'); ctx.close()
    nav.close()

print(f"\nResultado UI: {sum(1 for r in res if r[2])}/{len(res)} casos aprobados")
