# Precio de tour por persona (precio fijo con reparto entre servicios)

> ## ⛔ DESCARTADO (13-oct-2026) — se conserva solo como alternativa evaluada
>
> **Reemplazado por `plan-tours-venta-simple-costo-real.md`.** Razón: este plan buscaba un
> costo exacto **al cotizar** (reparto entre servicios, mínimo como divisor, vehículo de
> referencia, `cobro_por`, vehículo por confirmar). Era mucha complejidad para estimar algo
> que cambia el día del tour, y aun así nunca habría mostrado el margen **real**. El usuario
> propuso el modelo de presupuesto contra real (vender simple, operar por salida, registrar
> los gastos reales con comprobante), y se adoptó. Las piezas que siguen valiendo están
> listadas en §4 del plan nuevo. **No implementar este documento.**
>
> ---
>
> *Estado anterior:* diseño cerrado, pendiente de aprobación. Redactado el
> 11-oct-2026 a partir de una conversación con el usuario.
> - Rondas 2 y 3 (11-oct): D10-D16.
> - Ronda 4 (13-oct): movilidad contratada por vehículo y vehículo por confirmar en la reserva, D17-D19.
> - Ronda 5 (13-oct): repaso completo del plan contra el código y los datos de `agencia-demo`. Se encontraron 11 faltantes, todos incorporados: D20-D24, §3.7 corregido (hoteles), E5.
> - Ronda 6 (13-oct): ejercicio completo de punta a punta (Anexo A). Agrega D25 (costo niño en tarifas) y D26 (indicador de mínimo en la Salida Operativa).
>
> El endoso de pasajeros tiene su propio plan (`plan-endoso-pasajeros.md`, §9).

---

## 1. Problema

Al crear un tour se cargan sus servicios (proveedor, costo y venta de cada uno) y además un
"precio de venta" general. **El cotizador nunca usa ese precio general:** al cargar un tour,
`AlternativaItemController::desdePlantilla()` crea una línea por servicio con la venta de su
tarifa de proveedor. `precio_venta_final` solo se muestra en el catálogo como
"Precio de catálogo desde". `paquetes/detalle.vue` ya detecta que no coincide con la suma de
los servicios, pero no hace nada al respecto.

La única forma de corregirlo hoy es `ajuste_redondeo`, que tiene un error: se agrega como
**una sola línea `tarifa_fija`, cantidad 1**, mientras que los servicios compartidos se
multiplican por pasajero.

Ejemplo: servicios que suman S/ 93.66, ajuste de +6.34 y 4 adultos.
- Esperado: S/ 400.
- Real: 4 × 93.66 + 6.34 = **S/ 380.98**.

## 2. Decisiones tomadas

