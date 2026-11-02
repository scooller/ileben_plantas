# Changelog

All notable changes to this project will be documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [0.2.5] - 2026-11-02

### Fixed
- Priorización directa de `salesforce_interior_image_url` en `Ileben_Api_Client::map_api_item` para asegurar la captura de la lámina interior de la planta.
- Prevención de contaminación de imagen interior: se excluye la URL de portada/fachada (`$cover_image`) cuando `detailImageUrl` hace fallback a la portada en la API externa.
- Eliminación de fallback de `cover_image_media` en la extracción de imagen interior para evitar duplicación de imágenes.
- Soporte en `Ileben_Api_Repository::sanitize_data` para resolver `foto_interior` y `foto_portada` desde campos de API (`salesforce_interior_image_url`, `interior_image_url`, `cover_image_url`).
- Fallback en shortcode `map_showcase_item`: si una planta no posee foto de portada pero sí interior, prioriza la imagen interior antes del logotipo genérico.

## [0.2.4] - 2026-09-22

### Added
- Selección múltiple de contactos en la tabla de **Sync Contactos** (checkboxes por fila y selector maestro en cabecera).
- Barra de herramientas de acciones masivas con contador dinámico de seleccionados y opción de seleccionar todos los registros coincidentes con los filtros activos.
- Modal de edición masiva de canal de contacto con opción de resincronización inmediata.
- Modal de progreso de resincronización por lotes vía AJAX con barra de progreso en tiempo real, conteo de éxitos/errores y consola de registro de actividad.
- Campo de edición de canal en el modal de edición individual de contactos.
- Métodos `bulk_update_contact_channel` y `get_contact_ids_by_filters` en `Ileben_Api_Repository`.
- Endpoints AJAX `wp_ajax_ileben_api_bulk_update_contact_channel`, `wp_ajax_ileben_api_batch_resync_contacts` y `wp_ajax_ileben_api_get_filtered_contact_ids`.

### Changed
- Actualización de versión del plugin a 0.2.4 en cabecera de plugin y constante `ILEBEN_API_VERSION`.
- Centralización del procesamiento de reintentos de contactos en `process_contact_retry` en `Ileben_Api_Admin`, asegurando la actualización persistente de `channel` y `payload_json`.
- Estilos de administración en `admin.css` para barra de acciones masivas y visualización de canales.

