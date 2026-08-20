# Módulo 12 — Configuración de códigos y numeración (tours, paquetes, cotizaciones, reservas)

> Sub-plan del módulo 12 (el documento raíz que indexaba el mapa de
> módulos, `plan-general-vertical-agencia-viajes.md`, ya se archivó —
> ver `historial-archivo.md`).
> Referencia de arquitectura base: `arquitectura-multitenant-backend_1.md`
> **Estado: diseñado, todavía sin construir** — no tiene fila propia
> todavía en `plan-hoja-de-ruta-ejecucion.md` (que hoy solo llega hasta
> 11v + 11e/11f/11g/11d/11t). Si se retoma este módulo, agregarle una
> fila ahí primero.

---

## 1. Objetivo

Que cada agencia (tenant) configure, una sola vez por tipo de documento, el
formato del código con el que se identifican sus **tours**, **paquetes**,
**cotizaciones** y **reservas**, con una propuesta por defecto ya armada
pero totalmente editable — y que el sistema genere esos códigos solo al
crear cada registro, sin choques entre usuarios ni saltos/duplicados.

## 2. Alcance

**Entra:**
- Configuración de formato de código por tipo de documento: tour, paquete
  (o combo), cotización, reserva. Diseñado para ser extensible a otros
  tipos que el negocio pida más adelante (ej. órdenes de servicio) sin
  cambiar el modelo.
- Generación atómica del correlativo al guardar cada documento.
- Pantalla de configuración con vista previa en vivo del próximo código.

**No entra (explícitamente aparte):**
- Series de comprobantes SUNAT (factura/boleta). Esas ya tienen su propia
  numeración correlativa regulada por el core de facturación electrónica
  (`tipos_comprobante` y las reglas de Greenter/SUNAT) — no se debe mezclar
  una numeración comercial editable por el usuario con una numeración fiscal
  que tiene reglas propias. Son dos sistemas de numeración distintos que
  conviven sin tocarse.

## 3. Sigla de la agencia + letra por tipo (confirmado con el usuario)

La agencia tiene **una sola sigla** (ej. `DKM`), que se usa igual en los
cuatro tipos de documento. Lo único que cambia entre tipos es la letra que
va adelante:

| Tipo | Letra | Prefijo resultante (sugerido, editable) |
|---|---|---|
| Tour | `T` | `TDKM` |
| Paquete / combo | `P` | `PDKM` |
| Cotización | `C` | `CDKM` |
| Reserva | `R` | `RDKM` |

La sigla (`DKM`) se configura **una sola vez, a nivel de agencia** (en los
datos generales de la empresa/tenant, no repetida en cada tipo). El
prefijo de cada tipo se sugiere por defecto como `{letra_tipo}{sigla}`, y
desde ahí queda 100% editable — si algún día una agencia quisiera romper
el patrón para un tipo puntual, puede.

## 4. Formato por tipo (v3 — 14-ago-2026, incluye reserva)

### 4.1 Tour, paquete y cotización — numeración propia

| Tipo | Prefijo sugerido | ¿Lleva periodo? | Dígitos correlativo | Ejemplo |
|---|---|---|---|---|
| Tour | `TDKM` | No | 4 | `TDKM-0001` |
| Paquete | `PDKM` | No | 4 | `PDKM-0001` |
| Cotización | `CDKM` | Sí (informativo) | 7 | `CDKM-0826-0000001` |

Tour y paquete no llevan periodo — el correlativo es corrido y no se
reinicia, así que el mes/año no aporta nada. Cotización sí muestra el
periodo (foto de cuándo se creó), pero tampoco reinicia su correlativo:
sigue la cuenta igual que un número de factura, sin importar el mes/año en
que se generó cada una.

**Todos arrancan en correlativo 1 el día que se activa la configuración**
— sin empalme con numeración previa (Excel, cuaderno, etc.), confirmado
con el usuario.

### 4.2 Reserva — documento derivado de la cotización (no numeración propia)

Una reserva no es un catálogo que se vende repetidamente como tour o
paquete, ni un documento independiente como la cotización — nace siempre
de una alternativa aceptada dentro de una cotización específica. Por eso
**no tiene su propio contador**: reusa el mismo periodo y el mismo
correlativo de la cotización que le dio origen, solo cambiando la letra
inicial de C a R.