| # | Decisión |
|---|---|
| D1 | Los servicios del tour **siguen siendo la forma principal** de armarlo (proveedor y costo reales). De ellos dependen el Reporte Operativo, la Salida Operativa, el guía, el "Incluye" del PDF y la facturación por tipo de IGV. Excepción: D15. |
| D2 | Cada tour elige una estrategia de precio: **Suma de servicios** (lo actual, por defecto) o **Precio por persona**. |
| D3 | El precio por persona lleva **adulto (obligatorio)** y **niño (opcional; si está vacío, se usa el de adulto)**. **El infante paga 0.** Lo que un niño pequeño necesite se agrega como servicio suelto. En los tours, los niños son mayores de 10 años. |
| D4 | El precio fijo se **reparte entre los servicios en proporción a su costo** (opción A): todos los servicios del bloque quedan con el mismo % de margen. |
| D5 | Los **tours privados** quedan fuera por ahora: siguen con "Suma de servicios". En el privado (mínimo 6 pax), el guía es un costo de grupo cargado completo a la cotización, como hoy. |
| D6 | El IGV no se complica: cada línea conserva su `tip_afe_igv` y la facturación ya separa por categoría y tipo de IGV. El precio enviado al cliente incluye IGV. |
| D7 | `ajuste_redondeo` **se retira**, con la condición de no romper nada (§6). |
| D8 | Los servicios sueltos (movilidad, hotel, restaurante fuera de un tour) **no cambian**: se cotizan con el precio de su tarifa. El reparto solo modifica las líneas de esa cotización; la tarifa del proveedor nunca se toca. |
| D9 | El precio por persona queda guardado en el tour para publicarlo en la web más adelante. |
| D10 | **Si en una cotización se quita un servicio de un tour con precio fijo, el precio baja en la parte que ese servicio tenía.** |
| D11 | **En el tour compartido con precio por persona, el costo del guía (y cualquier costo por grupo) se comparte entre todas las reservas que salen ese día.** En el costeo se convierte a costo por persona, dividiéndolo entre el **mínimo de pasajeros para salir** del tour (§3.1). |
| D12 | **El precio por persona excluye el alojamiento.** En el cotizador, el hotel se agrega aparte (matriz de hoteles, como hoy). El "enlatado" con hotel para la web queda para la fase web (§8). |
| D13 | **El infante sí aparece en el Reporte Operativo** y en las listas de pasajeros. Solo se excluye del cálculo de precio y costo. |
| D14 | Cambiar pasajeros de una **reserva** no recalcula precios (`ReservaPasajeroController`/`ReservaItemPasajeroController`: 0 referencias a precio o costo). Sin impacto. |
| D15 | **Se permite un tour con precio por persona y sin servicios cargados.** Caso real: tours nacionales donde el operador da su precio, la agencia le pone su margen y vende. El tour lleva un **costo referencial por persona** y su propio **tipo de IGV** (D24); en la cotización entra como una línea manual (§3.4). |
| D16 | **La movilidad tiene tres casos según la agencia:** (a) **contrata transportistas que cobran por persona**: tarifa con cobro por persona, como hoy; (b) **contrata el vehículo completo** (auto, minivan o combi, según cantidad de pasajeros) y reparte su costo entre los pasajeros; (c) **tiene movilidad propia**: se registra a la propia agencia como proveedor tipo "Transporte". (b) y (c) usan tarifas con **cobro por grupo** (§3.7) y entran en §3.2 divididas entre el mínimo para salir, igual que el guía. Un indicador de "movilidad propia" para reportes queda para el plan de endoso (§9). |
| D17 | **Se separan dos ideas que hoy están mezcladas en `proveedor_tarifas.modalidad`:** `modalidad` (compartido/privado) sigue diciendo **con quién se comparte el servicio**; un campo nuevo, `cobro_por` (persona/grupo) más `capacidad_pax`, dice **cómo se paga**. La combi de un tour compartido queda como *compartido + cobro por grupo*: se sigue enganchando a la Salida Operativa (que solo engancha `compartido`, `ReservaController.php:592`) y su costo se divide entre el mínimo. El tour gana su propio campo `modalidad` (compartido/privado). Detalle en §3.7. |
| D18 | **En un tour compartido del mismo día, las reservas pueden ir en vehículos y zonas de recojo distintas** (coincide con la migración de `salidas_operativas`: el transporte no se centraliza, vive en `reserva_items`). Al cotizar se usa el costo de referencia por persona. El costo real se registrará en una fase posterior compartida con el endoso: **Salida Operativa con varios vehículos**, cada uno con su tarifa, su costo real y las reservas que lleva. No es parte de este plan. |
| D19 | **El vehículo se asigna en la reserva, no al cotizar.** Hoy la línea de la reserva hereda la tarifa de la cotización (`ReservaController.php:522`) y nace mostrando ese vehículo como si ya estuviera asignado. La reserva **conserva la tarifa de referencia como sugerencia, marcada "Vehículo por confirmar"**, hasta que se reasigne o se confirme (§3.8). |
| D20 | *(ronda 5)* **Los tours que ya tienen `precio_venta_final` cargado** (6 en `agencia-demo`, §7) no se convierten solos. Un diagnóstico compara, por tour, el precio cargado contra la suma real de sus servicios, y **el usuario decide tour por tour** si pasa a "precio por persona" con ese precio. Los que se quedan en "suma de servicios" muestran en el catálogo la **suma calculada**, no el número viejo. |
| D21 | *(ronda 5)* **El precio del tour se puede negociar dentro de una cotización:** acción "Cambiar precio del tour en esta cotización" sobre el bloque (adulto y niño), que vuelve a repartir. Los descuentos línea por línea siguen existiendo como hoy. |
| D22 | *(ronda 5)* **El guía cobra por día.** En un tour de varios días, su costo es costo diario × días del tour. Hoy se costea 1 solo día (E5). |
| D23 | *(ronda 5)* **Los hoteles no llevan `cobro_por`** (se cobran por habitación y siguen calculándose como hoy). El valor que cubre vehículos se llama **`grupo`**, no `vehiculo`: también sirve para un bote, un cocinero o cualquier servicio contratado por grupo. |
| D24 | *(ronda 5)* **El tour sin servicios (D15) lleva su propio tipo de IGV** (gravado, exonerado o inafecto, con su destino tributario), que pasa a su línea manual. Los tours de la agencia están en San Martín (Amazonía) y pueden ser exonerados. |
| D25 | *(ronda 6)* **Las tarifas ganan un costo niño opcional** (`precio_costo_nino`). Hoy solo tienen un `precio_costo`, así que a un niño se le carga el costo de adulto aunque el proveedor le cobre menos (por ejemplo, entrada S/ 5 en vez de S/ 10), y el margen del niño sale peor de lo real. Si está vacío, se usa el costo de adulto: ninguna tarifa existente cambia. Solo aplica a `cobro_por = persona`. |
| D26 | *(ronda 6)* **La Salida Operativa muestra cuántos pasajeros lleva contra el mínimo del tour** ("7 / 6 mínimo" ✅ o "4 / 6 mínimo" ⚠️) en el Reporte Operativo, para decidir con tiempo si se sale o se endosa. Cuenta adultos y niños de las reservas enganchadas; los infantes se muestran aparte. Lee `pax_minimo_salida` del tour en vivo (la salida ya conoce su `tour_origen_id`). Sin mínimo cargado, no se muestra nada. |

## 3. Diseño

### 3.1 En el tour (`paquetes_plantilla`)

Columnas nuevas:

| Columna | Uso |
|---|---|
| `estrategia_precio` | `suma_servicios` \| `precio_por_persona`. Por defecto `suma_servicios`: ningún tour existente cambia. |
| `precio_venta_nino` | Decimal, nullable (D3) |
| `moneda` | `PEN` \| `USD`, por defecto `PEN`. Hoy el tour no tiene moneda y las tarifas sí; también hace falta para la web. |
| `pax_minimo_salida` | Entero, nullable. **Mínimo de pasajeros para salir.** Divide los costos por grupo para obtener su costo por persona (D11). |
| `costo_referencial_persona` | Decimal, nullable. Solo para un tour sin servicios (D15): lo que el operador cobra por persona. |
| `tip_afe_igv`, `destino_tributario` | Nullable. Solo para un tour sin servicios (D24). Mismo formato que en `proveedor_tarifas`. |
| `modalidad` | `compartido` \| `privado` (D17). `precio_por_persona` solo se permite en tours `compartido` (D5). Backfill en §3.7. |

