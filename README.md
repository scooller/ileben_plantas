# ileben_plantas

Plugin de WordPress para gestionar plantas de edificios (departamentos/casas) con almacenamiento local, sincronizacion por API, importacion CSV y visualizacion publica con shortcode tipo carousel showcase.

## Caracteristicas principales

- **Sincronizacion API**: Conexion con endpoint REST `/api/v1/plants` con filtrado por proyecto
- **Gestion centralizada**: Tabla personalizada con 18 campos incluyendo datos de superficie, orientacion y estado
- **Importacion CSV**: Carga masiva con upsert por `external_id` y descarga de CSV de ejemplo
- **Frontend moderno**: Shortcode con carousel, filtros dinamicos y panel de detalles
- **Cotizacion flexible**: Boton "Cotizar" por planta con fallback global desde `.env`
- **Media Library**: Integracion nativa con biblioteca multimedia de WordPress para imagenes y brochures
- **CRON automatico**: Sincronizacion horaria opcional configurable via `.env`
- **Multi-proyecto**: Soporte para filtrar y sincronizar plantas de proyectos especificos

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
	- planta_label
	- orientacion
	- superficie_interior
	- terraza_m2
	- superficie_total
	- fotos (JSON de URLs)
	- brochure
  - cotizacion_url
	- estado (disponible / no_disponible)
- Panel admin con Bootstrap:
	- listado con filtros
	- crear/editar/eliminar
	- importacion CSV con upsert por external_id y CSV de ejemplo descargable
	- sincronizacion manual con API externa
	- configuracion de API y cron desde archivo .env
	- imagen unica por planta usando la biblioteca multimedia de WordPress
	- brochure opcional por planta (archivo descargable)
  - URL de cotizacion opcional por planta (fallback a COTIZA_URL de `.env`)
- Shortcode frontend con Bootstrap:
	- [ileben_plantas]
	- layout tipo ficha de tipologia (como cotizador)
	- filtros por tipologia y planta
	- carrusel de plantas con panel de datos y boton de brochure

## Estructura

- `ileben_plantas.php`: bootstrap del plugin
- `includes`: core, repositorio y cliente API
- `admin`: pantallas y handlers de administracion
- `public`: shortcode y render frontend
- `assets`: estilos y scripts

## Instalacion

1. Copia la carpeta `ileben_plantas` dentro de `wp-content/plugins/`.
2. Activa el plugin desde WordPress.
3. Al activar:
	 - se crea la tabla personalizada
	 - se agrega el permiso `manage_ileben_plantas` a `administrator` y `editor`

## Uso en Admin

Menu: `Plantas`

- `Listado`: ver y filtrar plantas
- `Nueva Planta`: crear o editar
- `Importar CSV`: cargar archivo CSV
- `Sincronizar API`: importar desde endpoint configurado

## Configuracion API (.env)

El plugin lee toda la configuracion desde el archivo `.env` en la raiz del plugin:

```env
ENDPOINT_API=http://127.0.0.1:8000/api/v1/plants
PROYECTO_ID=29
ENDPOINT_TOKEN=
COTIZA_URL=https://tu-cotizador.com/proyecto-x
TIMEOUT=30
CRON=true
```

### Variables de configuracion:

| Variable | Obligatorio | Descripcion | Ejemplo |
|----------|-------------|-------------|---------|
| `ENDPOINT_API` | Sí | URL completa del endpoint de la API | `http://127.0.0.1:8000/api/v1/plants` |
| `PROYECTO_ID` | No | ID del proyecto a filtrar. Si se configura, solo sincroniza plantas de ese proyecto | `29` |
| `ENDPOINT_TOKEN` | No | Token de autorizacion Bearer (API publica no lo requiere) | `tu-token-secret` |
| `COTIZA_URL` | No | URL global del boton Cotizar cuando la planta no tiene `cotizacion_url` propia | `https://...` |
| `TIMEOUT` | No | Timeout de peticiones HTTP en segundos (min: 5, max: 120) | `30` |
| `CRON` | No | Sincronizacion automatica horaria (`true`/`1`/`yes`/`on` para activar) | `true` |

### Filtrado por proyecto:

Cuando se configura `PROYECTO_ID`, el plugin construye la URL automaticamente:
- Sin filtro: `http://127.0.0.1:8000/api/v1/plants` (todas las plantas)
- Con filtro: `http://127.0.0.1:8000/api/v1/plants?proyecto_id=29` (solo plantas del proyecto 29)

Esto es util cuando trabajas con multiples proyectos y cada instalacion de WordPress gestiona un proyecto especifico.

### Mapeo de campos de API

El plugin mapea automaticamente la respuesta de `/api/v1/plants` de la siguiente manera:

