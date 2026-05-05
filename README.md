# ileben_plantas

Plugin de WordPress para gestionar plantas de edificios (departamentos/casas) con almacenamiento local, sincronizacion por API, importacion CSV y visualizacion publica con shortcode tipo carousel showcase.

Version actual: 0.1.8

## Novedades recientes

- **Errores detallados en Sync Contactos**: Al reintentar un contacto fallido, ahora muestra errores especificos por campo (ej: "email: Email is required" en lugar de "validation.required").
- **Modal para editar antes de reintentar**: Botón "Editar y reintentar" abre un formulario donde puedes corregir los datos antes de re-enviar.
- **Error logging completo**: Todos los errores de CF7 se registran en WordPress logs (`debug.log`) con detalles de HTTP code, campo y mensaje.
- Integracion con Contact Form 7 para envio de contactos al endpoint `/api/v1/contact-submissions`.
- Nuevo tag de formulario CF7 para canal: `[ileben_channel "sale"]` (genera hidden `channel`).
- Validaciones locales previas para envios CF7: canal obligatorio, comuna obligatoria y proyecto obligatorio.
- Nuevo registro local de intentos de envio de contactos (estados: enviado, validacion, rate limit, fallido).
- Nueva pantalla admin **Sync Contactos** con filtros, metricas y reintento manual por registro.

- Migracion a estructura nueva de API solamente: sincronizacion valida unicamente respuestas paginadas con `data` y `next_page_url`.
- Nuevo filtro frontend por `tipo_producto` (ademas de tipologia, piso y planta).
- Persistencia de `tipo_producto` en base de datos con indice dedicado para consultas.
- Logica de disponibilidad alineada al payload nuevo: `is_available`, `unidad_sale`, `is_paid`, `completed_reservation` y `completed_payment`.
- Sincronizacion por proyecto robusta: se usa el `proyecto_id` seleccionado y se conserva en todas las paginas de la API (`next_page_url`).
- Confirmacion cuando `proyecto_id` esta vacio: se advierte que se importaran todas las plantas.
- Depuracion por proyecto en sync: cuando hay `proyecto_id`, se eliminan registros locales fuera del proyecto sincronizado.
- Frontend con filtros AJAX paginados: carga inicial y cambios de filtro consultan servidor; el carrusel carga mas items al llegar al final.
- Contador visible: `Total plantas X, mostrando Y plantas` actualizado dinamicamente.
- Imagenes desde API: portada (`cover_image_*`) e interior (`interior_image_*`) con lightbox de imagen interior al hacer click.
- Filtro adicional por piso y orden alfabetico de opciones en los selectores.

## Caracteristicas principales

- **Sincronizacion API**: Conexion con endpoint REST `/api/v1/plantas` con filtrado por proyecto
- **Gestion centralizada**: Tabla personalizada con 19 campos incluyendo datos de superficie, orientacion, tipo de producto y estado
- **Importacion CSV**: Carga masiva con upsert por `external_id` y descarga de CSV de ejemplo
- **Frontend moderno**: Shortcode con carousel, filtros dinamicos (tipologia/tipo_producto/piso/planta), panel de detalles y lightbox
- **Cotizacion flexible**: Boton "Cotizar" por planta con fallback global desde configuracion del plugin
- **Media Library**: Integracion nativa con biblioteca multimedia de WordPress para imagenes y brochures
- **CRON automatico**: Sincronizacion horaria opcional configurable desde el admin del plugin
- **Multi-proyecto**: Soporte para filtrar y sincronizar plantas de proyectos especificos
- **Paginacion incremental**: Al llegar al final del carrusel se cargan los siguientes bloques por AJAX
- **Integracion CF7**: Envio de formularios de Contact Form 7 hacia API de contactos con canal por formulario
- **Observabilidad de contactos**: Historial local de envios API con estados y reintento manual desde admin

## Tecnologias

- **Backend**: PHP 7.2.24+, WordPress 6.0+
- **Frontend**: Bootstrap 5.3.3 (CDN), JavaScript vanilla
- **API**: REST client con timeout configurable (5-120s)
- **Base de datos**: Tabla personalizada con indices optimizados

## Funcionalidades v1

- Tabla personalizada para plantas con campos:
	- external_id (unico)
	- nombre
	- descripcion
	- precio
	- banos
	- dormitorios
	- metros_cuadrados
	- tipologia
  - tipo_producto
	- planta_label
	- orientacion
	- superficie_interior
	- terraza_m2
	- superficie_total
  - foto_portada
  - foto_interior
	- brochure
  - cotizacion_url
	- estado (disponible / no_disponible)