`precio_venta_final` se reutiliza como **precio adulto**, y solo tiene efecto con
`precio_por_persona`. Con `suma_servicios`, el catálogo muestra la suma calculada (D20).

Validaciones en `PaquetePlantillaController` para `precio_por_persona`:
- Precio adulto mayor que 0.
- **Al menos 1 servicio, o un `costo_referencial_persona`** (D15). Sin servicios, `tip_afe_igv` es obligatorio (D24).
- El tour tiene que ser `modalidad = compartido` (D5).
- Si hay servicios con costo por grupo (guía, o tarifa con `cobro_por = grupo`), **`pax_minimo_salida` es obligatorio**.
- Si `pax_minimo_salida` es mayor que la `capacidad_pax` de un servicio por grupo, se avisa (no se puede salir con ese mínimo en ese vehículo).
- Los hoteles (`tipo_habitacion` no nulo) no participan del precio por persona (D12): se avisa que quedan fuera.

Pantalla del tour (`paquetes/form.vue`, `paquetes/detalle.vue`):
- Selectores de estrategia y de modalidad, precio adulto y niño, mínimo para salir, costo referencial y tipo de IGV (si no hay servicios).
- **Margen en vivo, por persona**, en S/ y en %, contra `margen_minimo_pct` del tour, el de la agencia y el de cada tarifa.
- Si hay costos por grupo: una línea de ayuda, por ejemplo "Con 6 pax (mínimo) el guía cuesta S/ 20 por persona; con 10 pax, S/ 12".

### 3.2 Costo por persona de cada servicio del tour

| Tipo de servicio en un tour con precio por persona | Costo por persona |
|---|---|
| Tarifa con `cobro_por = persona` | Adulto: `precio_costo`. Niño: `precio_costo_nino ?? precio_costo` (D25) |
| Guía (`guia_tarifa`) | `costo_diario × días_del_tour / pax_minimo_salida` (D22) |
| Tarifa con `cobro_por = grupo` (vehículo contratado o propio, bote, etc.) | `precio_costo / pax_minimo_salida` |
| Hotel | **No participa** (D12) |
| Tour sin servicios | `costo_referencial_persona` |

`días_del_tour` = máximo `dia_relativo` del itinerario del tour (`?? 1`), el mismo criterio que
`desdePlantilla()` ya usa para ubicar los tours de un combo en días.

En la cotización, el costo de cada línea = costo adulto × adultos + costo niño × niños (para
los costos por grupo, el costo por persona es el mismo para adulto y niño).

**Precisión:** el costo por persona de un costo por grupo no se redondea a 2 decimales antes
de multiplicar. Se calcula `precio_costo × (A+N) / divisor` y se redondea al final. Así, con
6 pax y una combi de S/ 250, el costo da S/ 250.00 y no S/ 249.96.

**Por qué la referencia es segura:** con el mínimo como divisor, la referencia es el peor caso
por persona de cualquier día que sí sale. Ejemplo con auto S/ 150 (hasta 4), minivan S/ 250
(hasta 10) y combi S/ 350 (hasta 20), referencia minivan y mínimo 6 → S/ 41.67 por persona.
Con 9 pax en minivan el costo real es S/ 27.78; con 14 en combi, S/ 25.00. Solo un día que
no llega al mínimo cuesta más, y ese caso se resuelve saliendo con pérdida o endosando.

**Consecuencia buscada:** el margen de cada cotización ya no depende de cuántos pasajeros
trae esa cotización. El riesgo pasa al nivel de la salida del día (§9).

### 3.3 Bloque de tour en la cotización (tabla nueva)

`alternativa_tour_bloques` registra **cada carga** de un tour o combo en una alternativa:

| Columna | Uso |
|---|---|
| `id` | Identificador de esta carga |
| `alternativa_id`, `alternativa_destino_id` | Dónde se cargó |
| `paquete_plantilla_id` | Qué tour o combo se cargó |
| `estrategia_precio` | Copia al momento de cargar |
| `moneda`, `precio_adulto`, `precio_nino` | **Copia del precio al cargar.** Si después se cambia en el catálogo, la cotización no cambia. Se reduce al quitar un servicio (D10) y se puede cambiar con la acción de D21. |

Columnas nuevas en `alternativa_items`:
- `tour_bloque_id`: nullable, FK.
- `divisor_costo_grupo`: entero, nullable. **El mínimo para salir que se usó para esa línea.** Va por línea, no en el bloque, porque en un combo cada tour tiene su propio mínimo (los servicios sueltos del combo usan el mínimo del combo). Se usa para recalcular el costo al cambiar pasajeros.

¿Por qué una tabla y no solo `tour_origen_id`?
- Hoy, si el mismo tour se carga dos veces (por ejemplo, día 2 y día 5), las dos cargas comparten `tour_origen_id`, y `moverBloque()` **mueve las dos** (E4).
- Un recálculo mezclaría ambas cargas.
- La copia del precio tiene que guardarse en algún lado.
- El endoso (§9) también necesita tratar "este tour de esta reserva" como una unidad.

