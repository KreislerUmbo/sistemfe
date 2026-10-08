# Créditos — Fase 4: Frontend (admin-start-kit)

Estado: **construida y en producción** (main `1974b7c`, 05-oct-2026).

Leer junto con `00-reglas-y-modelo.md`. API: `routes/creditos.php` (Fase 3). Rama sugerida: `feat/creditos-fase4-frontend`.
**Antes de codificar**: verificaciones (abajo) + lista de vistas, componentes, stores y composables; esperar aprobación.

## Base técnica (existente, sin librerías nuevas)
Vue 3 + TS, Pinia, Bootstrap 5 (tema Rizz), SweetAlert2, flatpickr, httpClient, rutas con `meta.permission`, Vitest. Modelo de estructura: `views/commercial-quotes/` (listado + creación + detalle).

## Mockups aprobados
Fuente en `docs/planning/creditos/mockups/` (HTML; abrir en navegador o leer como referencia). **Definen contenido, jerarquía y flujo, no colores ni fuentes**: usar componentes y estilos del tema Rizz. Leer solo el mockup de la pantalla en curso.
- `1-nuevo-credito.html` · `2-detalle-credito.html` · `3-cobrar.html` · `4-cobranza-del-dia.html` · `5-panel-inicio.html` (este último es Fase 4c).
- El cálculo en JS del mockup 1 es solo demostración: **en la app, el resumen y el cronograma vienen de `POST creditos/preview`** (debounce ~300 ms). El frontend nunca calcula montos.

## Pantallas (orden de construcción)
| # | Pantalla | Ruta | Puntos clave |
|---|---|---|---|
| 1 | Nuevo crédito / editar borrador | `/creditos/nuevo`, `/creditos/:id/editar` | Mockup 1. Cliente + tarjeta `resumen-credito` (créditos activos, deuda, disponible, puntualidad, estado). Monto, interés + selector **Sobre el total / Mensual**, forma y n.º de pagos, entrega (hoy), primer pago (auto, editable), "No cobrar domingos". Resumen y cronograma en vivo desde `preview`. "Opciones avanzadas" plegado. Advertencias de límites. Guardar borrador → Activar |
| 2 | Detalle del crédito | `/creditos/:id` | Mockup 2. Saldo, progreso, mora, exigible hoy. Pestañas: Cuotas (estado + mora al día) · Pagos (anular, editar referencia) · Historial. Acciones visibles: **Cobrar, Liquidar, Reprogramar** (+ Documentos deshabilitado hasta 4b); el resto (condonar, corregir, anular, castigar/revertir, renovar) en menú "Más". Todo según permiso y estado |
| 3 | Cobrar | `/creditos/:id/cobrar` (pantalla completa en celular; modal en escritorio) | Mockup 3. Deuda de hoy desglosada, monto con atajos (cuota de hoy / ponerse al día / liquidar), método, **vista previa del reparto** (`pagos/cotizar`), destino del excedente (default devolver), fecha anterior solo con permiso. Botón deshabilitado mientras procesa. Al terminar: opciones de recibo (activas en 4b) |
| 4 | Créditos (listado) | `/creditos` | Búsqueda, estado, "con atraso". Tarjetas en celular, tabla en escritorio |
| 5 | Cobranza del día | `/creditos/cobranza` | Mockup 4. Resumen (por cobrar, cobrado, clientes), filtros Pendientes/Vencidos/Promesas/Cobrados, orden: vencidos → hoy. Tarjeta: nombre, dirección/referencia, monto, estado; botones llamar (`tel:`), mapa, WhatsApp (`wa.me`), Cobrar. El cobrador solo ve su cartera (lo filtra el backend) |
| 6 | Registrar crédito existente | `/creditos/migrar` | Condiciones con fecha pasada; pagos modo rápido o detallado; aviso "no genera movimientos de caja" |
| 7 | Configuración y feriados | `/creditos/configuracion` | Defaults del negocio (sección "Opciones avanzadas" completa) y feriados del año |
| 8 | Ficha de cobro | Componente en detalle de crédito/cliente | Dirección, referencia, mapa, teléfonos, DNI y foto, cobrador asignado (`PUT clientes/{id}/cobrador`) |
Diálogos del detalle: liquidar (cotización del día + confirmar), reprogramar (preview + mora mantener/condonar + cargo), condonar, corregir, anular, castigar/revertir, renovar (preview con entrega neta), autorizar excepción. Motivo obligatorio donde el backend lo pide.