```
Cotización aceptada:  CDKM-0826-0000001
Reserva generada:     RDKM-0826-0000001
```

**Caso a futuro ya contemplado — más de una reserva por cotización.** Hoy
el modelo asume una alternativa aceptada → una reserva, pero si más
adelante el negocio permite dividir un grupo en dos salidas (u otro
escenario que genere más de una reserva desde la misma cotización), el
código no colisiona: la primera reserva sale limpia
(`RDKM-0826-0000001`), y desde la segunda en adelante se agrega un sufijo
numérico corrido, sin padding:

```
1ra reserva de esa cotización:  RDKM-0826-0000001
2da reserva de esa cotización:  RDKM-0826-0000001-2
3ra reserva de esa cotización:  RDKM-0826-0000001-3
```

El sufijo se calcula con un contador propio **por cotización** (no
global), así que nunca compite por el mismo lock que usan tour/paquete/
cotización para su correlativo — ver `cotizaciones.reservas_generadas` en
la sección 6.4. El código, una vez asignado, es inmutable igual que los
demás — si esa segunda reserva se anula, el número `-2` no se reutiliza.

## 5. Punto de vista / consideraciones

- **Por qué reusar el número de la cotización en vez de darle su propia
  secuencia a reserva:** con una secuencia propia (ej. `RDKM-0001`,
  `RDKM-0002`...) cualquiera tendría que abrir la reserva para saber de
  qué cotización salió. Reusando el mismo periodo+correlativo, el número
  cuenta la historia solo con mirarlo — `RDKM-0826-0000001` es
  evidentemente la reserva de `CDKM-0826-0000001`. Es un patrón común en
  sistemas de venta donde un documento nace de otro (cotización → orden,
  orden → factura): mantener la raíz numérica y solo cambiar el prefijo
  según la etapa.
- **El correlativo de tour y paquete tiene que ser corrido para siempre
  (nunca se reinicia).** Al no llevar periodo en el código visible, si el
  correlativo se reiniciara cada mes, en febrero volvería a salir
  `TDKM-0001` repitiendo el de enero. Por eso, cuando un tipo no incluye
  periodo, el sistema **fuerza** `reinicio_correlativo = nunca`.
- **La longitud del correlativo es un mínimo de ceros a la izquierda, no
  un tope.** `0001` con 4 dígitos no significa que el máximo sea `9999` —
  si tour llega al número 10000 se muestra completo (`TDKM-10000`), nunca
  se recorta ni se reinicia.
- **Sigla única + letra por tipo simplifica la configuración inicial.**
  Con una sola sigla de agencia y una letra fija por tipo (T/P/C/R), la
  configuración de los cuatro tipos se arma en un solo paso al activar el
  vertical para un tenant nuevo.
- **El sufijo de reserva no necesita padding.** A diferencia del
  correlativo principal (que sí se rellena con ceros para que se vea
  prolijo en listados: `0001`, `0002`...), el sufijo de reservas múltiples
  es un caso raro — no hace falta que se vea "parejo", así que va como
  número simple (`-2`, `-3`, no `-002`).

## 6. Modelo de datos propuesto

Todo vive en la **base de datos del tenant** (no en la central) — es
configuración comercial propia de cada agencia, no un catálogo compartido
entre tenants.

### 6.1 Sigla de agencia (dato de empresa, no de esta tabla)

Vive en la configuración general de la empresa/tenant (donde ya está razón
social, RUC, etc.), como un campo de texto corto, ej. `sigla_comercial`.
Este módulo solo la **lee** para sugerir el prefijo de cada tipo — no la
duplica ni la vuelve a pedir acá.

### 6.2 `configuracion_codigos` — una fila por tipo de documento