`tour_origen_id` se mantiene: lo usan el itinerario, el "Incluye" y el PDF.

### 3.4 Algoritmo de reparto (`RepartoPrecioTourService`, nuevo)

Entrada: un bloque con `estrategia_precio = precio_por_persona`.

1. `A` = adultos y `N` = niños de la cotización. **Los infantes no cuentan** en precio ni en costo (D3, D13).
2. Precio total: `T = precio_adulto × A + (precio_nino ?? precio_adulto) × N`, convertido a la moneda de la cotización con su `tipo_cambio_aplicado`.
3. Peso de cada línea: su costo total según §3.2, convertido a la moneda de la cotización. Los hoteles no entran.
4. `venta_i = T × peso_i / Σpesos`, redondeado a 2 decimales. **Los centavos sobrantes van a la línea de mayor peso**, para que `Σ venta_i = T` exacto.
5. Casos borde:
   - Si `Σpesos = 0`, se reparte según la venta de lista.
   - Si también es 0, se reparte en partes iguales.
6. Se escribe `precio_convertido` y `precio_venta_snapshot` (este último en `moneda_costo`). Un `descuento_pct` manual se aplica después del reparto, igual que hoy; en ese caso el total del bloque deja de ser exacto a propósito (es una negociación de esa línea).
7. **Tour sin servicios (D15):** no hay reparto. Se crea una única línea `ORIGEN_MANUAL` enganchada al bloque, con:
   - `descripcion_manual` = nombre del tour, y **`tour_origen_id` = el tour**, para que se agrupe bien en el itinerario y en el PDF;
   - costo = `costo_referencial_persona × (A+N)` y venta = `T`;
   - `tip_afe_igv`/`destino_tributario` copiados del tour (D24).

   Sale en el Reporte Operativo (las líneas manuales ya salen) y se puede convertir después con "Promover a proveedor" (`promoverAProveedor()`, ya existe).

Ejemplo verificado a mano: costos 30/15/25, precio S/ 100, 1 adulto → 42.86 / 21.43 / 35.71 = 100.00.

### 3.5 Cuándo se reparte

| Evento | Qué pasa |
|---|---|
| Se carga el tour (`desdePlantilla()`) | Se crea el bloque y sus líneas (con `divisor_costo_grupo`), y se reparte. **Ya no se crea la línea "Ajuste de redondeo".** |
| Cambian los pasajeros (`CotizacionController::recalcularItemsPorPersona()`) | Las líneas del bloque **no** se recalculan con la tarifa del proveedor. Se recalculan los costos según §3.2 (usando `divisor_costo_grupo` de cada línea) y se **vuelve a repartir** con el precio copiado. Hoy esto pisaría el reparto en silencio (R1). |
| **Se cambia el precio del tour en la cotización (D21)** | Acción nueva sobre el bloque: se edita el precio adulto y niño copiado y se vuelve a repartir. Valida el margen igual que el formulario del tour (avisa, no bloquea). |
| Se quita una línea del bloque (D10) | Antes de borrarla, se calcula su parte por persona: `parte_adulto = precio_adulto × venta_i / T`, y lo mismo para niño. Se resta del precio copiado del bloque y se reparte de nuevo entre las líneas que quedan. Si era la última línea, se elimina el bloque. |
| Se edita el precio de una línea a mano | Se respeta (descuento manual, como hoy). No se reparte de nuevo. |
| Se intenta restringir una línea del bloque a algunos pasajeros (`pax_incluidos`) | **No se permite** en bloques con precio por persona: el reparto cuenta a todos los adultos y niños. Para algo distinto se agrega un servicio suelto. |
| Se duplica la alternativa (`AlternativaController::duplicar()`) | Se clonan los bloques y se remapea `tour_bloque_id`; se copia `divisor_costo_grupo`. **`duplicar()` copia campo por campo: las columnas nuevas tienen que agregarse ahí.** |
| Se acepta y se crea la reserva | Sin cambios de precio: se copia `precio_venta_snapshot` tal cual. Se copia también `tour_bloque_id` a `reserva_items`, para el endoso (§9). |

### 3.6 Combos

Hoy el combo calcula su precio de catálogo como "suma de los tours − descuento + ajuste", pero
**al cargarlo en la cotización el descuento nunca se aplica**: `desdePlantilla()` solo agrega
el ajuste (E3; en `agencia-demo` hay 0 combos con descuento).

Propuesta: el combo usa el mismo mecanismo.
- **Un bloque para todo el combo**, con un precio por persona:
  - Si es `suma_servicios`: suma del precio por persona de cada tour − descuento.
  - Si es `precio_por_persona`: el precio fijo del combo.
- Ese precio se reparte entre todas las líneas del combo, **excepto los hoteles** (D12), que mantienen su precio de tarifa como hoy. Así **el descuento por fin llega a la cotización**.
- Cada línea usa el mínimo para salir de **su** tour (`divisor_costo_grupo`, §3.3); los servicios sueltos del combo usan el mínimo del combo.
- `totalesTour()` devuelve el precio por persona cuando el tour lo tenga, para que el catálogo y `ComboValidationService::validarMargenMinimo()` usen el mismo número.
- Se quita el 422 que impide `precio_venta_final` en un combo.
- Si un combo tiene hoteles, el catálogo muestra "No incluye alojamiento".

### 3.7 Tarifas: "con quién se comparte" vs. "cómo se cobra" (D17, D23)