- Panel admin con Bootstrap:
	- listado con filtros
	- crear/editar/eliminar
	- importacion CSV con upsert por external_id y CSV de ejemplo descargable
	- sincronizacion manual con API externa
  - configuracion de API, Bearer Token y cron desde el admin del plugin
	- imagen unica por planta usando la biblioteca multimedia de WordPress
	- brochure opcional por planta (archivo descargable)
  - URL de cotizacion opcional por planta (fallback a URL Cotizar por defecto del plugin)
- Shortcode frontend con Bootstrap:
	- [ileben_plantas]
	- layout tipo ficha de tipologia (como cotizador)
  - filtros por tipologia, tipo de producto, piso y planta
  - carrusel de plantas con panel de datos, contador "mostrando X" y carga incremental por AJAX
  - lightbox de imagen interior al hacer click en imagen principal

## Estructura

- `ileben_api.php`: bootstrap del plugin
- `includes`: core, repositorio y cliente API
- `admin`: pantallas y handlers de administracion
- `public`: shortcode y render frontend
- `assets`: estilos y scripts

## Instalacion

1. Copia la carpeta `ileben_plantas` dentro de `wp-content/plugins/`.
2. Activa el plugin desde WordPress.
3. Al activar:
	 - se crea la tabla personalizada
   - se agrega el permiso `manage_ileben_api` a `administrator` y `editor`

## Uso en Admin

Menu: `Plantas`

- `Listado`: ver y filtrar plantas
- `Nueva Planta`: crear o editar
- `Importar CSV`: cargar archivo CSV
- `Sincronizar API`: importar desde endpoint configurado
- `Sync Contactos`: monitorear y reintentar envios de formularios CF7 hacia API

## Integracion Contact Form 7

El plugin agrega soporte para enviar formularios CF7 al endpoint de contactos de la API.

### Campos obligatorios del formulario CF7

**IMPORTANTE**: Tu formulario CF7 DEBE incluir los siguientes tres campos ocultos (hidden fields):

1. **`[hidden channel "valor"]`** - El canal de envio (ej: "sale", "rent", "info")
2. **`[hidden comuna "valor"]`** - La comuna del proyecto (ej: "Santiago", "Providencia")
3. **`[hidden proyecto "valor"]`** - El nombre o ID del proyecto (ej: "Argomedo", "29")

Sin estos campos, el envio fallara con error de validacion (`validation_error`) y se registrara en la pantalla Sync Contactos.

### Ejemplo de formulario CF7 completo

```
<label> Nombre (requerido)
    [text nombre placeholder "Tu nombre"] </label>

<label> Email (requerido)
    [email email placeholder "Tu email"] </label>

<label> Mensaje
    [textarea mensaje placeholder "Tu mensaje aqui"] </label>

[hidden channel "sale"]
[hidden comuna "Santiago"]
[hidden proyecto "Argomedo"]

[submit "Enviar Consulta"]
```

### Campos de usuario que puedes personalizar

Ademas de los campos obligatorios ocultos, tu formulario puede incluir cualquier campo visible:

- `[text nombre placeholder "Nombre"]` - Nombre del contacto
- `[email email placeholder "Email"]` - Email del contacto
- `[tel telefono placeholder "Telefono"]` - Telefono opcional
- `[textarea mensaje placeholder "Mensaje"]` - Mensaje libre
- Cualquier otro campo personalizado

El plugin capturara todos los campos y los enviara a la API junto con los ocultos.

### Debugging de errores de validacion

Si ves el error `validation_error` en la pantalla **Plantas → Sync Contactos**:

1. Verifica que tu formulario incluya:
   - `[hidden channel "..."]` 
   - `[hidden comuna "..."]`
   - `[hidden proyecto "..."]`

2. Comprueba que el valor no este vacio en los hidden fields.

3. Si los campos estan correctos, revisa la seccion "Response Body" en Sync Contactos para ver el error exacto de la API.

## Configuracion API (Admin del plugin)

La configuracion se realiza desde WordPress en:

`Plantas > Sincronizar API`

Campos disponibles:

### Ajustes de configuracion:

| Variable | Obligatorio | Descripcion | Ejemplo |
|----------|-------------|-------------|---------|
| `Endpoint API` | Sí | URL base de la API (sin /plantas) | `http://127.0.0.1:8000/api/v1` |
| `Proyecto ID` | No | ID del proyecto a filtrar. Si se configura, solo sincroniza plantas de ese proyecto | `29` |
| `Bearer Token` | No | Token de autorizacion. Se envia como `Authorization: Bearer <token>` | `tu-token-secret` |
| `URL Cotizar por defecto` | No | URL global del boton Cotizar cuando la planta no tiene `cotizacion_url` propia | `https://...` |
| `Timeout` | No | Timeout de peticiones HTTP en segundos (min: 5, max: 120) | `30` |
| `Sincronizacion automatica (CRON)` | No | Activa la sincronizacion por CRON | `Activado` |
| `Intervalo CRON (horas)` | No | Define cada cuantas horas corre la sincronizacion automatica (min: 1, max: 24) | `3` |