| Campo | Tipo | Notas |
|---|---|---|
| `id` | bigint | |
| `tipo` | string/enum | `tour`, `paquete`, `cotizacion`, `reserva` (abierto a más tipos después) |
| `prefijo` | string corto | Editable, texto libre. Se sugiere por defecto como `{letra_tipo}{sigla_comercial}` (T/P/C/R + sigla de la empresa) al activar el tipo |
| `deriva_de` | string, nullable | `null` para tour/paquete/cotización (tienen numeración propia). `cotizacion` para reserva — indica que este tipo NO usa `codigo_secuencias`, sino que reusa periodo+correlativo del documento padre (ver 6.4) |
| `incluye_periodo` | boolean | default `false` para tour/paquete, `true` para cotización. Sin efecto en tipos con `deriva_de` (heredan el periodo del padre) |
| `formato_periodo` | constante | `MMAA` (ej. `0826`), solo aplica si `incluye_periodo = true`. Constante interna, no opción visible en la UI por ahora |
| `separador` | char(1) | default `-` |
| `longitud_correlativo` | smallint | default `4` para tour/paquete, `7` para cotización. Sin efecto en tipos con `deriva_de` (heredan el correlativo del padre tal cual) |
| `reinicio_correlativo` | enum | `nunca` (default y único valor permitido cuando `incluye_periodo = false`), `mensual`/`anual` disponibles solo si `incluye_periodo = true`. Sin efecto en tipos con `deriva_de` |
| `activo` | boolean | por si se quiere pausar la generación automática de un tipo puntual |
| `updated_by`, `updated_at` | | auditoría — cambiar el formato a mitad de camino afecta cómo se leen los códigos ya emitidos |

**Validación a nivel de backend:** si `incluye_periodo = false`,
`reinicio_correlativo` se guarda siempre como `nunca`. Si `deriva_de` no es
`null`, los campos `incluye_periodo`/`longitud_correlativo`/
`reinicio_correlativo` se ignoran para efectos de generación (solo se
respeta `prefijo` y `separador`).

### 6.3 `codigo_secuencias` — contador de tour/paquete/cotización

Separado de la fila de configuración para que la pantalla de ajustes no
compita por el mismo lock que la generación de códigos en caliente.

| Campo | Tipo | Notas |
|---|---|---|
| `id` | bigint | |
| `tipo` | string | `tour`, `paquete`, `cotizacion` — **no** incluye `reserva`, que no tiene contador propio |
| `periodo` | string, nullable | Solo se usa si `reinicio_correlativo` fuera `mensual`/`anual` en algún tenant futuro. Hoy siempre `null` |
| `ultimo_correlativo` | int | arranca en `0` al activar el tipo, se incrementa de forma atómica (el primer código sale con correlativo `1`) |

Constraint único en (`tipo`, `periodo`). El siguiente correlativo se
obtiene con `SELECT ... FOR UPDATE` dentro de una transacción, nunca con
`MAX(correlativo) FROM tours` — con anulaciones o dos usuarios grabando al
mismo tiempo eso duplica o salta números.

### 6.4 `cotizaciones.reservas_generadas` — contador de reservas por cotización

Campo `int default 0` en la propia tabla `cotizaciones` (ya definida en el
módulo de Cotizaciones/Reservas). Al generar una reserva:

1. `SELECT ... FOR UPDATE` sobre la fila de la cotización padre.
2. `reservas_generadas += 1`.
3. Si el nuevo valor es `1` → código = `RDKM-{periodo cotización}-{correlativo cotización}` (sin sufijo).
4. Si el nuevo valor es `> 1` → se agrega `-{valor}` al final.

No usa `codigo_secuencias` en absoluto — es un contador acotado (0 a un
puñado de reservas por cotización en el peor caso), sin relación con el
correlativo global de ningún tipo.

## 7. Armado del código final

**Tour, paquete, cotización:**
```
{prefijo}{separador}{periodo si incluye_periodo}{separador}{correlativo con padding a longitud_correlativo}
```

**Reserva (derivado):**
```
{prefijo}{separador}{periodo de la cotización padre}{separador}{correlativo de la cotización padre}[{separador}{sufijo si reservas_generadas > 1}]
```

Ejemplos:
- Tour, correlativo 1 → **`TDKM-0001`**
- Paquete, correlativo 1 → **`PDKM-0001`**
- Cotización, agosto 2026, correlativo 1 → **`CDKM-0826-0000001`**
- Primera reserva de esa cotización → **`RDKM-0826-0000001`**
- Si esa misma cotización genera una segunda reserva → **`RDKM-0826-0000001-2`**