Hoy `proveedor_tarifas.modalidad` (`compartido`/`privado`) hace dos trabajos a la vez:
- La **Salida Operativa** solo engancha servicios `compartido` (`ReservaController.php:592`).
- La **explosión del tour** (`desdePlantilla()`) multiplica por pasajero los `compartido` y deja los `privado` como `tarifa_fija`, cantidad 1. Los hoteles ya tienen su propio camino (`tipo_habitacion`, línea 412).

Una combi pagada por vehículo dentro de un tour compartido no encaja en ninguno de los dos
valores: es compartida (va en la salida del día) pero se cobra por grupo.

Columnas nuevas en `proveedor_tarifas`:

| Columna | Uso |
|---|---|
| `cobro_por` | `persona` \| `grupo`, **nullable**. **Cómo se paga.** Reemplaza a `modalidad` en todo cálculo de precio y costo. **Los hoteles quedan en `null`** (D23): se cobran por habitación, como hoy. |
| `precio_costo_nino` | Decimal, nullable (D25). Solo para `cobro_por = persona`. Vacío = se usa `precio_costo`. También lo usan los servicios sueltos y `recalcularItemsPorPersona()` (E2), no solo los tours. |
| `capacidad_pax` | Entero, nullable. Pasajeros máximos del vehículo o grupo. Por ahora solo se muestra y se valida contra el mínimo; en los tours privados (fase posterior) permitirá elegir el vehículo según los pasajeros. |

`modalidad` queda solo para **con quién se comparte** (enganche a la Salida Operativa, badge
"pública/privada" de las cards).

**Backfill sin cambiar ningún comportamiento** (en `agencia-demo`: 12 compartido y 5 privado
sin hotel, más 9 de hotel):
- Hoteles (`tipo_habitacion` no nulo): `cobro_por = null`.
- Resto con `modalidad = compartido`: `cobro_por = persona`.
- Resto con `modalidad = privado`: `cobro_por = grupo`.
- La explosión pasa a decidir con `cobro_por` en vez de `modalidad`. Con el backfill, cada tarifa existente se calcula exactamente igual que hoy (test de regresión obligatorio).
- `guia_tarifas` no cambia: el guía siempre es costo por grupo (`costo_diario`).

**Lugares que crean tarifas y tienen que llenar `cobro_por`:**
- `ProveedorTarifaController` (formulario de tarifas).
- `AlternativaItemController::promoverAProveedor()` (línea 1332).
- `OpcionHotelController` (línea 421): hotel, `cobro_por = null`.

**Backfill de `paquetes_plantilla.modalidad`:** por defecto `compartido`. Los tours que hoy
tienen alguna tarifa `privado` sin hotel (2 en `agencia-demo`) quedan listados por un
diagnóstico para revisión manual: el sistema no puede saber solo si son tours privados o
tours compartidos con un servicio por grupo.

**Pantallas:**
- `proveedores/detalle.vue` (formulario de tarifa): selector "Se cobra por: persona / grupo", con capacidad cuando es por grupo y costo niño opcional cuando es por persona. Oculto para hoteles.
- Formulario del tour: selector compartido/privado.
- **Etiquetas "tipo · modalidad"** de la tarifa en 4 pantallas (`cotizador/editar.vue`, `paquetes/detalle.vue`, `reservas/detalle.vue`, `reporte-operativo/index.vue`): se suma "por persona" / "por grupo".

### 3.8 Vehículo "por confirmar" en la reserva (D19)

Columna nueva en `reserva_items`: `vehiculo_por_confirmar` (boolean, por defecto `false`).

- **Al crear la reserva** (`ReservaController`, donde hoy se copia `proveedor_tarifa_id`): queda en `true` si la tarifa tiene `cobro_por = grupo` y la línea viene de un tour `compartido`. La tarifa de referencia se conserva tal cual, como sugerencia.
- **Pasa a `false`** cuando:
  - se reasigna el proveedor o la tarifa de esa línea (`ReservaItemController::update()` o la reasignación inline del Reporte Operativo), o
  - se usa una acción nueva **"Confirmar vehículo"** (se queda la referencia). **Usa el mismo permiso que reasignar proveedor.**
- **Se conserva** al reprogramar la reserva (`reprogramar()`) y al sincronizar ítems: cambiar la fecha no confirma el vehículo.
- **Backfill:** las reservas existentes quedan en `false`, sin marcar nada antiguo.
- **Pantallas:** badge "Vehículo por confirmar" en `reservas/detalle.vue` y en el Reporte Operativo, con el botón "Confirmar" al lado del de reasignar. Opcional: filtro "solo por confirmar" en el Reporte Operativo.
- No toca precio ni costo: es solo un estado operativo.

## 4. Errores existentes que se corrigen de paso

| # | Error | Dónde |
|---|---|---|
| E1 | El ajuste de redondeo no se multiplica por pasajero | `desdePlantilla()`. Desaparece con D7. |
| E2 | Al cambiar los pasajeros, se actualiza la venta pero **no el costo** de las líneas compartidas: margen inflado | `CotizacionController::recalcularItemsPorPersona()`. Se corrige también para las líneas que no son de tours. |
| E3 | El descuento del combo no se aplica en la cotización | `desdePlantilla()`, §3.6 |
| E4 | `moverBloque()` mueve todas las cargas del mismo tour, no solo una | Pasa a filtrar por `tour_bloque_id` |
| E5 | *(ronda 5)* El guía de un tour de varios días se costea como 1 solo día | `ComboExplosionService::resolverCostoVentaItem()`: pasa `costo_diario` una vez. Se multiplica por los días del tour (D22). Afecta también a los tours con "suma de servicios". |

