# Memoria de Agente: Contact Submit y Canales

## Objetivo
Este documento explica el nuevo contexto del flujo de contactos para integraciones externas (PHP/WordPress) que ya tienen resuelta la conexion API/token.

El foco es como enviar correctamente `POST /api/v1/contact-submissions`, como se resuelve el canal, que validaciones aplica el backend y que efectos secundarios ocurren (correo + Salesforce).

## Endpoint
- Metodo: `POST`
- URL: `/api/v1/contact-submissions`
- Auth: publica (no requiere `auth:sanctum`)
- Rate limit: `throttle:10,1` (10 requests por minuto por IP)
- Content-Type recomendado: `application/json`

Respuesta exitosa:
- HTTP `201`
- Body:

```json
{
  "message": "Tu mensaje fue enviado correctamente.",
  "id": 123
}
```

## Contrato de request
Payload base:

```json
{
  "channel": "sale",
  "turnstile_token": "opcional-segun-config",
  "fields": {
    "name": "Nombre Apellido",
    "email": "persona@correo.cl",
    "phone": "+56911111111",
    "comuna": "Santiago",
    "proyecto": "Argomedo",
    "message": "Quiero mas informacion",
    "utm_source": "google",
    "utm_medium": "cpc",
    "utm_campaign": "invierno",
    "utm_term": "depto",
    "utm_content": "anuncio-a"
  }
}
```

### Campos top-level
- `fields` (requerido): objeto con los campos del formulario.
- `channel` (OBLIGATORIO): slug de canal activo. Sin canal válido se retorna `422`.
- `turnstile_token` (condicional):
  - requerido si existe `services.turnstile.secret_key`.
  - nullable si Turnstile esta deshabilitado.

### Campos en `fields`
Se validan segun configuracion dinamica del formulario (canal o fallback global), mas campos suplementarios permitidos.

Campos de marketing permitidos:
- `utm_source`
- `utm_medium`
- `utm_campaign`
- `utm_term`
- `utm_content`
- `utm_site`

Campos suplementarios relevantes (entre otros):
- telefonia: `phone`, `telefono`, `fono`, `celular`, `whatsapp`
- comuna: `comuna`, `commune`, `district`, `project_commune`
- proyecto: `proyecto`, `project`, `project_name`, `nombre_proyecto`
- mensaje: `message`, `mensaje`

## Regla funcional critica
Ademas de la validacion de campos configurados, el backend exige SIEMPRE que exista valor para:
- grupo comuna (al menos uno): `comuna|commune|district|project_commune`
- grupo proyecto (al menos uno): `proyecto|project|project_name|nombre_proyecto`

Si faltan, responde `422` con:
- `fields.comuna`: "El campo Comuna es obligatorio."
- `fields.proyecto`: "El campo Proyecto es obligatorio."

## Resolucion de canal (orden exacto)
El backend determina el canal con este orden:
1. `channel` en body (slug de canal activo).
2. Header `X-Contact-Channel` (fallback para clientes API que prefieren headers).
3. Si ninguno resuelve → `422` con error en `channel`.

Notas:
- Solo canales activos (`is_active = true`) son elegibles.
- Si `channel` body existe pero el slug no existe o está inactivo → `422` "El canal de contacto seleccionado no es válido o está inactivo."
- Si falta en body y en header → `422` "El campo Canal de contacto es obligatorio."
- **ELIMINADO**: detección automática por dominio (`utm_site`, `Origin`, `Referer`) y canal por defecto fallback.

## Efectos al crear el contacto
Al guardar `contact_submissions`:
- Se persisten `name`, `email`, `phone`, `rut` inferidos desde aliases dentro de `fields`.
- Se persiste `contact_channel_id` resuelto.
- Se guarda `recipient_email` efectivo para notificacion.
- Se guarda `ip_address`, `user_agent`, `submitted_at`.
- Se guarda `fields` completo (JSON).

### Enriquecimiento automatico de `utm_site`
Si `fields.utm_site` viene vacio, backend lo intenta completar con:
1. host de `Origin`
2. host de `Referer`
3. valor de `X-Source-Site`
4. host del request

