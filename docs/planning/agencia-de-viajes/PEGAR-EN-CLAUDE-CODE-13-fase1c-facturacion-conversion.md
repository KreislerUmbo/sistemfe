# Brief para Claude Code — Fila 13, fase 1c: facturar la línea de tour y convertir los tours

> Redactado el 14-oct-2026 y **completado al cerrar la 1b** (mismo día) con lo que esa sesión
> aprendió. No ejecutar antes de que la 1b esté en `main`. Tercera y última parte de la fase 1.

## 0. Lectura obligatoria
1. `plan-tours-venta-simple-costo-real.md`: §6 (bloques "Fase 1a — HECHA" y "Fase 1b — HECHA", con sus hallazgos), N5, N14, §3.3, §3.11 y §5c (C1).
2. `PEGAR-EN-CLAUDE-CODE-13-fase1b-tour-cotizacion.md` §1, decisiones D1-D3.
3. `ReservaFacturacionController`: leer el docblock completo antes de tocarlo. Explica por qué hoy nunca se parte una línea entre dos comprobantes y cómo funciona la selección por pasajero.
4. `TourLineaService` (1b): `calcular()`, `repartir()`, `recalcularUnidad()` y la forma de `tour_linea`.

## 1. Qué dejó la 1b (reutilizar, no reimplementar)
- Línea de tour: `alternativa_items.origen_tipo = 'tour'`, `modo_precio = 'por_persona'`, `tour_origen_id`.
- **Carga:** `carga_tour` (UUID) agrupa las líneas de un mismo tour cargado, una por grupo de IGV. `carga_combo` agrupa las cargas de un combo. Ambos se copian a `reserva_items` al aceptar, junto con `desglose_costo_snapshot` y el IGV.
- `alternativa_items.tour_linea` (JSON): `precios` unitarios (`adulto`, `nino`, `adulto_ext`, `nino_ext`, ya con los vacíos resueltos), `grupos` de IGV con su costo por persona, `grupo` (índice de la línea), `n_grupos`, `combo` (id, nombre, descuento) y `fecha_tour`. `reserva_items` **no** lo copia: se lee vía `alternativaItem`.
- `precio_manual` (bool): el vendedor fijó el precio del tour; el total se repartió entre las líneas de IGV en la proporción que tenían.
- `ReservaFacturacionController::clasificarCategoria()` ya devuelve `TOUR` para estas líneas, y un tour sin mezcla ya se factura entero como cualquier línea (test en la 1b).

## 2. Alcance

### Factura con IGV mezclado (D1, decidido con el usuario el 14-oct-2026)
- Hoy `detectarMezclaTributaria()` bloquea un comprobante con más de un `destino_tributario` (por ejemplo, Amazonía + nacional), con el mensaje "requiere revisión con el contador".
- Cambio: **permitir** la mezcla cuando todas las líneas con destino distinto pertenecen a **una misma `carga_tour`** (en `reserva_items`). El comprobante sale con una línea por tipo de IGV, como ya hace `agruparPorCategoria()` dentro de un destino.
- **Se mantiene todo lo demás:**
  - mezcla de servicios sueltos de distinto destino: sigue bloqueada;
  - `extranjero`: sigue bloqueado;
  - Amazonía: sigue exigiendo la confirmación ítem por ítem (`motivo_override_tributario`). **Hallazgo 3 de la 1b:** un tour exonerado cargado como línea cae en esta regla. Decidir con el usuario si la confirmación se hace una vez por carga (todas sus líneas) en vez de línea por línea.
- Pregunta abierta: ¿un combo (`carga_combo`) cuenta como una misma carga para la mezcla? D1 dice "una misma carga de tour"; confirmarlo antes de implementar.

### Facturación por subgrupo de pasajeros con la línea de tour (C1)
- Hoy una línea nunca se parte entre dos comprobantes. La línea de tour puede saber cuánto le toca a cada pasajero: `tour_linea.precios` × tipo y residencia del pasajero (la residencia está en `cotizacion_pasajeros`; **revisar si se copia a `reserva_pasajeros`** — la 1b no lo hizo — o si hay que mapear por el pasajero de cotización).
- **Complicaciones que la 1b dejó a la vista** (definir la regla con el usuario):
  - el precio vendido puede diferir del de lista: descuento del combo, descuento global de la alternativa, precio a mano. Propuesta: parte del pasajero = su precio de lista × (precio vendido de la carga ÷ precio de lista de la carga), con los centavos al último subgrupo (o `TourLineaService::repartir()`);
  - una carga con IGV mezclado son varias líneas: la parte de cada pasajero se reparte entre ellas con la misma proporción de la carga;
  - el infante paga 0.
- Cambio: al facturar un subgrupo, la línea de tour aporta **exactamente la parte de esos pasajeros**, y queda registrado qué parte ya se facturó, para no facturarla dos veces. Solo para líneas de tour; el resto sigue sin fragmentarse.

### Conversión de los tours existentes (§3.11)
- Pantalla o flujo que parte de `agencia-viajes:diagnosticar-tours` y muestra **tour por tour** la propuesta: precio (el cargado o la suma de ventas de sus tarifas), costo (calculadora) e IGV de cada ítem. El **usuario confirma uno por uno** (D20 del plan anterior).
- Hallazgo 3 de la 1a: proponer el **IGV de la tarifa** en cada ítem del Incluye, no dejarlo vacío. (La pestaña "Incluye y costos" ya muestra "La tarifa es Gravado · usar".)
- Para IGV mezclado, todos los servicios necesitan costo: la conversión debe avisarlo (si no, la carga da 422).
- Los tours `solo_itinerario` quedan fuera. Datos conocidos:
  - `agencia-demo`: 6 tours con precio y 6 `solo_itinerario`; Lamas mezcla IGV. El tour #12 (Laguna Azul) no tiene costos en el Incluye: en la prueba en vivo de la 1b la línea quedó con costo 0 y aviso.
  - dakamu: 3 tours sin precio, 0 de mayorista y 1 solo ítem en el Incluye.

## 3. Reglas
- Igual que la 1b (§5 de su brief): explicar antes de corregir, hallazgos como sub-paso propio, migraciones aditivas con `down()` probado, `HoraPeru`, no commitear sin confirmar.
- Las temporadas son catálogo central y no existen en `sistemafe_test_migrations`: para tests de temporada, usar el resolver en memoria de `Fila13Fase1bTourCotizacionTest::temporadas()`.
- Un tour Amazonía exige confirmar el tratamiento antes de facturar: en tests de facturación usar un tour gravado nacional, o confirmar los ítems.

## 4. Tests mínimos
- Comprobante de un tour con IGV mezclado: una línea exonerada y otra gravada, totales exactos.
- Mezcla de servicios sueltos de distinto destino: sigue bloqueada; mezcla entre dos cargas distintas: bloqueada.
- Subgrupo de pasajeros: la parte facturada coincide con el precio de esos pasajeros (con y sin descuento de combo y precio a mano); no se puede facturar dos veces; la suma de los subgrupos da el total de la línea.
- Conversión: nada se escribe sin confirmación; el IGV propuesto sale de la tarifa.