## 8. Reglas de negocio

- **El código es inmutable una vez asignado.** Se genera al guardar el
  documento y se graba tal cual — no se recalcula ni se regenera si luego
  cambia la configuración.
- **Generación solo al guardar, no al abrir el formulario** — para no
  "quemar" correlativos (ni el sufijo de reserva) de borradores que nunca
  se llegan a guardar.
- **El correlativo de tour/paquete/cotización es corrido, arranca en 1 y
  no se reinicia.** No hay empalme con numeración previa.
- **Reserva nunca tiene contador propio** — siempre deriva de su
  cotización padre. Si esa cotización cambia de prefijo después, la
  reserva ya emitida no cambia (código inmutable), pero una reserva nueva
  de esa misma cotización sí usaría el prefijo `R` + sigla vigente al
  momento de generarla.
- **Vista previa sin efectos secundarios**, tanto para los tipos con
  contador propio como para reserva (se simula con datos de ejemplo, sin
  tocar `codigo_secuencias` ni `reservas_generadas`).

## 9. Pantalla de configuración

Tour, paquete y cotización: prefijo (texto libre, con el default
precargado), ¿incluir periodo? (on/off — si se desactiva, el selector de
reinicio se oculta y queda fijo en "nunca"), separador, longitud del
correlativo (mínimo de dígitos). Sin campo de "correlativo inicial" — todos
arrancan en 1.

Reserva: solo prefijo y separador editables (no aplica periodo,
correlativo propio ni reinicio, por ser un tipo derivado). Vista previa
del próximo código en tiempo real para los cuatro tipos.

## 10. Pendientes a confirmar con el usuario antes de implementar

- ¿`formato_periodo` (`MMAA`) necesita ser configurable desde ya para
  cotización, o se deja como constante hasta que una agencia real pida
  otro orden (ej. `AAMM`)?
- El diseño de reserva como documento derivado queda propuesto y con buena
  lógica, pero se termina de validar contra el modelo de datos real de
  `reserva` cuando se retome el módulo de Cotizaciones/Reservas (ese
  módulo sigue en maduración — confirmar ahí que el campo
  `reservas_generadas` encaja bien con el resto de la tabla `cotizaciones`
  y que no hay otro caso de negocio que rompa el supuesto de "una reserva
  deriva de una sola cotización").

## 11. Historial

| Fecha | Cambio |
|---|---|
| 14-ago-2026 | Primera versión del módulo: prefijo editable + periodo + correlativo con longitud configurable por tipo, contador separado de la configuración con lock atómico. |
| 14-ago-2026 | v2: se retira el periodo del código de tour y paquete (correlativo corrido, sin mes/año); cotización mantiene el periodo pero solo como dato informativo — su correlativo tampoco se reinicia, sigue la cuenta como un número de factura. Regla: `reinicio_correlativo` se fuerza a `nunca` cuando el tipo no incluye periodo. Longitud del correlativo aclarada como mínimo de padding, no tope. |
| 14-ago-2026 | Confirmado con el usuario: sin empalme con numeración previa — los tres tipos arrancan en correlativo 1 al activarse. Se elimina el campo "correlativo inicial". |
| 14-ago-2026 | Confirmado con el usuario: sigla de agencia única y compartida entre los tres tipos, vive en los datos generales de la empresa; solo cambia la letra inicial por tipo (T/P/C). |
| 14-ago-2026 | v3: se agrega **reserva** como cuarto tipo, diseñado como documento derivado de cotización — reusa periodo+correlativo de la cotización padre, cambia solo la letra (C→R), sin contador propio. Se agrega el caso a futuro de más de una reserva por cotización, resuelto con sufijo numérico corrido (`-2`, `-3`...) basado en un contador `cotizaciones.reservas_generadas`, sin necesidad de tocar el diseño base. Pendiente de validar contra el modelo de datos real cuando se retome el módulo de Cotizaciones/Reservas. |
| 15-ago-2026 | Diseñado originalmente en un Proyecto de Claude aparte del repo; llevado al repo real (`docs/planning/agencia-de-viajes/`) en esta fecha, sin cambios de contenido respecto a la v3. |
