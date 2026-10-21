# Asilo Cabeza de Algodón – Sistema de administración

Proyecto final de Análisis de Sistemas II. Universidad Mariano Gálvez, sede Mazatenango.

## Cómo ponerlo en marcha (XAMPP en Windows)

1. Abra el **Panel de control de XAMPP** y encienda **Apache** y **MySQL**.
2. Entre a <http://localhost/phpmyadmin>, abra la pestaña **Importar**, elija `database/schema.sql` y presione **Importar**.
   Esto crea la base `asilo_cabeza_algodon` con datos de ejemplo. Si la base ya existía, **la borra y la vuelve a crear**.
3. (Opcional, recomendado) Encienda los microservicios con Docker Desktop. En la carpeta del proyecto ejecute:
   ```
   docker compose up -d --build
   ```
   Si Docker no está encendido el sistema **sigue funcionando**: el IMC y la cotización se calculan en el servidor PHP con las mismas fórmulas, y la pantalla de inicio indica que el microservicio no responde.
4. (Para los correos al familiar) En la carpeta `config`, copie `correo.ejemplo.php` con el nombre `correo.php`.
   Escriba su cuenta de Gmail y la contraseña de aplicación, y cambie `CORREO_ACTIVO` a `true`.
   Verifíquelo en **Configuración > Enviar prueba**. Este archivo no se sube a GitHub (.gitignore). Si su red bloquea SMTP, use el método `apps_script` (vea `docs/apps-script/EnviarCorreo.gs`).
5. Abra <http://localhost/asilo-cabeza-algodon/public/>. Lo enviará a la pantalla de ingreso.

### Cuentas de prueba (contraseña: `Asilo2026!`)

| Correo | Rol |
|---|---|
| admin@asilo.com | Administrador |
| medico@asilo.com | Médico general |
| enfermera@asilo.com / enfermero@asilo.com | Enfermero(a) |
| fundacion@asilo.com | Fundación |
| internista@ / cardiologo@ / psiquiatra@ / geriatra@ / traumatologo@asilo.com | Médico especialista |
| laboratorio@asilo.com | Laboratorio |
| farmacia@asilo.com | Farmacia |
| contador@asilo.com | Contador (caja) |

Cambie estas contraseñas desde **Usuarios** antes de usar el sistema con datos reales.

## Estructura

```
config/           config.php (parámetros y URLs de microservicios), database.php (Singleton PDO)
database/         schema.sql (24 tablas + datos iniciales)
docker/           ms-imc (puerto 5001) y ms-cotizador (puerto 5002), Python/Flask
public/           index.php (panel), login.php, api.php (Front Controller), assets/
src/Core/         Auth (sesión y roles), Bitacora (auditoría), Http (validación y JSON)
src/Models/       Acceso a datos con sentencias preparadas (DAO)
src/Controllers/  Reglas de negocio de cada módulo
src/Services/     MicroservicioClient (Gateway cURL), ReglasClinicas (respaldo local), Correo (PHPMailer)
src/Lib/          PHPMailer 6.9.3 (licencia LGPL)
tests/            Pruebas automatizadas de la API y de la interfaz (evidencia para SQA)
```

## Publicación en Azure (acceso público)

El sistema completo corre en una máquina virtual Ubuntu de Azure con Docker (`docker-compose.prod.yml`):

| Contenedor | Qué es | Expuesto a Internet |
|---|---|---|
| `asilo_web` | PHP 8.2 + Apache con la aplicación (`docker/web/Dockerfile`) | Sí, puerto 80 |
| `asilo_db` | MariaDB 10.11; crea las tablas con `database/schema.sql` la primera vez | No |
| `asilo_ms_imc`, `asilo_ms_cotizador` | Microservicios Flask | No (solo red interna) |

1. En el portal de Azure cree una máquina virtual **Ubuntu Server 24.04 LTS**, tamaño **B1s** o mayor, y abra el puerto **HTTP (80)**.
2. En la máquina: **Operaciones > Ejecutar comando > RunShellScript** y ejecute:
   ```
   curl -fsSL https://raw.githubusercontent.com/Fredy-Vasquez/asilo-cabeza-algodon/main/deploy/azure/instalar.sh | bash
   ```
   Instala Docker, descarga el código de GitHub, genera contraseñas aleatorias en `.env` (solo en el servidor) y levanta los contenedores.
3. Abra `http://IP-PUBLICA/` en el navegador.

Para actualizar después de un `git push`, ejecute el mismo comando. Otros scripts en `deploy/azure/`:
`correo.sh` (configura el correo al familiar) y `reiniciar-datos.sh` (vuelve a los datos de ejemplo).

## Pruebas automatizadas

Con Apache y MySQL encendidos y la base recién importada:

```
C:\xampp\php\php.exe tests\ejecutar_pruebas.php http://localhost/asilo-cabeza-algodon/public
```

Opcionales, con Python 3 (`pip install requests playwright` y `playwright install chromium`):

```
python tests/prueba_api.py http://localhost/asilo-cabeza-algodon/public
python tests/prueba_ui.py  http://localhost/asilo-cabeza-algodon/public
```

Las pruebas crean registros; al terminar vuelva a importar `database/schema.sql`.

## Avance

Enunciado: **Proyecto Final 2026, Asilo de Ancianos Cabeza de Algodón**.

- [x] Base: inicio de sesión, ocho roles, bitácora, residentes, familiares, habitaciones, evaluación con IMC, cotizador.
- [x] Paso A: nuevo nombre, modelo de datos completo (24 tablas) y ficha médica (padecimientos, psicopatologías, medicamentos permanentes).
- [x] Paso B: solicitudes del médico general, módulo de la fundación, agenda y visita del especialista, acompañamientos del enfermero, correo al familiar (Gmail) y configuración.
- [x] Paso C: laboratorio (resultados) y farmacia (entregas e inventario).
- [x] Paso D: caja (cuentas de familiares, cuotas, pagos, fundación, donaciones, gastos).
- [x] Pruebas automatizadas: 92 casos en PHP, 41 en Python y 14 de interfaz con Playwright, todos aprobados (20 oct 2026).
- [x] Despliegue en Azure con Docker.
- [x] Paso E: siete reportes (pantalla, impresión/PDF y exportación CSV).
- [ ] Paso F: manual de usuario, plan en MS Project.
