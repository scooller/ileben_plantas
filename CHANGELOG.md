# Changelog

All notable changes to this project will be documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

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

