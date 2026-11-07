# Requerimiento — Precio de venta y margen real (Agencia)

| | |
|---|---|
| **Fecha** | 2026-11-05 |
| **Módulo** | AGE |
| **Pedido por** | usuario |
| **Prioridad** | **urgente** en P4 (el margen que se ve no es el real). P1 bajó de prioridad: no hay tours privados (respuesta del 05-nov) |
| **Relacionado** | `agencia-tributario-simplificado.md` (la afectación IGV define el margen real) |

> Borrador vivo para ir puliendo. El estado está solo en `docs/backlog.md`.

## 1. Lo que se necesita

¿Cómo se pone el margen al precio de venta (paquetes, vuelos, hoteles, tours…)? "No puedo poner un
precio así por así." El usuario escuchó de dos fórmulas, markup y margen, y quiere saber qué se usa
en las agencias.

## 2. Análisis

### Conceptos

| | Markup (sobre el costo) | Margen (sobre el precio) |
|---|---|---|
| Fórmula | Precio = Costo × (1 + %) | Precio = Costo ÷ (1 − %) |
| Costo 100, 25 % | 125.00 | 133.33 |

Markup 25 % = margen 20 %. Margen 25 % = markup 33.3 %. "Quiero ganar 20 %" suele querer decir
margen.

**Modelos de precio que usan las agencias:**
1. **Neto + markup:** hoteles, tours, traslados, mayorista con tarifa neta.
2. **Tarifa comisionable:** el proveedor fija el precio al público (PVP) y paga una comisión; la
   comisión no se suma al precio.
3. **Fee fijo:** vuelos, clientes corporativos, cambios.
4. **Costeo por pasajero** para tours propios: fijos del grupo ÷ pasajeros + variables por
   persona. Por eso los operadores publican precios por rango de pasajeros.

Ajustes que se aplican al final: temporada, redondeo, precio de mercado y piso.

En Perú **no existe** un régimen de IGV sobre el margen, como en España: si la agencia revende, el
IGV va sobre todo el precio.

### Lo que hay hoy en el código (verificado el 05-nov-2026)

- **Lo que se llama "margen" es markup en todas partes:**
  - tarifa de proveedor (`proveedores/detalle.vue`, `calcularMargenPct` = (venta − costo) ÷ costo);
  - motor de precios (`PriceEngineService::calcular()`, costo × (1 + %) o + monto fijo);
  - tour (`MargenEnVivo.vue`, "sobre el costo").
- **Dos mínimos distintos:**
  - la tarifa de proveedor pinta rojo por debajo de un **20 % fijo en el código**
    (`proveedores/detalle.vue:434`, `MARGEN_MINIMO_ACEPTABLE_PCT`);
  - el tour usa el mínimo del tour o el de la agencia (`configuracion_agencia.margen_minimo_aceptable_pct`).
- **El IGV no entra en ningún margen**, y el precio de venta incluye el IGV
  (`desglosarPrecioFinal.ts`). Con el mismo "30 %", un servicio gravado gana bastante menos que uno
  exonerado.
- **Modelos que ya existen:**
  - neto + markup (tarifa con markup % o monto fijo, y precio editable que recalcula el %);
  - fee fijo en el pasaje aéreo (`fee_agencia_monto`);
  - temporadas;
  - piso de descuento y margen mínimo (`PriceEngineService::evaluarPiso()`).
- **Lo que no existe:**
  - la tarifa comisionable (en el mayorista se carga costo y venta a mano);
  - el redondeo del precio sugerido;
  - el precio por rango de pasajeros.
- **Tours propios:**
  - Cada servicio del Incluye tiene su `modalidad`: `compartido` (por persona), `privado` (por
    vehículo o grupo), y el guía por día.
  - `TourCostoEstimadoService` calcula el costo por persona así:
    - lo compartido, tal cual;
    - lo privado, **÷ `pax_minimo_salida`**;
    - el guía, costo por día × días ÷ `pax_minimo_salida`.
  - El precio del tour es **fijo por persona** (adulto o niño, nacional o extranjero, por
    temporada).
