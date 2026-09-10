# Servisello Cache Warmer — Fase F1

Versión: `1.0.0-f1`

## Alcance de esta fase

Incluye:

- Fichero principal con cabecera, constantes y carga de dependencias.
- Activación: creación de las 3 tablas con `dbDelta`, creación de opciones y programación del watchdog.
- Desactivación: eliminación de todos los eventos de cron, liberación de leases y estado a reposo.
- Esquema: `{prefix}scw_queue`, `{prefix}scw_runs`, `{prefix}scw_events` con prefijo dinámico (`$wpdb->prefix`) y versión de esquema.
- Ajustes (`scw_settings`) y estado de ejecución (`scw_runtime_state`), ambos con `autoload = no`.
- Registro de eventos con códigos normalizados y retención configurable.
- Scheduler cableado pero inerte: `scw_tick` registrado y nunca programado, `scw_watchdog` recurrente cada 5 minutos que sólo escribe un latido y limpia eventos antiguos.
- Página de administración de diagnóstico con capability `manage_options`.
- Comprobación de entorno bajo demanda (loopback a `wp-cron.php`), protegida con nonce.
- `uninstall.php` que sólo destruye datos si el ajuste `delete_data_on_uninstall` está activo (por defecto, no).

No incluye, a propósito: parser de sitemaps, normalización, cola, worker, peticiones HTTP de calentamiento, validación YITH, pacer ni circuit breaker. **El plugin no realiza ninguna petición al front del sitio en esta fase.**

## Instalación

1. Subir la carpeta `servisello-cache-warmer/` a `wp-content/plugins/`, o instalar el ZIP desde Plugins → Añadir nuevo → Subir plugin.
2. Activar.
3. Ir al menú lateral «Cache Warmer».

## Pruebas de aceptación de F1

1. **Activación.** Tras activar, la página de diagnóstico debe mostrar las 3 tablas con «Existe: Sí» y `scw_events` con 1 fila (`PLUGIN_ACTIVATED`).
2. **Opciones.** En phpMyAdmin, `SELECT option_name, autoload FROM wp_options WHERE option_name LIKE 'scw_%';` debe devolver `scw_settings`, `scw_runtime_state` y `scw_db_version`, y las dos primeras con `autoload = no`.
3. **Sin ticks.** «Próximo tick» debe decir «No programado». Si aparece programado, es un fallo de esta fase.
4. **Watchdog.** «Próximo watchdog» debe mostrar una fecha. Al cabo de 5–10 minutos con tráfico en el sitio, «Último watchdog» debe avanzar solo. Eso demuestra que WP-Cron nativo se dispara sin necesidad de cron de sistema.
5. **Entorno.** Pulsar «Comprobar entorno». Debe aparecer el aviso verde y un evento `LOOPBACK_OK` en la tabla de eventos. Si sale rojo, hay que resolverlo antes de F3.
6. **Permisos.** Con un usuario editor, `/wp-admin/admin.php?page=servisello-cache-warmer` debe devolver 403, y el menú no debe aparecer.
7. **Nonce.** Enviar el formulario de comprobación sin nonce válido debe morir con «Enlace caducado».
8. **Desactivación.** Al desactivar, `SELECT * FROM wp_options WHERE option_name = 'cron'` no debe contener `scw_tick` ni `scw_watchdog`, y debe registrarse `PLUGIN_DEACTIVATED`.
9. **Reactivación.** Volver a activar no debe duplicar tablas ni perder datos.
10. **Desinstalación segura.** Con `delete_data_on_uninstall` en `false` (por defecto), borrar el plugin debe conservar las tablas.

## Notas para F2

- `SCW_Settings::defaults()` ya contiene sitemaps, exclusiones, parámetros ignorados, prioridades, presets YITH y perfiles de página. F2 los consumirá sin tocar el modelo de datos.
- La tabla `scw_queue` ya tiene `url_hash` único, `available_at`, lease y el índice `claim (status, available_at, priority)` que usará el `UPDATE ... LIMIT 1` de reclamación atómica.