### Filtrado por proyecto:

Cuando se configura **Proyecto ID**, el plugin construye la URL automaticamente:
- Sin filtro: `http://127.0.0.1:8000/api/v1/plantas` (todas las plantas)
- Con filtro: `http://127.0.0.1:8000/api/v1/plantas?proyecto_id=29` (solo plantas del proyecto 29)

Durante la paginacion, si la API entrega `next_page_url` sin `proyecto_id`, el plugin lo vuelve a inyectar para no perder el filtro del proyecto.

Esto es util cuando trabajas con multiples proyectos y cada instalacion de WordPress gestiona un proyecto especifico.

### Mapeo de campos de API

El plugin mapea automaticamente la respuesta de `/api/v1/plantas` de la siguiente manera:

| Campo Plugin | Campo API | Transformacion | Notas |
|--------------|-----------|----------------|-------|
| external_id | salesforce_product_id | Directo | ID unico de Salesforce, clave para upsert |
| nombre | name | Directo | Numero de planta (ej: "203", "101") |
| descripcion | proyecto.descripcion | Directo | Descripcion del proyecto padre |
| precio | precio_lista / precio_base | Fallback | Prioriza precio_lista, sino usa precio_base |
| dormitorios | programa2 / programa | Extraccion por patron | Busca patron N+D (ej: "2D+2B" → 2) |
| banos | programa2 / programa | Extraccion por patron | Busca patron N+B (ej: "2D+2B" → 2) |
| metros_cuadrados | superficie_total_principal | Directo | Superficie principal total |
| estado | is_available + unidad_sale + is_paid + completed_* | Logica booleana | Disponible solo si no hay senales de venta/reserva/pago completado |
| tipologia | programa | Directo | Texto completo (ej: "3D+2B", "2 dormitorios") |
| tipo_producto | tipo_producto | Directo | Tipo de unidad (ej: DEPARTAMENTO) |
| planta_label | name | Directo | Nombre corto de unidad/planta |
| orientacion | orientacion | Directo | Orientacion de la planta (ej: "SP", "Norte") |
| superficie_interior | superficie_util / superficie_interior | Fallback | Prioriza superficie_util |
| terraza_m2 | superficie_terraza | Directo | Metros cuadrados de terraza |
| superficie_total | superficie_total_principal | Directo | Metros cuadrados totales |
| foto_portada | cover_image_url / imageUrl / proyectoImageUrl | Fallback | Imagen principal |
| foto_interior | interior_image_url / detailImageUrl / salesforce_interior_image_url | Fallback | Imagen interior para lightbox |
| cotizacion_url | configuracion del plugin | Fallback | Se arma desde URL por defecto + external_id |

**Campos opcionales no siempre presentes en API**:
- `brochure` (si no viene desde API puede cargarse manualmente en admin)

**Extraccion de dormitorios y banos:**
- El metodo de extraccion usa patrones por tipo (`D` para dormitorios, `B` para banos).
- Ejemplo: "2D+2B" -> dormitorios=2, banos=2.
## Formato CSV

Cabeceras esperadas:

`external_id,nombre,descripcion,precio_base,precio_lista,banos,dormitorios,metros_cuadrados,tipologia,planta_label,orientacion,superficie_interior,terraza_m2,superficie_total,foto_portada,foto_interior,brochure,cotizacion_url,estado`

Notas:

- `external_id` y `nombre` son obligatorios.
- `foto_portada` y `foto_interior` contienen URLs de imagen por planta.
- `brochure` es opcional y admite URL de archivo (por ejemplo PDF).
- `cotizacion_url` es opcional; si viene vacio se usa la URL Cotizar por defecto del plugin.
- `tipologia`, `planta_label`, `orientacion`, `superficie_interior`, `terraza_m2` y `superficie_total` son opcionales pero recomendados para la vista tipo ficha.
- Si `external_id` ya existe, se actualiza (upsert).

## Shortcode

Basico:

`[ileben_plantas]`

Con atributos:

`[ileben_plantas por_pagina="9" orderby="precio_asc" mostrar_filtros="1"]`

## Bootstrap

El plugin utiliza Bootstrap 5.3.3 (CDN) en:

- pantallas admin del plugin
- paginas frontend donde se detecta el shortcode

La carga de assets es condicional para reducir conflictos con el theme.

## Workflow de uso

### 1. Configuracion inicial

Configura los datos en **Plantas → Sincronizar API**.

### 2. Sincronizacion desde API