## Reglas de UI (de 00 §4)
- Mobile-first 360 → 768 → 1280 → 1920 (ver "Pantallas grandes"). Botones ≥ 44 px; acción principal abajo en celular. `inputmode="decimal"`.
- Montos: el backend devuelve y recibe decimales en string; mostrar con `Intl.NumberFormat('es-PE', {style:'currency', currency:'PEN'})`. Nunca aritmética de dinero en el frontend.
- Estados con color + texto (pendiente, vencida, pagada, anulada, castigado).
- Previews antes de toda acción que mueve dinero o cambia el cronograma.
- **Idempotencia**: composable `useClaveIdempotencia()` — una clave estable por intento (se genera al abrir la acción, se conserva en reintentos, se renueva solo tras éxito). Si falla la red: conservar formulario, mensaje claro, botón "Reintentar".
- Errores: 422 de límites → lista de infracciones; si es autorizable y el usuario tiene `creditos.autorizar_excepcion`, botón "Autorizar" (pide motivo). 409 → "Esta operación ya fue registrada" / "clave usada con otros datos". Nunca mostrar datos internos.

## Pantallas grandes (laptop y monitor)
Breakpoints de Bootstrap: **< 768** celular (tarjetas, una columna) · **768–1199** tablet (tarjetas en 2 columnas o tabla compacta) · **≥ 1200** escritorio (tablas completas, 2 columnas) · **≥ 1600** monitor: contenido con `max-width` ~1440 px centrado (no estirar formularios ni textos).

| Pantalla | Escritorio (≥ 1200) |
|---|---|
| Nuevo crédito | 2 columnas: formulario a la izquierda (~60%); **resumen + cronograma fijos (sticky) a la derecha** (~40%), siempre visibles mientras se escribe. Botones al pie del formulario (sin barra fija) |
| Detalle | Fila superior con 4 tarjetas (saldo, pagado, mora, próximo pago). Izquierda: tabla completa de cuotas (#, vence, capital, interés, mora, pagado, estado). Derecha (~340 px): acciones, pagos recientes, ficha del cliente |
| Cobrar | Modal ancho de 2 columnas: izquierda deuda de hoy + monto + método + excedente; derecha vista previa del reparto + botón confirmar |
| Listado | Tabla con filtros en barra superior, orden por columna, paginación; clic en fila → detalle |
| Cobranza del día | Tabla con acciones en la fila (llamar, WhatsApp, cobrar); resumen arriba; filtro por cobrador para el admin |
| Configuración / feriados | Formulario en grilla de 2–3 columnas; feriados en tabla con alta en línea |

- **Teclado** (el cajero en escritorio trabaja con teclado): foco inicial en el campo principal (buscar cliente, monto), `Enter` confirma el diálogo activo, `Esc` lo cierra, orden de tabulación lógico. Sin atajos globales en v1.
- Las mismas vistas y componentes para todos los tamaños (sin pantallas duplicadas): cambia solo el layout con clases de grilla de Bootstrap.
- Probar en 360, 768, 1280 y 1920 antes de cerrar cada pantalla.

## Además
- `MenuItemsSeeder`: grupo "Créditos" (giro `creditos`): Cobranza del día, Créditos, Nuevo crédito, Registrar existente, Configuración; cada ítem con su permiso.
- Caja: etiquetas legibles para `credito_desembolso`, `credito_pago`, `credito_devolucion_excedente` (y los de prendas cuando existan).
- Tests Vitest: `useClaveIdempotencia`, formateo de montos, mapeo de errores 422/409, visibilidad de acciones por permiso/estado.

## Verificar antes de codificar
1. Guard de rutas y `helpers/permisos.ts` con permisos compuestos (ej. anular propio vs `anular_pago`).
2. Selector de cliente reutilizable (ventas/cotizaciones) y si admite mostrar la tarjeta de resumen.
3. Cómo `httpClient` expone 422/409 (cuerpo, campos) para mapear infracciones.
4. Layout Rizz en 360 px: sidebar, header, espacio útil para Cobrar y Cobranza con una mano.

## Fuera de esta fase
PDF/contrato/recibo (4b), panel de inicio y reportes (4c), garantes y prendas (7), caja del cobrador y gestiones (8).