- **El hueco:** en la cotización, `TourLineaService::calcular()` toma el costo como **costo por
  persona × pasajeros** (`TourLineaService.php:333`). Los fijos ya vienen divididos entre el
  mínimo, así que con un grupo de otro tamaño el costo sale mal. Ejemplo: movilidad 300 y guía 150
  (fijos), 45 por persona variable, mínimo 4, precio 205 por persona:

  | Pax | Cobra | Costo en pantalla | Costo real | Ganancia que se ve | Ganancia real |
  |---|---|---|---|---|---|
  | 2 | 410 | 315 | 540 | +95 | **−130** |
  | 4 | 820 | 630 | 630 | +190 | +190 |
  | 8 | 1,640 | 1,260 | 810 (un solo vehículo) | +380 | +830 |

  - **Tour compartido** (la salida junta varias reservas): no es grave, porque los fijos se
    reparten en la salida y el Inicio avisa "bajo mínimo" (`DashboardService`).
  - **Tour privado** (el grupo va solo, `paquetes_plantilla.modalidad = 'privado'`, no se engancha
    a una salida, `SalidaEngancheService:54`): **puede vender a pérdida mientras la pantalla
    muestra ganancia.**
- **Otros:**
  - el guía en el cotizador cuenta 1 día (error **E5**, anotado en `TourCostoEstimadoService`);
  - en el modo clásico, el costo cuenta a los infantes (**H19**, en T-029).
  - La capacidad del vehículo solo existe en las salidas operativas (`vehiculos.capacidad`), no en
    el costo del tour.

### Propuesta (borrador)

| # | Qué | Detalle |
|---|---|---|
| P1 (si aparece un tour privado) | **Tour privado con el costo real del grupo** | En la cotización (y al recalcular por cambio de pasajeros), costo = **Σ fijos del grupo + Σ variables × pasajeros reales**, y no costo por persona × pasajeros. Hay que guardar en el tour los fijos y los variables por separado (hoy solo queda la suma por persona). Margen en vivo con ese costo; si el grupo está debajo del mínimo, o la ganancia es negativa, aviso en rojo ("con 2 pasajeros pierdes S/ 130"). Los tours compartidos no cambian. |
| P2 | **Precio por rango de pasajeros** (tours privados) | Tabla opcional en el catálogo ("2 pax · 3-4 · 5-6 · 7+") por temporada. El cotizador toma el precio del rango según los pasajeros. Sin tabla, el precio por persona de hoy. |
| P3 | **Capacidad del vehículo** | Si los pasajeros superan la capacidad, el costo suma otro vehículo. Requiere la capacidad en la tarifa privada. |
| **P4** (urgente) | **Margen honesto en todas las pantallas** | Llamar "Markup %" a lo que hoy dice "Margen". Mostrar siempre `Markup X % · Margen Y % · Ganancia S/ Z`. **Restar el IGV** cuando el servicio es gravado (precio ÷ 1.18; costo sin IGV si el proveedor factura con IGV, a confirmar con el contador). **Un solo mínimo** (el de Configuración, o el del tour si tiene) en la tarifa, el tour y el cotizador; fuera el 20 % fijo. |
| P5 | **Porcentajes por defecto por tipo** | En Configuración: markup por defecto para hotel, tour, mayorista y manual, y fee por defecto para vuelos. La tarifa nace con el precio sugerido. |
| P6 | **Tarifa comisionable** (mayorista y hoteles) | En el mayorista o la tarifa: elegir "neto + markup" o "PVP + comisión %". Con comisión: venta = PVP, costo = PVP − comisión. |
| P7 | **Redondeo** | Redondear el precio sugerido a 0, 5 o 10 (configurable). |
| P8 | **Ganancia por salida operativa** (tours compartidos) | Es donde un compartido con pocos pasajeros pierde plata. Tiene dos niveles. **(a) Estimada, ahora:** en la salida y en el Inicio, junto a "bajo mínimo": lo cobrado a todas las reservas de la salida − fijos de las tarifas (un costo de vehículo por cada `salida_vehiculos`, el guía por día) − variables × pasajeros. Hoy la salida guarda vehículos (con su capacidad), servicios y guía, pero ningún monto. **(b) Real, con Compras:** ya está diseñada en `plan-tours-venta-simple-costo-real.md` ("Medir lo real", N9, fase 3), con las facturas reales asignadas a la salida. (a) usa la misma pantalla que después llenará (b): no construir un reporte aparte. |
| P9 | **"Mínimo para salir" obligatorio** cuando hace falta | Al guardar un tour que tiene servicios por grupo o guía, pedir `pax_minimo_salida`. Sin él, la calculadora no sugiere el costo y queda escrito a mano. |