## 5. Riesgos revisados

| # | Riesgo | Cómo se resuelve |
|---|---|---|
| R1 | Cambiar pasajeros borra el reparto | §3.5 |
| R2 | Dos cargas del mismo tour se mezclan | Bloque propio, §3.3 |
| R3 | Guía o costo por grupo: con pocos pasajeros la cotización da pérdida | Costo por persona con el mínimo para salir (D11, §3.2). El riesgo real (no llegar al mínimo) se gestiona en la salida del día (§9). |
| R4 | Moneda | `paquetes_plantilla.moneda` y reparto con costos convertidos |
| R5 | El infante suma costo aunque pague 0 (hoy: costo × (A+N+I)) | En los bloques con precio por persona no cuenta en precio ni costo, pero sigue en las listas (D13) |
| R6 | Combos | §3.6 |
| R7 | IGV mezclado en un tour (1 en `agencia-demo`) | Cada línea conserva su `tip_afe_igv`; el reparto por costo divide gravado y exonerado |
| R8 | Margen mínimo por línea (`evaluarPiso()`) | El formulario del tour avisa antes de que aparezca la alerta en el cotizador. `update()` toma como precio de lista `precio_venta_snapshot`, que ya es el precio repartido: no hay descuento fantasma. |
| R9 | El costo de un proveedor sube después de fijar el precio | Las cotizaciones no cambian (copia). El listado de tours marca los que quedaron bajo el margen mínimo. |
| R10 | Cambiar el precio en el catálogo | No afecta cotizaciones hechas (copia en el bloque) |
| R11 | Venta Directa | No usa tours. Sin impacto. |
| R12 | Hoteles dentro de un tour o combo | Fuera del reparto, con su precio de tarifa (D12), y sin `cobro_por` (D23) |
| R13 | *(ronda 5)* Backfill de `cobro_por` clasificando mal los hoteles | Hoteles en `null` (§3.7) |
| R14 | *(ronda 5)* Combo con tours de distinto mínimo | `divisor_costo_grupo` por línea (§3.3) |
| R15 | *(ronda 5)* Línea del bloque restringida a algunos pasajeros | No se permite (§3.5) |

## 6. Retiro de `ajuste_redondeo` (D7)

Dónde se usa hoy: `PaquetePlantilla` (fillable y cast), `PaquetePlantillaController`
(validación), `ComboExplosionService::totalesCombo()`, `PriceEngineService::calcularCombo()`,
`AlternativaItemController::desdePlantilla()`, `paquetes/detalle.vue`,
`types/agencia-viajes.ts`, `AjusteRedondeoTest` y `Sesion12f1BackendMultidestinoTest`.

Además: **`AsignacionOperativa::itemsDelRango()`** (base del Reporte Operativo y del Inicio)
**filtra las líneas por su nombre** (`descripcion_manual = 'Ajuste de redondeo'`).

Para que no se rompa nada:
1. **Convertir antes de quitar.** Un comando de diagnóstico y migración (con `--simular`) convierte cada tour o combo con ajuste a `precio_por_persona`, con precio adulto = precio de catálogo actual (suma + ajuste). El cliente sigue viendo el mismo número.
2. **Las líneas "Ajuste de redondeo" de cotizaciones antiguas se quedan** (2 en `agencia-demo`). **El filtro de `AsignacionOperativa` también se queda**; si no, esas líneas empezarían a salir como servicios en el Reporte Operativo.
3. Se quita del formulario, de la validación y de `desdePlantilla()`. `calcularCombo()` deja de recibirlo.
4. **La columna se elimina en un paso posterior**, después de un ciclo en producción (como en 12g).
5. Los tests de ajuste se reescriben como tests de reparto. No se borran sin reemplazo.

## 7. Datos reales (diagnóstico de solo lectura, `agencia-demo`, 11 y 13-oct-2026)

- 13 `tour_simple` y 1 `paquete_combo`. **0 con `ajuste_redondeo`.**
- **6 tours con `precio_venta_final` cargado que hoy no se usa al cotizar** (D20): Reserva Ecológica Tingana Full Day S/ 280, Tours a Lamas - Half Day S/ 75, Tours Laguna Azul - Full Day S/ 110, Tour Catarata de Ahuashiyacu S/ 60, Tours Full Day Alto Mayo S/ 120, Tours a la Encañada S/ 110.
- Servicios de tours: 7 compartidos y 2 privados, todos en PEN, ningún hotel ni guía como servicio de tour. 2 tours con privados o guía.
- Tarifas: 12 compartido y 5 privado sin hotel; 2 compartido y 7 privado de hotel.
- 1 tour mezcla `tip_afe_igv` distintos.
- 65 líneas de cotización vienen de tours; 2 son "Ajuste de redondeo".
- `SalidaOperativa` no guarda costo (solo guía, cupo y vehículo); `reserva_items` tampoco tiene costo.
- Existe el tipo de proveedor "Operador de turismo" (`ProveedorTipoSeeder`).
- **Pendiente:** correr los mismos diagnósticos en producción (`dakamu`) **antes de la Fase 0** (§11).

## 8. Web (fuera de alcance, para no cerrarle la puerta)