Nota: este enriquecimiento solo afecta el campo `utm_site` guardado en `fields`. Ya no influye en la resolución del canal.

## Notificacion por correo
Si existe `recipient_email`, se envia correo administrativo de contacto.

`recipient_email` se resuelve asi:
1. `contact_channels.notification_email` del canal resuelto
2. fallback `site_settings.contact_notification_email`
3. fallback `site_settings.contact_email`

## Salesforce
Despues de guardar:
- Si `services.salesforce.lead_enabled = true` (o fallback `case_enabled`), ejecuta sincronizacion inmediata via `CreateSalesforceCaseJob::dispatchSync(..., 'automatic')`.
- Si esta deshabilitado, solo loguea warning y no sincroniza.

Metadatos de sync (en `contact_submissions`):
- `salesforce_case_id`
- `salesforce_case_error`
- `salesforce_synced_at`
- `salesforce_sync_trigger`

## Canales: estructura y origen
Tabla `contact_channels` (relevante):
- `slug` (unico)
- `name`
- `is_active`
- `is_default`
- `notification_email`
- `form_fields` (override de formulario por canal)
- `domain_patterns` (array para auto-resolucion por dominio)

Seed inicial historico:
- `default` (default activo)
- `sale`
- `argomedo`
- `capitanes`

Adicionalmente, hay sincronizacion de canales desde proyectos (`Proyecto.slug`), que:
- crea/actualiza canales por slug de proyecto
- marca `is_default = false` para esos canales
- agrega dominio de `Proyecto.pagina_web` a `domain_patterns` cuando aplica

## Recomendaciones para cliente PHP/WordPress
1. Enviar siempre `fields` con minimo:
   - identificacion: `name`, `email`
   - negocio: uno de comuna + uno de proyecto
   - mensaje: `message` o `mensaje`
2. Enviar **siempre** `channel` con el slug del canal correspondiente. Es obligatorio.
3. Alternativamente, enviar el canal via header `X-Contact-Channel: <slug>`.
4. Manejar `422` leyendo `errors` por clave (`channel`, `fields.*`, `turnstile_token`).
5. Respetar rate limit (retry con backoff).

## Ejemplo minimo valido

```json
{
  "channel": "sale",
  "fields": {
    "name": "Juan Perez",
    "email": "juan@correo.cl",
    "comuna": "Santiago",
    "proyecto": "Argomedo",
    "message": "Necesito cotizacion"
  }
}
```

## Ejemplo con canal y marketing

```json
{
  "channel": "sale",
  "fields": {
    "name": "Ana Rojas",
    "email": "ana@correo.cl",
    "telefono": "+56999999999",
    "comuna": "Providencia",
    "project_name": "Capitanes",
    "mensaje": "Me interesa una unidad 2D2B",
    "utm_source": "meta",
    "utm_medium": "paid-social",
    "utm_campaign": "lanzamiento-q2",
    "utm_site": "sale.ileben.cl"
  }
}
```

## Referencia rapida de codigo fuente
- Request/validacion: `app/Http/Requests/StoreContactSubmissionRequest.php`
- Controller/store: `app/Http/Controllers/Api/ContactSubmissionController.php`
- Modelo canal: `app/Models/ContactChannel.php`
- Modelo envio: `app/Models/ContactSubmission.php`
- Ruta API: `routes/api.php`
- Correo admin: `app/Services/FinMail/FinMailNotificationService.php`
- Migraciones canales/contactos: `database/migrations/2026_04_21_191516_create_contact_channels_table.php`, `database/migrations/2026_04_09_163802_create_contact_submissions_table.php`, `database/migrations/2026_04_21_191517_add_contact_channel_id_to_contact_submissions.php`
- Formulario React: `frontend/src/pages/Contact.jsx` — lee `channel` de `?channel=<slug>` en la URL
- Servicio HTTP React: `frontend/src/services/contactSubmissions.js` — `create(fields, turnstileToken, channel)`
- Tests API: `tests/Feature/ContactSubmissionApiTest.php`
