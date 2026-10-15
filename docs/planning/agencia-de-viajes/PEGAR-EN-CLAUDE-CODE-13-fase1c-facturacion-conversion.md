# Brief para Claude Code — Fila 13, fase 1c: facturar la línea de tour y convertir los tours

> **BORRADOR (14-oct-2026).** Se termina al cerrar la 1b, con lo que esa sesión aprenda. No
> ejecutar antes de que la 1b esté en `main`. Tercera y última parte de la fase 1 del plan.

## 0. Lectura obligatoria
1. `plan-tours-venta-simple-costo-real.md`: §6 (bloques de la 1a y la 1b, con sus hallazgos), N5, N14, §3.3, §3.11 y §5c (C1).
2. `PEGAR-EN-CLAUDE-CODE-13-fase1b-tour-cotizacion.md` §1, decisiones D1-D3.
3. `ReservaFacturacionController`: leer el docblock completo antes de tocarlo. Explica por qué hoy nunca se parte una línea entre dos comprobantes y cómo funciona la selección por pasajero.

## 1. Alcance

### Factura con IGV mezclado (D1, decidido con el usuario el 14-oct-2026)
- Hoy `detectarMezclaTributaria()` bloquea un comprobante con más de un `destino_tributario` (por ejemplo, Amazonía + nacional), con el mensaje "requiere revisión con el contador".
- Cambio: **permitir** la mezcla cuando todas las líneas con destino distinto pertenecen a **una misma carga de tour** (el identificador que crea la 1b). El comprobante sale con una línea por tipo de IGV, como ya hace `agruparPorCategoria()` dentro de un destino.
- **Se mantiene todo lo demás:**
  - mezcla de servicios sueltos de distinto destino: sigue bloqueada;
  - `extranjero`: sigue bloqueado;
  - Amazonía: sigue exigiendo la confirmación ítem por ítem (`motivo_override_tributario`).

### Facturación por subgrupo de pasajeros con la línea de tour (C1)
- Hoy una línea nunca se parte entre dos comprobantes. Una línea de tour **sí sabe cuánto le toca a cada pasajero** (adulto/niño × nacional/extranjero; la 1b debe dejar ese desglose guardado en la línea).
- Cambio: al facturar un subgrupo de pasajeros, la línea de tour aporta **exactamente la parte de esos pasajeros**, y queda registrado qué parte ya se facturó, para no facturarla dos veces.
- Solo para líneas de tour. El resto de ítems sigue con la regla de hoy (no se fragmentan).

### Conversión de los tours existentes (§3.11)
- Pantalla o flujo que parte de `agencia-viajes:diagnosticar-tours` y muestra **tour por tour** la propuesta: precio (el cargado o la suma de ventas de sus tarifas), costo (calculadora) e IGV de cada ítem. El **usuario confirma uno por uno** (D20 del plan anterior).
- Hallazgo 3 de la 1a: proponer el **IGV de la tarifa** en cada ítem del Incluye, no dejarlo vacío.
- Los tours `solo_itinerario` quedan fuera. Datos conocidos:
  - `agencia-demo`: 6 tours con precio y 6 `solo_itinerario`; Lamas mezcla IGV.
  - dakamu: 3 tours sin precio, 0 de mayorista y 1 solo ítem en el Incluye.

## 2. Tests mínimos (a completar)
- Comprobante de un tour con IGV mezclado: una línea exonerada y otra gravada, totales exactos.
- Mezcla de servicios sueltos de distinto destino: sigue bloqueada.
- Subgrupo de pasajeros: la parte facturada coincide con el precio de esos pasajeros; no se puede facturar dos veces; la suma de los subgrupos da el total de la línea.
- Conversión: nada se escribe sin confirmación; el IGV propuesto sale de la tarifa.