1. Ve a **Plantas → Sincronizar API** en el admin de WordPress
2. El plugin:
  - Hace GET a `http://127.0.0.1:8000/api/v1/plantas?proyecto_id=29`
   - Mapea los campos de la API (ver tabla de mapeo arriba)
   - Crea o actualiza plantas por `salesforce_product_id` (external_id)
  - Si hay `proyecto_id`, depura registros locales fuera de ese proyecto
   - Muestra mensaje de exito: "Se han sincronizado X plantas"

### 3. Completar informacion de plantas

Las imagenes de portada/interior pueden venir desde API. El brochure sigue siendo opcional y puede completarse manualmente:

1. Ve a **Plantas → Listado**
2. Haz clic en "Editar" en cada planta
3. Verifica/ajusta **Imagen Portada** y **Imagen Interior** si corresponde
4. Opcionalmente usa **"Seleccionar brochure"** para agregar un PDF descargable
5. Guarda la planta

### 4. Mostrar en frontend

1. Crea una pagina nueva (ej: "Plantas Disponibles")
2. Agrega el shortcode: `[ileben_plantas]`
3. Publica la pagina
4. Los visitantes veran:
  - Carrusel de plantas con imagenes de portada
  - Filtros por tipologia (programa), tipo de producto, piso y planta
   - Panel lateral con detalles: precio, superficies, orientacion
  - Apertura de imagen interior en lightbox al hacer click en la portada
   - Boton para descargar brochure (si existe)
  - Indicador de total y mostradas, con paginacion incremental al navegar carrusel

### 5. Sincronizacion automatica (opcional)

Si activaste la opcion de sincronizacion horaria (CRON) en la configuracion del plugin, el plugin sincroniza automaticamente cada hora:
- Actualiza precios, disponibilidad y otros datos desde la API
- Las imagenes y brochures cargados manualmente se mantienen
- Solo se actualizan los campos que vienen de la API

## Ejemplos de respuesta API

### Estructura esperada de respuesta paginada:

```json
{
  "current_page": 1,
  "data": [
    {
      "id": 167,
      "salesforce_product_id": "01t8c00000NpSjtAAF",
      "name": "203",
      "product_code": "203 DEPARTAMENTO PISO 2  3D+2B MODELO C",
      "orientacion": "SP",
      "programa": "3D+2B",
      "programa2": "3D+2B",
      "precio_lista": "10228.68",
      "superficie_total_principal": "90.64",
      "superficie_interior": "0.00",
      "superficie_terraza": "16.11",
      "is_active": true,
      "active_reservation": null,
      "proyecto": {
        "id": 29,
        "name": "Edificio Capitanes",
        "descripcion": "Moderno edificio en Providencia",
        "comuna": "PROVIDENCIA"
      }
    }
  ],
  "next_page_url": "https://new.ileben.cl/api/v1/plantas?page=2",
  "prev_page_url": null,
  "total": 19,
  "per_page": 12,
  "last_page": 2
}
```

El plugin recorre automaticamente todas las paginas usando `next_page_url` hasta completar la sincronizacion y preserva `proyecto_id` durante toda la paginacion.

### Mapeo aplicado:

- `salesforce_product_id` → `external_id`
- `name` → `nombre` (ej: "203")
- `programa` → `tipologia` (ej: "3D+2B")
- `tipo_producto` → `tipo_producto`
- `programa2 / programa` → extrae `dormitorios` y `banos` por patron `N+D` / `N+B`
- `is_available + unidad_sale + is_paid + completed_*` → `estado` ("disponible" o "no_disponible")
- `proyecto.descripcion` → `descripcion`

## Troubleshooting

### Error: "Debes configurar el endpoint API en la configuracion del plugin"
- Ve a **Plantas → Sincronizar API**
- Completa el campo **Endpoint API** con una URL valida
- Guarda la configuracion e intenta sincronizar nuevamente

### La sincronizacion no trae plantas
- Verifica que la API Laravel este corriendo: `curl http://127.0.0.1:8000/api/v1/plantas`
- Si usas **Proyecto ID**, verifica que el proyecto tiene plantas en la base de datos
- Revisa los logs de WordPress en caso de errores HTTP

### El CRON no sincroniza automaticamente
- Verifica que la opcion **Activar sincronizacion horaria (CRON)** este marcada en **Plantas → Sincronizar API**
- El CRON de WordPress debe estar funcionando (se ejecuta con visitas al sitio o WP-CLI)
- Prueba manualmente desde **Plantas → Sincronizar API** primero

### Las imagenes no se muestran en el shortcode
- Verifica que la API este enviando `cover_image_url` o `imageUrl` para portada.
- Verifica que la API este enviando `interior_image_url`, `detailImageUrl` o `salesforce_interior_image_url` para interior.
- Revisa conectividad HTTPS y acceso publico a las URLs de imagen.

## Licencia

GPL v2 o posterior