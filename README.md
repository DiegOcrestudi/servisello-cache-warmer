# Servisello Cache Warmer

Custom WordPress cache warmer for Servisello.es.

## Status

Development — F4.4 implementada y validada (versión `1.6.0-f4`).

## Qué hace hoy

Recorre las URLs encoladas desde los sitemaps, pide **una URL por tick** y **una
petición por URL**, y valida la respuesta antes de darla por buena.

La validación combina tres piezas independientes:

- **Integridad del HTML** (`SCW_HTML_Validator`): cuerpo vacío, documento
  demasiado pequeño, Content-Type incorrecto, truncamiento confirmado o falta de
  `</html>`. Es una comprobación ligera, no un validador W3C: distingue entre
  anomalías fuertes, que sí descalifican la respuesta, y señales meramente
  informativas, que no.
- **Perfil de página** (`SCW_Page_Profile`): resuelve por coincidencia exacta de
  path si una URL es `REQUIRES_YITH`, `NO_YITH` o `UNVERIFIED`. Las rutas son
  configuración, no código. `UNVERIFIED` no es un error: significa que no hay
  expectativa declarada.
- **Evidencia YITH** (`SCW_YITH_Validator`): busca el contenedor
  `yith-wcan-filters` y el preset asociado (`id="preset_NNNN"` y/o
  `data-preset-id`). Un identificador de preset suelto en JavaScript, en un
  comentario, en una URL o en JSON **no** cuenta como evidencia.

`SCW_Content_Validator` combina las tres y emite el veredicto. El worker se
limita a aplicarlo.

## Estados

| Estado de cola | Significado |
|---|---|
| `success` | La respuesta es correcta y cumple la expectativa de la URL. |
| `suspicious` | La petición funcionó, pero el contenido no es el esperado. |
| `failed` | La petición falló: no-2xx, timeout o error de transporte. |
| `skipped` | La URL no llegó a pedirse (inválida o excluida). No genera fila en `scw_runs`. |

Una URL `REQUIRES_YITH` que devuelve HTTP 200 sin el filtro esperado queda en
`suspicious`. Ese es el caso que motiva el proyecto.

## Evidencia

Cada petición real deja **una** fila en `scw_runs` con:

- `validation_result`: `ok`, `suspicious` o `error`.
- `yith_presets`: los presets válidos detectados (p. ej. `6432,6683`). Los
  presets desconocidos no van aquí: van a `diagnostics`.
- `diagnostics` (JSON): motivo del veredicto, anomalías y señales, perfil
  aplicado y evidencia YITH (detectado, válido, consistente, presets válidos,
  desconocidos y huérfanos, número de contenedores y avisos).

**El cuerpo de la respuesta no se persiste nunca.** Se consume en memoria
durante el ciclo y se descarta; en `diagnostics` sólo se guarda, y sólo cuando
el veredicto no es correcto, un fragmento acotado del contenedor del filtro.

Los errores de petición (404, timeout…) son resultados de la petición y viven en
`scw_runs` y en el estado de la cola. `scw_events` sigue reservado a fallos del
sistema: una respuesta dudosa nunca genera un evento.

## Lo que NO determina el veredicto

- **`X-LiteSpeed-Cache`**: se registra siempre, no decide nunca. Un `hit` no
  certifica que la página sea correcta; precisamente ese es el fallo que este
  plugin existe para detectar.
- **Elementor**: ninguna regla depende de ningún selector ni identificador de
  Elementor.
- El User-Agent, las cookies y el momento de la petición tampoco intervienen.

## Base de datos

**F4 no ha cambiado el esquema.** `SCW_Schema::DB_VERSION` sigue en `2` y no hay
ninguna migración: las columnas `validation_result`, `yith_presets` y
`diagnostics` existen desde F1 y ahora simplemente se rellenan.

## Fases

| Fase | Contenido | Estado |
|---|---|---|
| F1 | Esquema, ajustes, estado, eventos, scheduler inerte, admin | Cerrada |
| F2.1–F2.4 | Cola persistente, normalización, exclusiones, sitemaps | Cerrada |
| F3 | Cliente HTTP, worker, scheduler real, leases, `scw_runs` | Cerrada |
| F4.1 | Integridad del HTML | Cerrada |
| F4.2 | Perfil de página | Cerrada |
| F4.3 | Evidencia YITH | Cerrada |
| F4.4 | Integración y decisión | Cerrada |
| F5 | Pacing adaptativo, reintentos, circuit breaker, watchdog | Pendiente |
| F6 | Dashboard, estadísticas, exportación, ajustes | Pendiente |

## Tests

Scripts para WP-CLI, sin PHPUnit ni Composer. Desde la raíz de WordPress:

```bash
wp eval-file wp-content/plugins/servisello-cache-warmer/tests/f4-4-worker-integration-test.php
```

Suites disponibles en `tests/`: `f2-1-queue`, `f2-2-url-normalizer`,
`f2-3-url-exclusions`, `f2-4-sitemap`, `f3-http-client`, `f3-worker`,
`f4-1-html-validator`, `f4-2-page-profile`, `f4-3-yith-validator`,
`f4-4-content-validator`, `f4-4-worker-integration`.

Ejecutarlos con `php` a secas no hace nada: todos llevan el guard `ABSPATH`.
