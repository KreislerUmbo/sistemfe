# Sistema POS / E-commerce — Contexto del proyecto

## Estructura del proyecto
```
sistemafe/
├── CLAUDE.md
├── api-sistema-fe/       → Backend Laravel (API REST, lógica de negocio, SUNAT/Greenter)
└── admin-start-kit/      → Frontend Vue 3 (panel admin/POS, template Rizz/Riz)
```
El portal e-commerce (Vue.js) consume la misma API de `api-sistema-fe/` pero vive en un
repositorio/carpeta separado (no incluido en este workspace).

## Estado actual del proyecto (avance)

**Completado (CRUD):**
- Roles y permisos
- Usuarios
- Categorías
- Productos
- Clientes

**En progreso — Ventas:**
- Registrar, actualizar y envío a SUNAT: implementado
- **Pendiente:** probar exhaustivamente todos los casos tributarios (detracción, percepción,
  exoneración Amazonía, exportación, y combinaciones entre ellos) antes de avanzar a los
  siguientes módulos. Ver matriz de casos de prueba (pendiente de completar).

**Próximos módulos (en orden de prioridad):**

1. **Representación impresa (PDF) con impresión automática**
   - Debe soportar **dos formatos**: A4 (factura/boleta normal) y ticket térmico 80mm.
   - Cada usuario/caja debe tener un **formato por defecto configurable** (A4 o ticket 80mm),
     con opción de cambiarlo manualmente en una venta puntual.
   - Plantillas separadas por formato (no una sola plantilla responsive) — el ticket térmico
     necesita reglas propias (ancho fijo, sin colores, tipografía condensada).
   - Método de impresión automática: **pendiente de decidir** entre modo kiosco de Chrome
     (`--kiosk-printing`), servicio local de impresión en la PC del cajero, o SDK/ESC-POS
     directo a la impresora térmica.

2. **Adelantos (pago anticipado de cliente antes de una venta futura)**
   - ⚠️ Pendiente confirmar con contador: en Perú, el adelanto probablemente requiere su
     propio comprobante electrónico SUNAT al momento de recibirse (la obligación del IGV
     nace cuando se percibe el pago, no cuando se entrega el bien).
   - Diseño propuesto (sujeto a confirmación): tabla `advances` con relación a `client_id`,
     `sale_id` (nullable, se llena al aplicarse), `sunat_document_id`, y `status`
     (`pending`, `applied`, `partially_applied`, `refunded`).
   - **Pendiente de definir:** si la tabla de ventas actual ya soporta pagos
     parciales/múltiples métodos de pago — esto determina si un adelanto se trata como "un
     método de pago más" o necesita lógica propia.

3. **Compras, amortizaciones, cajas** — módulos futuros, reglas de negocio aún sin definir.

## Stack técnico
- **Frontend (POS/admin):** Vue 3, template Rizz/Riz basado en Bootstrap 5
- **Frontend (e-commerce):** Portal Vue.js separado, conectado al mismo backend Laravel
- **Backend:** Laravel
- **Base de datos:** PostgreSQL
- **Facturación electrónica:** Integración con Greenter (SUNAT), generación y envío de XML

## Contexto de negocio
- Sistema orientado al mercado peruano, con cumplimiento de facturación electrónica SUNAT
  y reglas tributarias regionales.
- **Exoneración de Amazonía (Ley 27037):** aplica según combinación de naturaleza del
  producto, destino de la venta y si es exportación.
- **`resolverTipAfeIgv()`:** función central que determina el tipo de afectación del IGV
  combinando naturaleza de producto + destino + exportación. Cualquier cambio en reglas
  tributarias regionales probablemente pasa por aquí.
- **`SaleController::update()`:** usa una estrategia de sincronización con transacción DB
  de tres casos. Al modificar ventas existentes, mantener esta estructura transaccional
  para evitar inconsistencias entre la venta, sus detalles y el comprobante SUNAT asociado.

## Módulos principales
- Gestión de clientes y productos
- Registro y edición de ventas
- Generación de comprobantes electrónicos (boleta/factura) vía Greenter
- Portal e-commerce: catálogo, categorías, carrito — consume la misma API Laravel

## Convenciones y preferencias de trabajo
- **Frontend-first cuando sea suficiente:** si un problema se puede resolver de forma
  simple en el frontend (Vue), preferir esa solución antes que modificar el backend.
- **Explicar antes de corregir:** al debuggear, explicar la causa raíz del problema antes
  de aplicar la solución.
- **Comunicación entre frontend y backend:** usar el helper `httpClient` (Axios) para
  llamadas a la API Laravel.
- **Estilos:** cuidado con variables CSS personalizadas en estilos "scoped" de Vue — han
  dado problemas antes; si una variable CSS no se aplica en un componente, considerar
  moverla a `main.css` (global) en vez de depurar el scoping.
- **Timing del DOM en Vue:** evitar `document.getElementById()` directo en el montaje de
  componentes; usar `ref` de Vue + hooks de ciclo de vida (`onMounted`, etc.) para evitar
  errores de timing.

## Notas de arquitectura recientes
- Sync de ventas en edición: enfoque de tres casos dentro de una transacción DB en
  `SaleController::update()` (crear/actualizar/eliminar detalles de venta de forma atómica).
- Bugs resueltos recientemente: cálculo de impuestos, manejo de tipos de datos, renderizado
  de modales, alineación de íconos de navegación (flexbox + `<span>` dentro de
  `<router-link>`).

## Cómo trabajar en este proyecto
- Antes de tocar lógica tributaria (IGV, exoneraciones, `resolverTipAfeIgv()`), revisar
  el flujo completo: producto → destino → tipo de operación, ya que estas reglas están
  interrelacionadas y un cambio aislado puede romper otro caso.
- Antes de tocar `SaleController::update()`, confirmar que cualquier cambio mantenga la
  transacción DB atómica (todo-o-nada) entre venta, detalles y comprobante SUNAT.