- El tour expone precio adulto, precio niño y moneda (D9).
- "Enlatado" con hotel (D12): producto aparte para la web, con precio por persona **según el tipo de habitación** (por ejemplo, "desde S/ X por persona en habitación doble"). Se diseña en la fase web. En el cotizador, el hotel siempre se agrega aparte.

## 9. Endoso de pasajeros (plan aparte)

Diseño en `plan-endoso-pasajeros.md` (borrador). Lo único que **este** plan tiene que dejar
listo para no bloquearlo:
- `tour_bloque_id` se copia a `reserva_items` (§3.5): el endoso trata "este tour de esta reserva" como una unidad.
- El tour puede necesitar más adelante un **precio neto para agencias** (para recibir endosos). No se agrega ahora, pero las columnas de precio de §3.1 no deben impedirlo.

## 10. Preguntas abiertas

Ninguna pendiente de este plan. Las del endoso viven en su propio plan.

## 11. Fases

**Antes de empezar:** correr en producción (`dakamu`) los diagnósticos de solo lectura:
tarifas por `modalidad`/hotel, tours con tarifas `privado`, tours con `precio_venta_final`,
tours y combos con `ajuste_redondeo`. Las migraciones se aplican con
`php artisan tenants:migrate-verticales` (no `tenants:migrate`).

| Fase | Contenido | Estimado |
|---|---|---|
| 0 | **Tarifas y modalidad del tour (§3.7):** `cobro_por` (con hoteles en `null`) + `capacidad_pax` + `precio_costo_nino` (D25) en `proveedor_tarifas`, `modalidad` en `paquetes_plantilla`, backfills, explosión del tour decidiendo con `cobro_por`, los 3 lugares que crean tarifas, diagnóstico de tours con tarifas `privado`. Pantallas de tarifa y de tour, etiquetas en 4 pantallas. Guía por días del tour (E5, D22). **Test de regresión: toda cotización cargada desde un tour da el mismo resultado que antes** (salvo el guía en tours de varios días, que es la corrección de E5). Va primero porque las demás fases leen `cobro_por`. | 1–1.5 días |
| 1 | Migraciones de §3.1 y §3.3, `RepartoPrecioTourService` (§3.4, con la precisión de §3.2), `desdePlantilla()` con bloque y reparto para `tour_simple`, tour sin servicios con su IGV (D15, D24), cambiar precio del bloque (D21), quitar línea con baja de precio (D10), bloqueo de `pax_incluidos` en líneas del bloque, `recalcularItemsPorPersona()` (R1 + E2), `duplicar()`, `moverBloque()` (E4), `tour_bloque_id` copiado a `reserva_items`, `vehiculo_por_confirmar` y su regla de creación y confirmación (§3.8). Tests: 1/4/5 pax, adulto+niño, infante excluido, costo por grupo con mínimo, centavos, USD↔PEN, IGV mezclado, carga doble del mismo tour, quitar línea, cambiar precio del bloque, duplicar. | 2–2.5 días |
| 2 | Pantalla del tour: estrategia, precios, mínimo para salir, costo referencial e IGV, margen en vivo por persona con la línea de ayuda de costos por grupo, aviso de mínimo mayor que la capacidad. Catálogo con suma calculada para `suma_servicios` (D20) y aviso en el listado (R9). En el cotizador: acción "Cambiar precio del tour" sobre el bloque y alerta de margen. Badge "Vehículo por confirmar" y botón "Confirmar" en la reserva y en el Reporte Operativo (§3.8). Indicador "pax / mínimo" por salida en el Reporte Operativo (D26). | 1.5–2 días |
| 3 | Combos (§3.6, E3), retiro de `ajuste_redondeo` (§6) y diagnóstico de D20: listado por tour del precio cargado contra la suma real, para que el usuario decida la conversión tour por tour. | 1 día |

**Total estimado: 6–7 días.** Cada fase se verifica con tests backend, type-check del
frontend y prueba en vivo con Playwright contra `agencia-demo`, revirtiendo los datos al
terminar.

**Fuera de este plan (fases posteriores, compartidas con el endoso):** Salida Operativa con
varios vehículos y costo real (D18), elección automática del vehículo por capacidad en tours
privados, rentabilidad real por salida.

---

## Anexo A — Ejercicio completo de punta a punta (caso de prueba)

> Ronda 6 (13-oct-2026). Recorrido del flujo planeado con un caso concreto. Los números se
> calcularon a mano con las fórmulas de §3.2 y §3.4 y **deben reproducirse exactos en los
> tests de la Fase 1** (incluidos los centavos). Datos ficticios; los nombres de proveedores
> son de ejemplo.

### A.1 Proveedores y tarifas (una sola vez)

| Proveedor | Servicio | `modalidad` | `cobro_por` | Costo |
|---|---|---|---|---|
| Transportes Selva | Minivan (`capacidad_pax` 10) | compartido | grupo | S/ 300 por día |
| Transportes Selva | Combi (`capacidad_pax` 18) | compartido | grupo | S/ 400 por día |
| Guía Carlos | Guía día local (`guia_tarifas`) | — | (siempre grupo) | S/ 120 por día |
| Restaurante El Mirador | Almuerzo | compartido | persona | S/ 25 |
| Municipalidad | Entrada Laguna Azul | compartido | persona | S/ 10 |
| Asociación de boteros | Paseo en bote | compartido | persona | S/ 15 |

Cada tarifa conserva su tipo de IGV, como hoy. En este ejercicio ninguna tiene costo niño
(D25), así que el niño usa el costo de adulto.