### Preguntas abiertas

1. **¿Margen o markup como número principal?** *Recomendado:* se escribe el markup (es más cómodo
   para fijar precios) y se muestran los dos.
2. **Costo de un proveedor gravado:** ¿sin IGV (porque se recupera como crédito fiscal) o con IGV?
   Depende de si la venta es gravada o exonerada. Lo confirma el contador: es la **C14** de
   `agencia-tributario-simplificado.md` §2.8. **07-nov, casi respondida:** como casi todo se vende
   exonerado (D-AGE-035), el IGV que cobra el proveedor no se recupera y el costo es **con IGV**
   (lo dijo el usuario para el pasaje aéreo). Falta que el contador lo confirme. Con eso, P4b
   (restar el IGV) solo afecta a lo gravado, que hoy casi no existe.
3. **Tour privado por debajo del mínimo:** ¿solo avisar, o bloquear la cotización?
   *Recomendado:* avisar en rojo, sin bloquear (se puede vender caro a propósito).
4. **¿Qué tours son privados de verdad?** Revisar en el catálogo de la agencia cuántos tienen
   `modalidad = 'privado'` y cuántos se venden así aunque estén como compartidos.
5. **Mayoristas:** ¿cuáles venden con tarifa neta y cuáles con PVP + comisión?
6. **Redondeo:** ¿a cuánto?
7. ~~¿Se juntan pasajeros de distintas reservas en el mismo vehículo?~~ Respondida: sí (ver
   abajo).

### Respuestas del usuario (05-nov-2026)

- **Pregunta 3:** solo avisar, sin bloquear.
- **Pregunta 4:** la agencia no tiene tours privados. Los datos locales, revisados el mismo día en
  `agencia-demo` (la agencia real está en producción), dicen lo mismo: 13 tours, **todos
  compartidos**, y **12 sin `pax_minimo_salida`**. En el Incluye hay 10 servicios por persona,
  1 por grupo y 4 sin tarifa. Por eso P1 deja de ser urgente, el riesgo pasa a la salida (P8) y
  aparece P9.
- **Pregunta 7:** **sí, se juntan** pasajeros de distintas reservas en el mismo vehículo ("no voy
  a operar un vehículo solo para esos 2"). Los tours son compartidos de verdad: el control de la
  ganancia va en la salida (P8), y P1 queda solo para el día que haya tours privados.

### Riesgos

- **Cotizaciones y reservas que ya existen:** P1 cambia el costo mostrado y el margen, pero no el
  precio cobrado. No recalcular a la fuerza lo que ya fue aceptado. Mostrar el costo real solo en
  lo nuevo o al recalcular.
- **Datos:** P1 necesita los fijos y variables por separado en los tours que ya existen. Se pueden
  reconstruir desde el Incluye (cada ítem conoce su `modalidad`). Si el costo del tour se escribió
  a mano, no se puede separar: tratarlo como hoy y avisar.
- **P4 toca muchas pantallas** (tarifa, tour, cotizador, mayorista): conviene un solo componente de
  margen (`MargenEnVivo` ampliado) y un solo cálculo en el backend.
- **Dinero:** cálculo en centavos y un solo punto de redondeo (checklist de `proceso.md` §4).

### Orden sugerido

**P4** (urgente) → P9 → P8 → P5 → P6 → P7 → (si aparece un tour privado: P1 → P2 → P3).

## 3. Resultado

- **Decisiones:**
- **Tareas (05-nov-2026):** T-087 (P4 sin IGV), T-088 (P9), T-089 (P8 a, depende de T-088).
  Siguen como borrador: P4b (IGV, espera al contador), P1/P2/P3 (tours privados), P5, P6, P7.