| Campo Plugin | Campo API | Transformacion | Notas |
|--------------|-----------|----------------|-------|
| external_id | salesforce_product_id | Directo | ID unico de Salesforce, clave para upsert |
| nombre | name | Directo | Numero de planta (ej: "203", "101") |
| descripcion | proyecto.descripcion | Directo | Descripcion del proyecto padre |
| precio | precio_lista / precio_base | Fallback | Prioriza precio_lista, sino usa precio_base |
| dormitorios | programa | Extraccion numerica | "3D+2B" → 3, "2 dormitorios" → 2 |
| banos | programa2 | Extraccion numerica | "3D+2B" → 3, "2 baños" → 2 |
| metros_cuadrados | superficie_vendible / superficie_total_principal | Fallback | Superficie comercial principal |
| estado | is_active + active_reservation | Logica booleana | "disponible" si is_active=true y active_reservation=null |
| tipologia | programa | Directo | Texto completo (ej: "3D+2B", "2 dormitorios") |
| planta_label | product_code / name | Fallback | Codigo de producto o nombre |
| orientacion | orientacion | Directo | Orientacion de la planta (ej: "SP", "Norte") |
| superficie_interior | superficie_interior | Directo | Metros cuadrados interiores |
| terraza_m2 | superficie_terraza | Directo | Metros cuadrados de terraza |
| superficie_total | superficie_total_principal | Directo | Metros cuadrados totales |
| cotizacion_url | cotizacion_url / cotiza_url | Fallback | Usa URL por planta; si viene vacia, frontend usa `COTIZA_URL` de `.env` |

**Campos NO mapeados desde API** (carga manual requerida):
- **fotos** (imagen_url): Se debe cargar via biblioteca multimedia de WordPress
- **brochure** (brochure_url): Archivo opcional, se carga via biblioteca multimedia
- **fecha_disponibilidad**: Campo disponible en DB pero no se mapea actualmente

**Extraccion numerica de programa/programa2:**
- El metodo `extract_number_from_programa()` extrae el primer numero encontrado en el texto
- Ejemplos: "3D+2B" → 3, "2 dormitorios" → 2, "ST" o "studio" → 0
## Formato CSV

Cabeceras esperadas:

`external_id,nombre,descripcion,precio,banos,dormitorios,metros_cuadrados,tipologia,planta_label,orientacion,superficie_interior,terraza_m2,superficie_total,foto,brochure,cotizacion_url,estado`

Notas:

- `external_id` y `nombre` son obligatorios.
- `foto` contiene solo una URL de imagen por planta.
- `brochure` es opcional y admite URL de archivo (por ejemplo PDF).
- `cotizacion_url` es opcional; si viene vacio se usa `COTIZA_URL` desde `.env`.
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

```env
# .env
ENDPOINT_API=http://127.0.0.1:8000/api/v1/plants
PROYECTO_ID=29
TIMEOUT=30
CRON=true
```

### 2. Sincronizacion desde API

1. Ve a **Plantas → Sincronizar API** en el admin de WordPress
2. El plugin:
   - Hace GET a `http://127.0.0.1:8000/api/v1/plants?proyecto_id=29`
   - Mapea los campos de la API (ver tabla de mapeo arriba)
   - Crea o actualiza plantas por `salesforce_product_id` (external_id)
   - Muestra mensaje de exito: "Se han sincronizado X plantas"

### 3. Completar informacion de plantas

Las imagenes y brochures NO vienen de la API, debemos agregarlas manualmente:

1. Ve a **Plantas → Listado**
2. Haz clic en "Editar" en cada planta
3. Usa el boton **"Seleccionar imagen"** para cargar una foto (biblioteca multimedia de WordPress)
4. Opcionalmente usa **"Seleccionar brochure"** para agregar un PDF descargable
5. Guarda la planta

### 4. Mostrar en frontend

1. Crea una pagina nueva (ej: "Plantas Disponibles")
2. Agrega el shortcode: `[ileben_plantas]`
3. Publica la pagina
4. Los visitantes veran:
   - Carrusel de plantas con imagenes
   - Filtros por tipologia (programa) y planta (product_code)
   - Panel lateral con detalles: precio, superficies, orientacion
   - Boton para descargar brochure (si existe)

### 5. Sincronizacion automatica (opcional)

Si configuraste `CRON=true`, el plugin sincroniza automaticamente cada hora:
- Actualiza precios, disponibilidad y otros datos desde la API
- Las imagenes y brochures cargados manualmente se mantienen
- Solo se actualizan los campos que vienen de la API

## Ejemplos de respuesta API

### Estructura esperada de `/api/v1/plants`:

```json
{
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
  "total": 19,
  "per_page": 12,
  "current_page": 1
}
```

### Mapeo aplicado:

- `salesforce_product_id` → `external_id`
- `name` → `nombre` (ej: "203")
- `programa` → `tipologia` (ej: "3D+2B") + extrae `dormitorios` (3)
- `programa2` → extrae `banos` (2)
- `is_active + active_reservation` → `estado` ("disponible" o "no_disponible")
- `proyecto.descripcion` → `descripcion`

## Troubleshooting

### Error: "Debes configurar ENDPOINT_API en el archivo .env"
- Verifica que existe el archivo `.env` en la raiz del plugin
- Asegurate de que la variable `ENDPOINT_API` tiene una URL valida

### La sincronizacion no trae plantas
- Verifica que la API Laravel este corriendo: `curl http://127.0.0.1:8000/api/v1/plants`
- Si usas `PROYECTO_ID`, verifica que el proyecto tiene plantas en la base de datos
- Revisa los logs de WordPress en caso de errores HTTP

### El CRON no sincroniza automaticamente
- Verifica que `CRON=true` en el `.env`
- El CRON de WordPress debe estar funcionando (se ejecuta con visitas al sitio o WP-CLI)
- Prueba manualmente desde **Plantas → Sincronizar API** primero

### Las imagenes no se muestran en el shortcode
- Las imagenes NO vienen de la API, debes cargarlas manualmente
- Ve a **Plantas → Editar** y usa el boton "Seleccionar imagen"
- Verifica que la URL de la imagen sea accesible

## Licencia

GPL v2 o posterior