### A.2 Crear el tour

"Laguna Azul Full Day", `modalidad = compartido`, servicios: minivan, guía, almuerzo,
entrada y bote. `pax_minimo_salida = 6`. Estrategia `precio_por_persona`: adulto S/ 150,
niño S/ 130.

| Servicio | Costo por persona |
|---|---|
| Minivan | 300 ÷ 6 = 50.00 |
| Guía | 120 × 1 día ÷ 6 = 20.00 |
| Almuerzo | 25.00 |
| Entrada | 10.00 |
| Bote | 15.00 |
| **Total** | **120.00** |

**Esperado en pantalla:**
- Adulto S/ 150 → margen S/ 30 (25 % sobre costo) ✅
- Niño S/ 130 → margen S/ 10 (8.3 %) ⚠️ por debajo de `margen_minimo_aceptable_pct` (20 %). Avisa, no bloquea.
- Línea de ayuda: "Con 6 pax el transporte y el guía cuestan S/ 70 por persona; con 10 pax, S/ 42".

### A.3 Cotizar (2 adultos, 1 niño de 12, 1 infante de 2)

`A = 2`, `N = 1`. `T = 150 × 2 + 130 × 1 = 430`. El infante paga 0 y no suma costo, pero
aparece en la lista de pasajeros.

| Línea | Costo | Venta repartida |
|---|---|---|
| Minivan (300 × 3 ÷ 6) | 150.00 | 179.17 |
| Guía (120 × 3 ÷ 6) | 60.00 | 71.67 |
| Almuerzo (25 × 3) | 75.00 | 89.58 |
| Entrada (10 × 3) | 30.00 | 35.83 |
| Bote (15 × 3) | 45.00 | 53.75 |
| **Total** | **360.00** | **430.00** |

Margen S/ 70 (19.4 %). Lienzo: "Laguna Azul Full Day — 2 adultos × 150 + 1 niño × 130 = S/ 430".

### A.4 Cambian los pasajeros (3 adultos, 1 niño)

`T = 150 × 3 + 130 = 580`. Costos: 200 / 80 / 100 / 40 / 60 = 480.
Ventas: **241.67 / 96.67 / 120.83 / 48.33 / 72.50 = 580.00**. Margen S/ 100 (20.8 %).
*(Hoy, en cambio, se recalcularía con la venta de las tarifas y el costo quedaría desactualizado: E2.)*

### A.5 Negociación (D21): adulto a S/ 140

`T = 140 × 3 + 130 = 550`. Margen S/ 70 (14.6 %) ⚠️ por debajo del 20 %; avisa, no bloquea.
Ventas: **229.17 / 91.67 / 114.58 / 45.83 / 68.75 = 550.00**.

**Variante, quitar el bote (D10):** su parte es 68.75 ÷ 550 = 12.5 %.
- Precio copiado del bloque: adulto 140 − 17.50 = **122.50**; niño 130 − 16.25 = **113.75**.
- `T = 122.50 × 3 + 113.75 = 481.25`. Las 4 líneas restantes quedan en 229.17 / 91.67 / 114.58 / 45.83 = **481.25**.
- Para un número redondo se usa D21 de nuevo (por ejemplo, adulto S/ 120).

### A.6 PDF al cliente

Total S/ 550, itinerario e "Incluye" (transporte, guía, almuerzo, entrada, paseo en bote).
Sin precio por línea, como hoy. Pasajeros con el infante incluido.

### A.7 Aceptación → reserva

Las líneas pasan con sus precios. La minivan nace con **"Vehículo por confirmar"** (D19,
§3.8). Guía y servicios compartidos se enganchan a la Salida Operativa del día.

### A.8 Operaciones, el día anterior

Salida Laguna Azul del 15-nov: reserva Rojas (4 pax + 1 infante) y reserva Pérez (3 pax).
- Indicador de la salida (D26): **"7 / 6 mínimo"** ✅ (infante aparte).
- Guía Carlos asignado una vez en la salida.
- Rojas recogen en Tarapoto centro → **Confirmar** la minivan sugerida.
- Pérez recogen en Morales → se **reasigna** a otro vehículo (D18).
- Desaparecen los avisos "por confirmar".

Costo real del día: minivan 300 ÷ 7 pax = S/ 42.86 por persona, contra S/ 50 de referencia:
más margen del cotizado. *El sistema todavía no registra este costo real (fase posterior,
compartida con el endoso).*

### A.9 Facturación

Se factura S/ 550 a los Rojas. Líneas agrupadas por categoría y tipo de IGV, como hoy. Sin
cambios.

### A.10 Variante: no llega al mínimo

Solo salen los Rojas (4 pax). Indicador **"4 / 6 mínimo"** ⚠️.
- Costo real de transporte + guía: 300 + 120 = **S/ 420**.
- Cotizado para eso: 4 × 70 = **S/ 280**.

Opciones: salir con S/ 140 menos de lo presupuestado, o endosar (`plan-endoso-pasajeros.md`).

### A.11 Hallazgos del ejercicio

| # | Hallazgo | Resolución |
|---|---|---|
| H1 | Las tarifas tienen un solo costo; al niño se le carga el de adulto | D25 |
| H2 | La Salida Operativa no avisa si no se llega al mínimo | D26 |
| H3 | Quitar un servicio deja precios con decimales (S/ 122.50) | Ya cubierto por D21 |
