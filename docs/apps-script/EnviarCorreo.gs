/**
 * Asilo Cabeza de Algodón — Envío de correos por Google Apps Script
 *
 * Usa MailApp (solo permiso de ENVIAR correos, principio de mínimo privilegio).
 * Sirve cuando la red bloquea los puertos de correo (587/465): el sistema PHP
 * envía los datos por HTTPS (puerto 443) a este script y Google envía el correo
 * desde la cuenta de Gmail dueña del script.
 *
 * Instalación (una sola vez):
 *  1. Entre a https://script.google.com con la cuenta de Gmail que enviará los correos
 *     y cree un "Proyecto nuevo". Nómbrelo "Asilo - Correos".
 *  2. Borre el contenido de Código.gs y pegue este archivo completo.
 *  3. Cambie CLAVE por una frase secreta larga (la misma irá en config/correo.php).
 *  4. Guarde. Ejecute una vez la función "probar" (menú superior) y acepte los permisos
 *     que pide Google ("Revisar permisos" > su cuenta > Configuración avanzada > Ir a… > Permitir).
 *  5. Implementar > Nueva implementación > tipo "Aplicación web":
 *       Ejecutar como: Yo   |   Quién tiene acceso: Cualquier usuario
 *     Presione Implementar y copie la "URL de la aplicación web" (termina en /exec).
 */

const CLAVE = 'cambie-esta-clave-por-una-frase-larga';

function doPost(e) {
  try {
    const d = JSON.parse(e.postData.contents);
    if (d.clave !== CLAVE) return responder({ ok: false, error: 'Clave incorrecta' });
    if (!d.para || !d.asunto) return responder({ ok: false, error: 'Faltan datos del correo' });
    MailApp.sendEmail({ to: d.para, subject: d.asunto, body: d.texto || '', htmlBody: d.html, name: d.remitente || 'Asilo Cabeza de Algodón' });
    return responder({ ok: true, restantes: MailApp.getRemainingDailyQuota() });
  } catch (err) {
    return responder({ ok: false, error: String(err) });
  }
}

function responder(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj)).setMimeType(ContentService.MimeType.JSON);
}

/** Ejecútela una vez desde el editor para autorizar el envío de correos */
function probar() {
  MailApp.sendEmail(Session.getEffectiveUser().getEmail(), 'Prueba Apps Script', 'El script del asilo puede enviar correos.');
}
