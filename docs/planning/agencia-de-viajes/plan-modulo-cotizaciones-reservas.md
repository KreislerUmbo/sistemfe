# Sub-plan — Módulo Cotizaciones / Alternativas / Reservas / Itinerarios

> Parte de: `plan-general-vertical-agencia-viajes.md` — Fase 1
> Estado: en definición (aún se están sumando casos de negocio)
> Última actualización: 28-jul-2026 (diseño UX del cotizador + motor de precios)

---

## 1. Principio central del módulo

**No existen dos tipos de venta ("empaquetado" vs "personalizado") a nivel de
datos.** Todo se modela como una lista de servicios atómicos (hotel,
transporte, restaurante, tour, vuelo, otros), cada uno con su proveedor y su
precio. Un **paquete** es una plantilla que agrupa varios de esos servicios
con precio ya fijado; una cotización **personalizada** es armar esos mismos
servicios a la carta. Un solo motor de cálculo para ambos casos — nada obliga
a un mínimo de ítems: una alternativa puede tener desde 1 solo servicio
suelto (solo un boleto, solo una noche de hotel, solo un tour/trekking) hasta
varios combinados.

Caso real que originó esta decisión: tour a Lamas empaquetado con un
proveedor (S/100, incluye movilidad + guía + alimentación + seguro) vs. el
mismo tour armado a medida donde el cliente saca alimentación o seguro.

---

## 2. Proveedores y tarifas

### 2.1 Clasificación de proveedores (actualizado 24-jul-2026 — ver `plan-modulo-proveedores.md`)
- Catálogo formal, no lista libre en texto: `proveedor_tipos` (central,
  compartido por todo el rubro agencias de viajes — ej. Hotel, Transporte,
  Restaurante, Mayorista, Otros), con trait `CentralConnection`. Cada
  tenant controla cuáles usa vía `proveedor_tipos_config` (habilitado
  default true, sembrado al provisionar) — deshabilitar un tipo solo
  oculta la opción al crear proveedores nuevos, nunca afecta a los que ya
  existen de ese tipo.
- `proveedores.tipo_id` → FK al catálogo central.
- Ubicados en distintas regiones del país (no solo Tarapoto — la agencia
  opera desde Tarapoto pero vende paquetes/tours a otros destinos sin
  restricción).
- **Se elimina el campo binario `tipo_proveedor: regular | mayorista`.**
  El comportamiento que dependía de él (canal de envío de vouchers,
  sección 5.5; disparador del flujo de paquetes internacionales, sección
  2.4) ahora se deriva de `proveedor_tipo.slug == 'mayorista'` — no se
  duplica la clasificación en dos campos distintos.
- **Guías turísticos quedan fuera de este catálogo.** Siguen modelados
  como tabla propia `guias` (sección 5.3), no como un tipo más de
  proveedor — decisión confirmada 24-jul-2026, revierte lo que se había
  propuesto inicialmente en el sub-plan de Proveedores, porque `guias` ya
  está construida y conectada a `reserva_items.guia_id`; migrar tenía
  costo real sin beneficio claro.

### 2.2 Tarifas por proveedor
Un proveedor **no tiene un solo precio de costo** — maneja varias tarifas
según el tipo de trato:

```
proveedor_tarifas
 - proveedor_id
 - tipo_tarifa: corporativa | grupal | publica
     (la pública se registra solo como referencia, no es el costo real
     de la agencia)
 - modalidad: compartido | privado
     (aplica sobre todo a transporte: mismo proveedor, precio distinto
     si el pasajero comparte vehículo con otros o va solo)
 - moneda: PEN | USD
     (la mayoría de proveedores locales cotizan en soles, pero algunos
     — ej. boletos aéreos comprados localmente — se manejan en dólares.
     La moneda es un atributo del proveedor/tarifa, no una regla fija
     "local = soles, internacional = dólares")
 - diferenciador: JSON flexible — varía según tipo de proveedor
     (tipo de distancia para transporte, tipo de menú para restaurante).
     Flexible a propósito para no alterar el esquema cada vez que aparece
     un diferenciador nuevo SIN catálogo fijo.
 - tipo_habitacion: matrimonial | doble | triple | familiar   (NUEVO
     28-jul-2026 — RETROFIT sobre tabla ya mergeada en Sesión 5. Antes
     vivía dentro de `diferenciador` como texto libre; se promueve a
     columna explícita, nullable, solo aplica cuando el proveedor es
     tipo Hotel. Motivo: `opciones_hotel_tarifas` (§2.4, paquetes/
     mayoristas) YA usa este mismo enum como columna real — tener dos
     formas distintas de guardar "tipo de habitación" según de dónde
     viene el ítem le impide al motor de precios (§2.5) tratar "Hotel"
     de forma uniforme sin importar el origen. Requiere su propia
     migración ALTER TABLE + backfill de lo ya cargado en
     `diferenciador` desde Sesión 5 — no se hace pasar como parte del
     diseño original, es un hallazgo posterior)
 - precio_costo
 - margen_tipo: porcentaje | fijo
 - margen_valor
 - descuento_maximo_pct
     (% máx. que un vendedor puede descontar del precio de venta al cotizar
     — agregado 24-jul-2026, ver plan-modulo-proveedores.md)
 - margen_minimo_pct
     (% mín. de utilidad que siempre debe quedar sobre precio_costo,
     protegido aunque el vendedor aplique descuento_maximo_pct — gana la
     regla más restrictiva entre ambos pisos, agregado 24-jul-2026)
 - precio_venta_adulto   (calculado por defecto, editable al cotizar)
 - precio_venta_nino
 - precio_venta_infante
     (sobre todo relevante en aéreo — el infante tiene tarifa propia,
     normalmente mucho menor a la de niño, no la misma)
 - edad_min_nino / edad_max_nino
     (el corte de qué edad cuenta como "niño" VARÍA POR PROVEEDOR/SERVICIO
     — sobre todo en hoteles — no hay una regla única de la agencia)
 - edad_max_infante
     (editable por proveedor, default = configuracion_agencia.edad_max_infante)
 - temporada_id
     (actualizado 24-jul-2026 — reemplaza fecha_inicio_vigencia/
     fecha_fin_vigencia sueltas. Nullable: null = tarifa regular/todo el
     año. FK a catálogo reutilizable `temporadas` + `temporada_ocurrencias`,
     ver plan-modulo-proveedores.md sección 2.6 — evita repetir fechas a
     mano por cada proveedor cada año y permite agrupar reportes por
     temporada, ej. "ventas en Fiestas Patrias")
 - vigente_desde / vigente_hasta
     (versionado del registro en sí — nunca se sobrescribe un precio ya
     usado en una cotización, se cierra la fila y se crea una nueva)
 - tip_afe_igv: '10' gravado | '20' exonerado | '30' inafecto | '40' exportación
     (mismo catálogo que ya usa Sale/SaleDetail para Greenter — se reutiliza,
     no se inventa uno nuevo; ver sección 6.4)
 - destino_tributario: amazonia | nacional | extranjero
     (determina si aplica la exoneración Ley 27037; coincide con
     Sale.destino del core)
```

```
temporadas (central, compartido por todo el rubro agencias de viajes)
 - id
 - nombre        -- "Temporada Alta", "Fiestas Patrias", "Navidad y Año Nuevo"
 - tipo          -- fija (mismo rango cada año) | móvil (varía por año)

temporada_ocurrencias (ocurrencia concreta por año calendario)
 - id
 - temporada_id
 - anio
 - fecha_desde
 - fecha_hasta
```

Al cotizar, el sistema cruza la fecha del servicio contra
`temporada_ocurrencias` para resolver automáticamente qué tarifa aplica —
el vendedor no elige la tarifa a mano. Para cargar una temporada nueva
cada año solo se crea una `temporada_ocurrencia` (ej. Fiestas Patrias
2027) y todos los proveedores que ya tienen tarifa para esa `temporada_id`
quedan ligados sin volver a escribir fechas por proveedor.

**Importante:** `tip_afe_igv` y `destino_tributario` son datos que alguien
debe confirmar con el contador de la agencia al dar de alta cada proveedor —
Claude no puede determinar por sí solo qué proveedores califican realmente
como exonerados Amazonía o qué tratamiento aplica a cada caso.

### 2.3 Destino y proveedores (actualizado 24-jul-2026 — ver `plan-modulo-proveedores.md`)
**Se reemplaza la decisión anterior** ("filtro sugerido, no bloqueante").
Caso real que la hizo insuficiente: un proveedor de transporte cobra
distinto según destino (por distancia u otros factores) — ej.
"Transportes San Martín" cobra S/50 el traslado a Lamas pero S/200 a
Moyobamba. Dejarlo como texto libre en el `diferenciador` (JSON) permite
error humano al cargar/cotizar.

Se modela con tabla intermedia entre proveedor y tarifa:
```
proveedor_servicios
  id
  proveedor_id
  destino_servicio_id   → FK a destinos_atractivos/destino_servicio (5.2)

proveedor_tarifas
  proveedor_servicio_id   → reemplaza la referencia directa a proveedor_id
  ... (resto de campos sin cambio, sección 2.2)
```
Un mismo proveedor puede tener varias filas en `proveedor_servicios`, una
por destino/servicio que ofrece, cada una con sus propias
`proveedor_tarifas`. Al cotizar, el sistema filtra automáticamente las
tarifas disponibles de ese proveedor **para el destino de la cotización**
— ya no es posible aplicar por error el precio de un destino a otro,
porque son filas estructuralmente distintas.

La agencia sigue operando desde Tarapoto pero vendiendo a cualquier
destino del país — eso no cambia; lo que cambia es que ahora el precio
puede variar de forma estructural según destino, no solo la
disponibilidad del proveedor.

### 2.4 Paquetes internacionales (proveedores mayoristas)
Caso distinto y con flujo propio. Pasajes aéreos + hotelería internacional,
con o sin fechas programadas. El precio no es estático (varía por
temporada/fecha), así que no se cotiza con tarifa fija en el sistema.

**Flujo real de compra confirmado (actualizado 25-jul-2026 con detalle del
proceso manual actual):**
```
1. Prospecto pide paquete internacional (ej. Punta Cana)
2. Agencia recopila: fechas, # pax, tipo (adulto/niño/infante),
   nivel de servicio deseado (todo incluido vs. solo hotel+traslados+
   actividades)
3. Agencia consulta a VARIOS proveedores mayoristas (ej. Nuevo Mundo
   Viajes, Viajes Falabella, Inter-agencias) — cada mayorista tiene su
   propio sistema donde la agencia cotiza como aliado
4. Cada mayorista devuelve una cotización con VARIAS opciones de hotel,
   cada una con precio por tipo de habitación (matrimonial/doble/triple)
   — no es un solo número, es una matriz (ver más abajo, confirmado con
   documentos reales de la agencia)
5. HOY (proceso manual que este sistema busca agilizar): la agencia lleva
   esos números a un Excel aparte para cuadrar costo + margen/fee +
   impuestos + precio final, y luego arma un Word con el resumen de
   precios, opciones de hotel e itinerario para el cliente — este paso es
   el que más demora al vendedor
6. Agencia compara entre mayoristas, elige la mejor opción de costo
7. Agencia arma SU cotización final (aplica margen) y se la pasa al cliente
8. Cliente acepta
9. RECIÉN AHÍ la agencia pide un adelanto al cliente y reserva/paga
   adelanto o espera factura final a la mayorista elegida
```
La compra/reserva real con la mayorista ocurre **después** de la
aceptación del cliente — mismo disparador que la creación de `reserva`
(sección 4).

**Objetivo del sistema respecto al paso 5:** que el vendedor cargue los
números que le da el mayorista directamente en la estructura de abajo, y
el sistema calcule margen/fee/precio final automáticamente (con el default
del mayorista, editable si hace falta) y genere el PDF de cotización
directo — sin pasar por Excel ni Word aparte.

**Comparación entre mayoristas (header — registro liviano por mayorista consultado):**
```
opcion_mayorista
 - alternativa_id
 - proveedor_id             (proveedor tipo "mayorista")
 - salida_mayorista_id       ← nullable, ver más abajo
 - moneda: PEN | USD         (casi siempre USD, pero queda como campo)
 - incluye                    (texto corto o checklist: traslados,
                                seguro, guía — lo que va en TODAS las
                                opciones de hotel de este mayorista)
 - notas                      (libre, ej. "vuelo con escala en Bogotá")
 - vuelo_aerolinea
 - vuelo_detalle              (texto: tramos ida/vuelta, fechas, horas,
                                equipaje permitido — informativo, no
                                estructurado en columnas porque cada
                                cotización trae un formato distinto del
                                mayorista)
 - estado: candidata | elegida | descartada
```

**Actualizado 25-jul-2026 — matriz de precio por hotel × tipo de
habitación (validado con documentos reales: Panamá, Cusco, Alto Mayo —
las 3 categorías de paquete usan la misma estructura, no es exclusivo de
internacional):**
```
opciones_hotel   (aplica tanto a opcion_mayorista como a paquetes_plantilla,
                   ver sección 3.7 — misma estructura, un solo motor)
 - id
 - opcion_mayorista_id   ← nullable
 - paquete_plantilla_id   ← nullable (uno de los dos, no ambos)
 - proveedor_id            ← nullable, si el hotel ya existe como
                              proveedor registrado (más común en local/
                              nacional); en internacional casi siempre
                              queda null porque es un hotel gestionado
                              por el mayorista, sin tarifa propia en el
                              sistema
 - nombre_hotel
 - categoria_estrellas

opciones_hotel_tarifas
 - opcion_hotel_id
 - tipo_habitacion: matrimonial | doble | triple | familiar
 - precio_costo            (lo que da el mayorista/proveedor)
 - precio_venta            (calculado con el margen del mayorista,
                             editable — ver regla de margen abajo)
```

**Margen automático por mayorista (confirmado 25-jul-2026):**
```
proveedores (campos nuevos, aplica sobre todo a tipo "mayorista")
 - margen_default_tipo: porcentaje | fijo
 - margen_default_valor
```
Al cargar los precios de costo de una opción de hotel, el sistema calcula
`precio_venta` automáticamente con el margen default de ese mayorista —
el vendedor puede editarlo línea por línea si la negociación puntual fue
distinta, sin perder el default para la próxima vez.

**Tours opcionales (confirmado 25-jul-2026 — distinto de `items_incluidos`,
que sí va en el precio base):**
```
opcion_mayorista_opcionales
 - opcion_mayorista_id
 - nombre                   (ej. "Excursión a San Blas")
 - precio_por_persona
 - moneda
 - incluye / no_incluye     (texto)
```
Se muestran en el PDF como actividades que el cliente puede agregar,
separadas del precio del paquete base — nunca se suman automáticamente al
total salvo que el cliente las pida explícitamente.

Cuando se marca `elegida`, la opción de hotel elegida (con su tipo de
habitación) se convierte en el ítem internacional de la alternativa del
cliente (con el margen de la agencia ya aplicado). Las demás quedan
`descartada` pero visibles, como historial de por qué se eligió esa
mayorista.

**Salidas de catálogo del mayorista (paquetes armados en fechas fijas):**
A veces la mayorista no cotiza a medida, sino que ofrece un paquete ya
armado para fechas específicas (precio y cupo referencial), disponible
para cualquier cliente que pregunte por esas fechas — es más parecido a
un catálogo que a algo propio de una cotización puntual.
```
salidas_mayorista   (catálogo — independiente de cualquier cotización)
 - proveedor_id         (la mayorista)
 - nombre                (ej. "Punta Cana Todo Incluido - Diciembre")
 - fecha_salida / fecha_retorno
 - cupo_total            (referencial, informado por la mayorista)
 - cupo_ocupado          (control interno — sube cuando una opcion_mayorista
                           de alguna alternativa ACEPTADA se vincula acá;
                           solo informativo, no bloquea vender de más si
                           se negocia ampliar con la mayorista)
 - precio_costo, moneda, incluye
 - estado: disponible | agotado | cancelado
```

**Sigue abierto:** el detalle de gestión de proveedores a fondo (altas,
bajas, negociación de tarifas) es un módulo aparte, no abordado todavía.

### 2.5 Motor de precios y pasajes aéreos sueltos (NUEVO 28-jul-2026)

**Motor de precios único, no lógica de margen duplicada por controller.**
Confirmado al diseñar la Sesión 11a: el cálculo de margen (`margen_tipo`/
`margen_valor`, piso por `descuento_maximo_pct`/`margen_minimo_pct`) no
debe vivir repetido en cada controller (`ProveedorTarifaController`,
`OpcionMayoristaController`, y ahora el de pasajes aéreos) — se extrae a
un servicio único:
```
PriceEngineService::calcular(costoBase, cargos[], margenTipo, margenValor, pisos)
 → { ventaBase, ventaTotal, desglose[], margenAplicado, alertaPiso }
```
Un solo lugar decide cómo se calcula el margen y dónde se dispara la
alerta de piso (§3.1) — evita que el cálculo quede "parecido pero no
igual" entre tipos de servicio.

**Caso distinto: vender un pasaje aéreo SUELTO (no como parte de un
paquete internacional con mayorista).** Confirmado 28-jul-2026 probando
el prototipo del cotizador: esto NO pasa por `opcion_mayorista` (esa
sección es para paquetes armados con matriz de hotel) ni por
`proveedor_tarifas` corriente (esa versiona tarifas vigentes por
temporada, un pasaje aéreo caduca en horas, no en meses). Necesita tabla
propia:

```
cotizacion_pasaje_aereo   (1-a-1 con el alternativa_item de tipo pasaje
                            aéreo — es cotización puntual, no catálogo,
                            mismo criterio que opcion_mayorista)
 - alternativa_item_id
 - aerolinea                  (texto libre, DECIDIDO 28-jul-2026 — mismo
                                 criterio que `vuelo_aerolinea` en
                                 `opcion_mayorista`/`paquetes_plantilla`,
                                 ver justificación abajo)
 - itinerario                  (texto libre: tramos ida/vuelta, fechas,
                                  horas — mismo criterio que vuelo_detalle
                                  ya usado en paquetes_plantilla/
                                  opcion_mayorista, cada aerolínea/GDS
                                  entrega un formato distinto)
 - moneda: PEN | USD
 - tarifa_base_adulto / tarifa_base_nino / tarifa_base_infante
                                (costo, antes de impuestos)
 - cargos: JSON [{codigo, nombre, monto, tipo: impuesto|tasa_aeropuerto|
                    fee_agencia}]
     (flexible a propósito, mismo patrón que proveedor_tarifas.
     diferenciador. Validado con normativa real: en Perú existen TUUA
     nacional, TUUA internacional, TUUA de transferencia — todas
     distintas y cambiantes — y el MTC aprobó regulación en 2026 que
     obliga a las aerolíneas a desglosar estos cargos al pasajero, así
     que el campo espeja lo que la propia aerolínea entrega, no lo
     reinterpreta con una lista fija que se desactualiza)
 - tua_incluida_en_tarifa: boolean   (a veces viene incluida en el precio
     mostrado, a veces se cobra aparte — se pregunta explícito, no se
     asume)
 - fee_agencia_monto           (lo único que la agencia realmente vende
     como servicio propio — tarifa + impuestos son traslado de costo de
     terceros)
 - tip_afe_igv                  (aplica SOLO a fee_agencia — confirmar
     con el contador si el pass-through de tarifa+impuestos es ingreso
     gravable propio de la agencia; el tratamiento real depende además
     del ORIGEN del vuelo, no del destino — dato de la ruta cotizada,
     no algo fijo por aerolínea)
 - fecha_cotizado                (a diferencia de proveedor_tarifas, NO
     hay vigente_desde/vigente_hasta de largo plazo acá — snapshot de
     cuándo se consultó, no un rango de vigencia)
 - costo_total / precio_venta_total   (calculados por PriceEngineService,
     editables — mismo patrón de edición en vivo que alternativa_items)
```

**Decidido 28-jul-2026 — `aerolinea` queda como texto libre**, no FK.
Precedente encontrado en el propio modelo: en `opcion_mayorista` (§2.4)
el proveedor con FK real es el **mayorista** (`proveedor_id`, tipo
"mayorista") — la aerolínea (`vuelo_aerolinea`) ya es solo texto
informativo ahí, mismo criterio en `paquetes_plantilla`. Confirmado con
el usuario: la agencia no es agencia IATA (sin acreditación directa con
aerolíneas), así que la aerolínea es un dato del vuelo, no una relación
comercial que la agencia gestione — no hay nada que reportar por
aerolínea (comisión, volumen) porque no hay contrato directo. FK habría
acoplado Sesión 11b a que Sesión 11a esté no solo construida sino
poblada con un catálogo de aerolíneas antes de poder cotizar un pasaje,
sin ningún beneficio real a cambio. Si la agencia consigue acreditación
IATA en el futuro, se promueve a FK igual que se hizo con
`tipo_habitacion` (§2.2) — no antes de que haga falta.

---

## 3. Cotización — estructura de alternativas

### 3.1 Por qué "alternativas" y no cotizaciones separadas
Caso real (Mario pide paquete a Tarapoto): la agencia arma varias opciones
completas de precio distinto para el mismo cliente/pasajeros — ej.
Alternativa A (Hotel Río Cumbaza + Restaurante La Patarashca + tour a Lamas
= S/700), Alternativa B (Hotel Río Sol + Restaurante Rocoto = S/500),
Alternativa C (Hotel Río Cumbaza + vuelo Star Perú = S/1200), etc. — sin
registrar al mismo pasajero/cliente varias veces.

**Decisión de diseño confirmada:** las alternativas son por **combinación
completa** (paquete entero por alternativa), no por servicio individual
dentro de una misma alternativa. Máximo 5 alternativas por cotización.

```
cotizaciones (header)
 - codigo_prefijo   (NUEVO 25-jul-2026 — libre, ingresado por el vendedor,
                      ej. "PDKM-CZ"; sin catálogo forzado)
 - codigo            (NUEVO — calculado: {codigo_prefijo}-{año}-{correlativo},
                       ej. "PDKM-CZ-2026-001". Correlativo propio POR
                       PREFIJO, no global de la agencia — permite ver de
                       un vistazo "vamos en la cotización #3 de este
                       paquete". Único por tenant, es lo que el vendedor
                       usa para ubicar la cotización sin memorizar IDs
                       internos)
 - cliente_id, destino, fecha_viaje_desde, fecha_viaje_hasta (ambas
   nullable — RETROFIT 28-jul-2026: reemplaza a fecha_viaje_tentativa,
   una sola fecha no alcanzaba para cotizar con fecha de ida y vuelta)
 - (NO se repite en cada alternativa)

cotizacion_pasajeros
 - cotizacion_id
 - tipo_pax: adulto | niño | infante   (sugerido automáticamente desde la
     edad, usando la clasificación general de la agencia)
 - edad (OBLIGATORIA — ver más abajo por qué)
 - en esta etapa el pasajero es básicamente un conteo por tipo, para
   poder calcular precio — nombre/documento completos NO son necesarios
   todavía (nadie llena eso para 4 alternativas que capaz no se concretan)
```

```
configuracion_agencia
 - edad_max_infante   (default 2, configurable)
 - edad_max_nino       (default 12, configurable)
```
Clasificación general: Infante 0-2 años, Niño 2-12 años, Adulto 12+. Sirve
como default al crear una tarifa de proveedor nueva y para sugerir
`tipo_pax` automáticamente. **La edad es obligatoria** (no opcional como
en la primera versión de este documento) porque es lo único que permite
calcular automáticamente qué precio (adulto/niño/infante) le toca a cada
pasajero según el corte específico de **cada proveedor** al armar
`alternativa_items` — un corte que puede ser distinto al general de la
agencia (sobre todo en hoteles, donde "depende del hotel"). `tipo_pax` se
deriva de la edad, pero ya no es la fuente de verdad para el precio.

```
alternativas
 - cotizacion_id
 - nombre ("Alternativa A", editable)
 - estado: borrador | enviada | aceptada | descartada
 - moneda_cotizacion: PEN | USD    (en qué moneda se presenta al cliente
                                     — ver sección 3.4)
 - tipo_cambio_aplicado             (snapshot del valor usado al cotizar)
 - tipo_cambio_origen: dia | agencia
 - fecha_envio                      (se llena al pasar a estado 'enviada')
 - fecha_vencimiento                (= fecha_envio + dias_vigencia_cotizacion,
                                      configurable — sección 3.5)
 - descuento_global_pct             (actualizado 24-jul-2026 — opcional;
                                      al aplicarse, se reparte a cada
                                      alternativa_items respetando el piso
                                      individual de cada uno; si alguna
                                      línea no lo permite, se avisa cuál en
                                      vez de bloquear todo en silencio)
 - total (calculado)

alternativa_items
 - alternativa_id
 - origen_tipo: proveedor | mayorista | pasaje_aereo | manual   (NUEVO
     28-jul-2026 — RETROFIT sobre tabla ya mergeada en Sesión 7, junto
     con `cantidad`, misma migración. Antes había que INFERIR el origen
     del ítem según qué FK nullable estaba llena (proveedor_tarifa_id /
     opcion_mayorista_id) — frágil apenas se agregó un tercer origen
     (`cotizacion_pasaje_aereo`, §2.5) y ahora un cuarto (manual, ver
     abajo). Discriminador explícito, no inferido. Backfill de las filas
     ya cargadas en tenants de prueba: proveedor_tarifa_id lleno →
     'proveedor', opcion_mayorista_id lleno → 'mayorista' — seguro
     porque son solo tenants descartables, sin datos reales todavía)
 - proveedor_tarifa_id      ← NULLABLE. Dos casos, no solo uno: (1) ítems
                               internacionales/manuales que no vienen de
                               una tarifa registrada (mayorista/manual,
                               ver `origen_tipo` arriba); (2) NUEVO
                               28-jul-2026 — actividades/servicios
                               LOCALES con `origen_tipo='proveedor'`
                               cotizados con un precio de REFERENCIA
                               (de cualquier tarifa vigente para ese
                               `destino_servicio`, marcado como
                               referencial) porque todavía no se decidió
                               qué proveedor específico va a operar —
                               eso se resuelve después en
                               `reserva_items.proveedor_tarifa_id` (ver
                               sección 4), no acá. Caso real confirmado
                               por el usuario, mismo criterio que ya
                               existía para guías (§5.3)
 - opcion_mayorista_id      ← nullable, cuando el ítem viene de una opción
                               de mayorista elegida (sección 2.4)
 - descripcion_manual         (NUEVO 28-jul-2026 — texto libre, SOLO
     cuando origen_tipo='manual'. Confirmado con el usuario: hace falta
     un ítem sin proveedor registrado para casos puntuales — ej. un
     cobro de última hora de un tercero ocasional que no amerita crear
     un proveedor entero para esa sola vez. Sin restricción de rol: lo
     puede crear cualquier vendedor, no solo el admin. IMPORTANTE: un
     ítem manual NO tiene `proveedor_tarifa_id`, así que la validación
     de piso en vivo (más abajo, "descuento_maximo_pct"/
     "margen_minimo_pct") no aplica — el precio manual queda a criterio
     del vendedor sin piso protegido, porque no hay tarifa de proveedor
     de la que derivar ese piso)
 - modo_precio: por_persona | tarifa_fija
     (hotel = tarifa_fija por habitación; tour = por_persona con precio
     adulto/niño distinto; transporte privado = tarifa_fija con límite
     de capacidad)
 - cantidad             (NUEVO 28-jul-2026 — integer, default 1. Hueco
     encontrado probando el prototipo HTML del cotizador: un hotel se
     cobra POR NOCHE, un transporte privado puede pedirse en más de un
     vehículo — precio_venta_snapshot/costo_snapshot pasan a ser precio
     UNITARIO, no total. Total del ítem = precio_venta_snapshot ×
     cantidad. Solo aplica a modo_precio=tarifa_fija en la práctica —
     en por_persona la "cantidad" ya está resuelta por pax_incluidos,
     no se multiplica dos veces)
 - pax_incluidos: qué pasajeros de la cotización aplica este ítem
     (por defecto todos, ajustable — ej. niño no toma un tour opcional)
 - moneda_costo               (heredada del proveedor/opción de origen)
 - costo_snapshot             (actualizado 24-jul-2026 — costo del
                                 proveedor congelado al cotizar, en
                                 moneda_costo)
 - precio_venta_snapshot      (actualizado 24-jul-2026 — precio de venta
                                 calculado y congelado al cotizar, antes de
                                 cualquier descuento)
 - descuento_pct              (actualizado 24-jul-2026 — editable; el
                                 vendedor puede escribir este campo O
                                 precio_convertido directamente, el otro se
                                 recalcula automáticamente)
 - precio_convertido          (en moneda_cotizacion de la alternativa,
                                usando tipo_cambio_aplicado; editable —
                                sincronizado en ambas direcciones con
                                descuento_pct, ver arriba)
```

**Validación del piso al editar (en vivo, no solo al guardar):** sea cual
sea el campo que edite el vendedor (`descuento_pct` o `precio_convertido`),
el sistema calcula `precio_minimo_permitido` (mayor entre el piso por
`descuento_maximo_pct` y el piso por `margen_minimo_pct`, definidos en
`proveedor_tarifas` — ver `plan-modulo-proveedores.md`) y marca el campo en
rojo de inmediato si lo cruza, sin esperar a intentar guardar la
cotización.

Cada alternativa genera su propio PDF (comercial, para el cliente),
usando los datos del header + solo sus propios ítems — sin duplicar
cliente/pasajeros.

### 3.1.1 Cómo se muestra el descuento en el PDF — configurable por agencia
No es una decisión única para todo el sistema: cada agencia decide su
propio estilo desde `configuracion_agencia`, sin que el dato subyacente
cambie (siempre se guarda precio original, % de descuento y precio final
completos — el PDF solo elige qué mostrar):

```
configuracion_agencia (campos nuevos)
 - formato_descuento_pdf: solo_final | tachado | separado
     solo_final  → solo precio final, sin mostrar que hubo descuento (default)
     tachado     → precio original tachado + precio final con descuento
     separado    → precio final + % de descuento como dato aparte, sin tachar
 - mostrar_descuento_como_linea: true | false
     true  → línea aparte al final del PDF ("Descuento 10%: -S/45")
     false → diluido dentro del precio final de cada servicio (default)
```
Las tres variantes de `formato_descuento_pdf` no son excluyentes entre sí a
nivel de dato — son solo plantillas distintas sobre la misma información
guardada. Una agencia puede cambiar de estilo en cualquier momento sin
migrar nada.

### 3.2 Ciclo de vida de las alternativas
```
borrador → enviada → aceptada / descartada
```
- Cuando el cliente elige una (ej. "vamos por la C"), esa pasa a
  `aceptada` y dispara la creación de la `reserva`.
- Las demás alternativas de esa cotización pasan automáticamente a
  `descartada` (no se borran de inmediato — el cliente podría
  arrepentirse antes de viajar y volver a una opción descartada).
- Limpieza: un proceso programado borra alternativas `descartada` después
  de un plazo tras la fecha del servicio. El plazo es **configurable**,
  sin default fijo — se define en un panel de configuración (a nivel de
  tenant o en el panel superadmin).

### 3.3 Ítems internacionales dentro de una alternativa
Un paquete internacional también se puede **personalizar**, no solo
registrarse como bloque de referencia fijo. Por eso
`alternativa_items.proveedor_tarifa_id` es nullable — un ítem de vuelo o
de hotel internacional puede no venir de una tarifa registrada, sino de una
`opcion_mayorista` elegida (sección 2.4) con precio capturado en el momento
de cotizar. Sigue siendo un ítem atómico más dentro de la misma alternativa
— mismo motor, no una estructura paralela.

### 3.4 Moneda y tipo de cambio
Regla real de la agencia:
- **Costo**: en soles para casi todo lo local; en dólares para boletos
  aéreos, paquetes internacionales, hoteles fuera del país.
- **Venta**: el cliente elige la moneda final (soles o dólares) sin
  importar en qué moneda esté el costo — la agencia convierte con su
  propio tipo de cambio.
- El tipo de cambio usado **no siempre es el mismo tipo**: a veces se
  aplica el tipo de cambio "del día" (mercado), a veces el "de la
  agencia" (fijado internamente, actualizado cada cierto tiempo o cuando
  el dólar sube/baja). Se registra cuál de los dos se usó, con historial
  — no un valor único que se sobrescribe.

```
tipo_cambio_agencia
 - fecha
 - origen: dia | agencia
 - valor
 - registrado_por
     (se guarda un registro nuevo cada vez que se usa un valor distinto,
     nunca se sobrescribe — así queda el historial completo de ambos
     orígenes)
```

Al cotizar, el usuario elige si usa el tipo de cambio del día o el de la
agencia (mostrando el último valor registrado de cada uno, con opción de
digitar uno nuevo si no está registrado todavía). Ese valor queda fijo
como snapshot en `alternativas.tipo_cambio_aplicado` +
`tipo_cambio_origen`, para que una cotización ya enviada no cambie de
precio si después se actualiza el tipo de cambio vigente.

Esto coincide con `Sale.currency` del core (sección 6): un solo valor por
venta, no mixto por línea — los costos en distinta moneda ya se convierten
antes, en `alternativa_items.precio_convertido`.

### 3.5 Vencimiento de cotización enviada
```
configuracion_agencia
 - dias_vigencia_cotizacion   (configurable, sin default fijo)
```
Al vencer `alternativas.fecha_vencimiento`, el sistema solo muestra una
**marca visual** ("vencida") en el listado — no cambia el estado
automáticamente ni bloquea que el vendedor la acepte igual (por si
reconfirma precio con el cliente). No se fusiona con la limpieza de
`descartada` (sección 3.2), que es un proceso distinto.

### 3.6 Cupos / capacidad
- **Lo local (Tarapoto/San Martín): sin control de cupo en el sistema.**
  Si la agencia usa movilidad propia tiene flexibilidad; si comparte
  vehículo con otra agencia ("endosar"), son pasajeros de otra agencia,
  fuera del alcance de este sistema.
- **Paquetes nacionales/internacionales:**
  ```
  configuracion_agencia
   - max_pax_reserva_con_vuelo   (default 15, configurable)
   - max_pax_reserva_grupo       (sin vuelo, default 50, configurable)
  ```
  Si la alternativa incluye algún ítem tipo `vuelo`, la suma de pasajeros
  no debe superar `max_pax_reserva_con_vuelo`; si no hay vuelo, aplica
  `max_pax_reserva_grupo`. **Es advertencia, no bloqueo** — el sistema
  avisa pero deja continuar, por si se negocia una excepción con la
  mayorista/aerolínea.

### 3.7 Paquetes/tours de plantilla (catálogo reutilizable)
Pensando también en el portal web que se construirá en una fase futura
aparte (no ahora): los "paquetes enlatados" que se arman para vender
directo (empaquetados, o un tour/actividad suelta) son plantillas
reutilizables, independientes de cualquier cotización puntual — mismo
concepto de servicios atómicos, solo que predefinido.

**Actualizado 24-jul-2026 — ver `plan-modulo-tours-catalogo.md`:** confirmado
que "tour" (usado en `tour_itinerario_items.tour_id`, sección 5.1) y
`paquetes_plantilla` son la **misma entidad**, no dos tablas distintas —
validado con documentos reales de tours de la agencia (Full Day Alto Mayo,
Tours Lamas Nativo), que traen ficha completa (duración, horarios, lugar de
recojo, incluye/no incluye, recomendaciones), no solo nombre y precio:
```
paquetes_plantilla
 - codigo                  (NUEVO 25-jul-2026 — el vendedor ingresa un
                             prefijo libre, ej. "PDKM-CZ"; confirmado con
                             documentos reales de la agencia que ya usan
                             este patrón de códigos. Único, visible al
                             cliente en el PDF)
 - categoria: local | nacional | internacional   (NUEVO — la agencia ya
                             piensa y organiza sus paquetes así,
                             confirmado con los 3 documentos reales)
 - nombre, descripción, fotos
 - destino_atractivo_id (o varios, vía destino_servicio)
 - duracion_horas
 - hora_salida / hora_retorno
 - lugar_recojo            (texto, ej. "Hoteles ubicados dentro de la ciudad")
 - no_incluye               (texto/lista, ej. "Gastos extras, propinas, bebidas")
 - recomendaciones           (texto/lista, ej. "Ropa cómoda, bloqueador, agua")
 - vuelo_incluido: boolean   (NUEVO — ej. Cusco/Panamá sí, Alto Mayo no)
 - vuelo_aerolinea            (NUEVO)
 - vuelo_detalle               (NUEVO — texto libre: tramos, fechas, horas,
                                 equipaje permitido; no estructurado en
                                 columnas porque el itinerario tentativo
                                 cambia según la fecha de salida real)
 - items_incluidos        (1 o más servicios/tarifas con precio ya fijado
                            — puede ser un solo ítem, ej. un tour suelto.
                            Esto es lo que genera el "Incluye" del PDF
                            automáticamente — NO es texto libre, cada
                            ítem es un destino_servicio + proveedor_tarifa
                            real)
 - precio_venta_final     (actualizado 25-jul-2026 — ya no es el único
                            precio real, ver nota abajo: queda como precio
                            "desde" para listados)
 - vigencia_desde / vigencia_hasta
 - publicado_web (boolean, default false — hoy no hace nada, es el
                   interruptor que usará el futuro portal web)
```

**Actualizado 25-jul-2026 — matriz de precio por hotel × tipo de
habitación (reemplaza `precio_venta_final` como único monto):**
validado con los 3 documentos reales (Alto Mayo, Cusco, Panamá) — un
paquete no tiene un solo precio, tiene varias opciones de hotel, cada una
con precio distinto según matrimonial/doble/triple/familiar. Se comparte
la misma estructura `opciones_hotel`/`opciones_hotel_tarifas` definida en
la sección 2.4 para `opcion_mayorista` — un solo motor para las 3
categorías de paquete, no una tabla de precios por cada una:
```
opciones_hotel (ver estructura completa en sección 2.4)
 - paquete_plantilla_id → esta tabla (en vez de opcion_mayorista_id)
 - proveedor_id            (nullable — si el hotel ya es un proveedor
                             registrado, se reutiliza su tarifa real en
                             vez de solo texto)
```
`precio_venta_final` queda como el precio "desde" mostrado en listados
(el más barato de todas las opciones de hotel), no como el único precio
real del paquete.
Un vendedor puede partir de un `paquete_plantilla` al armar una
alternativa, en vez de construir todo desde cero — ahorra tiempo hoy y
deja lista la base para cuando se construya el portal (proyecto aparte,
próximo año). El portal en sí, y cómo se conecta a la venta real, no se
diseña en este documento.

**Implementado 29-jul-2026 (Sesión 11b2)** — CRUD admin completo de
`paquetes_plantilla`/`paquete_plantilla_items`/`tour_itinerario_items` +
matriz de hotel, ver `plan-modulo-tours-catalogo.md` §7 (historial) y
`TODO.md` para el detalle. **"Partir de un paquete_plantilla al armar una
alternativa" (el párrafo de arriba) todavía NO está construido** — es la
fila 11b3 de `plan-hoja-de-ruta-ejecucion.md`, separada a propósito
porque no es una copia 1:1 (precio/cantidad/modo_precio se resuelven en
vivo, e ítems de guía no tienen equivalente en `alternativa_items` hoy).

**Implementado 30-jul-2026 (Sesión 11b4) — `paquete_combo`, reemplaza el
diseño original de la fila 11b4 (tabla `tours` separada +
`proveedor_tarifas.tour_id`, nunca implementado):**
```
paquetes_plantilla
 - tipo: tour_simple | paquete_combo   (NUEVO — default tour_simple,
                          backfill de todo lo existente hasta esta sesión)
 - activo: boolean        (NUEVO — borrado lógico, mismo patrón que
                            guias.activo)
 - descuento_tipo: porcentaje | monto   (NUEVO — solo aplica si
                            tipo=paquete_combo)
 - descuento_valor        (NUEVO)
 - margen_minimo_pct      (NUEVO — piso de utilidad del combo tras el
                            descuento, mismo patrón que
                            proveedor_tarifas.margen_minimo_pct)

paquete_plantilla_items
 - paquete_plantilla_hijo_id  (NUEVO — FK nullable a paquetes_plantilla,
                            mutuamente excluyente con proveedor_tarifa_id/
                            guia_tarifa_id — exactamente uno de los tres.
                            `orden` NO es columna nueva: se reusa con
                            doble propósito, orden de aparición en el PDF
                            Y, para un ítem tour-hijo, qué día del combo
                            ocupa ese tour)

alternativa_items / reserva_items
 - tour_origen_id          (NUEVO en ambas — de qué tour_simple vino el
                            ítem al explotar un paquete_combo. Solo
                            agrupación visual, no afecta precio ni bloquea
                            edición posterior)

proveedores / guias
 - es_referencial: boolean  (NUEVO en ambas — precio de lista de la
                            agencia cuando todavía no se sabe qué
                            empresa/persona específica va a operar)
```
Profundidad máxima real: `paquete_combo` → `tour_simple` → ítems atómicos
(un `tour_simple` nunca puede incluir otro tour ni un combo). Precio del
combo (`costo_total_combo`/`venta_bruta_combo`/`venta_neta_combo`/
`margen_resultante_pct`) se calcula EN VIVO al leer/listar, nunca se
guarda como valor stale — ver `ComboExplosionService`/`PriceEngineService`
(`app-sistema-fe/app/Services/AgenciaViajes/`). Itinerario del combo
también derivado en vivo (concatena el itinerario real de cada tour
incluido, con `dia_relativo` desplazado por offset), sin tabla ni columna
nueva. Explosión de ítems (para cargar un combo en el cotizador) NO
deduplica líneas de guía entre tours del mismo combo, aunque sea el mismo
guía en dos tours distintos — son líneas independientes por diseño.
Desactivar un `tour_simple` usado en un combo activo se bloquea con 422
explícito (lista los combos afectados); forzado explícito
(`forzar_desactivacion=true`) lo permite, y el combo excluye su costo/
venta del cálculo mostrando la lista de `componentes_inactivos` — nunca
rompe el total en silencio.

**Gaps reales encontrados al implementar esta sesión, documentados aquí
porque el prompt original de 11b4 asumía mecanismos ya construidos que en
realidad no existen en el código (confirmado por grep, no por lectura
superficial):**
- El punto "reporte operativo" (`reserva_item` pendiente de asignar por
  proveedor/guía referencial) **no tiene ningún endpoint de backend** —
  la condición "pendiente de asignar" que ya existía (proveedor_tarifa_id/
  guia_id NULL) es puramente un `computed` de `reservas/detalle.vue`
  (frontend), nunca un endpoint de "reporte operativo" real. Esta sesión
  agrega `es_referencial` a `proveedores`/`guias` (se sirve automático en
  las relaciones ya cargadas por `ReservaController`), pero extender la
  condición visual para incluir `es_referencial` queda para cuando se
  toque el frontend (11b4b).
- El bloqueo de "marcar un pago a proveedor como realizado contra un
  proveedor/guía referencial" **no se implementó** — no existe NINGÚN
  controller/endpoint para `pago_proveedor`/`cronograma_pago_proveedor`
  todavía (Sesiones 8b/9b dejaron solo schema/modelo, confirmado por
  grep en `app/Http/Controllers` y `routes/api.php` — cero resultados).
  Construir ese CRUD completo es alcance de una sesión propia, no un
  ajuste "que extiende lo de Sesión 9".
- El recordatorio automático `cotizacion_por_vencer` **solo tiene su mitad
  de catálogo/config construida** (`configuracion_agencia.
  dias_aviso_vencimiento_cotizacion` + fila en `tipos_recordatorio`) — la
  mitad "disparador" (un job/comando que recorra `alternativas.
  fecha_vencimiento` y cree filas en `recordatorios`) no existe para
  NINGUNO de los 5 códigos del catálogo, ni siquiera para los 4 que ya
  estaban marcados `automatico=true` desde Sesión 10. Confirmado por grep:
  cero controllers/comandos/servicios que creen una fila de
  `recordatorios` en todo el proyecto.

---

## 4. De alternativa aceptada a Reserva

Al aceptar una alternativa, se crea una `reserva` — entidad separada de la
cotización, porque a partir de aquí los datos que se necesitan son
distintos y más completos:

```
reserva                     ← creada al aceptar una alternativa
                               (copia sus ítems/precios ya cerrados)
                             - mayorista_elegida_id, estado_reserva_mayorista:
                               pendiente | confirmada  (caso internacional)
                             - estado: activa | cancelada
                             - fecha_cancelacion, motivo_cancelacion (ver 4.2)

reserva_ventas                ← tabla puente (NO un campo simple sale_id,
                                 porque una reserva puede terminar con más
                                 de una venta — ver 4.3 y el caso de pagos
                                 por pasajero en 4.4)
                               - reserva_id
                               - sale_id
                               - reserva_item_ids      (qué ítems cubre)
                               - reserva_pasajero_ids  (qué pasajeros cubre
                                 — relevante cuando cada familia/pasajero
                                 paga con su propia venta, 4.4)

reserva_pasajeros           ← ahora con datos completos:
                               nombre, documento, nacionalidad,
                               alimentación especial, discapacidad,
                               vuelos (aerolínea/hora ida y vuelta)
                             - pasajero_catalogo_id  ← FK al perfil
                               reutilizable (sección 6.5)

reserva_items                ← copiados de la alternativa aceptada, pero
                                con fecha y hora CONCRETAS de cada
                                servicio (en cotización no siempre se
                                sabe la hora exacta todavía)
                              - guia_id  ← nullable, FK a `guias`
                                (ver sección 5.3)
                              - proveedor_tarifa_id  ← nullable, FK a
                                `proveedor_tarifas` (NUEVO 28-jul-2026,
                                mismo patrón que guia_id de arriba). Se
                                copia de `alternativa_items.
                                proveedor_tarifa_id` al aceptar SI la
                                cotización ya venía con proveedor
                                elegido; queda NULL si se cotizó con
                                precio de referencia sin comprometer
                                proveedor todavía (caso real confirmado
                                por el usuario: en actividades locales
                                muchas veces se cotiza y recién se asigna
                                quién opera cuando se reserva o días
                                antes de la fecha). Reasignable en
                                cualquier momento, igual que `guia_id` —
                                "quién opera" se confirma cerca de la
                                fecha, no en el momento de cotizar

reserva_item_pasajero        ← tabla puente: qué pasajero específico va
                                en qué ítem/actividad (control real de
                                quién hace qué — no todos los pax hacen
                                todas las actividades)
```

Esta es la etapa donde se llenan los pasajeros reales que van a viajar,
para control operativo al momento de realizar sus actividades (tours,
hotel, restaurante, transporte).

### 4.1 Venta directa (atajo para servicio suelto)
Para vender un solo servicio (ej. un boleto aéreo o una noche de hotel)
sin pasar por las 5 pantallas del flujo completo:
```
"Venta directa" — un solo formulario: cliente, tipo de servicio +
proveedor/tarifa, pax, fecha del servicio. Al guardar, el sistema arma
automáticamente y en un solo paso la misma cadena de siempre:
  cotización (header) → cotizacion_pasajeros → 1 alternativa (directo
  a "aceptada", sin pasar visualmente por borrador/enviada) →
  1 alternativa_item → reserva → reserva_pasajeros → reserva_items
```
Es un atajo de flujo/UI, no una estructura de datos paralela — la data
queda igual de consistente que una reserva armada paso a paso (reportes,
pagos, itinerarios funcionan igual).

### 4.2 Cancelación y reembolso (definido — implementación en Fase 2)
```
reglas_cancelacion
 - proveedor_id            (nullable → null = regla general de la agencia;
                             con valor = ese proveedor tiene su propia
                             regla más estricta)
 - dias_min_antes
 - dias_max_antes          (nullable = sin tope)
 - porcentaje_reembolso

 Carga inicial (regla general, proveedor_id null):
 - > 30 días antes  → 80%
 - 15–30 días antes → 50%
 - < 15 días antes  → 0%
```
```
reserva
 - motivo_cancelacion: voluntaria | fuerza_mayor | clima | falta_pago_cuotas
 - porcentaje_reembolso_aplicado   (snapshot del % usado al cancelar)
 - monto_reembolso
```
El reembolso real se ejecuta por el mecanismo de Notas de Crédito que ya
existe en el core (`Sale::tieneNotaCreditoTotalAceptada()`), este módulo
solo calcula el `porcentaje_reembolso_aplicado` y sugiere el monto — no
reimplementa nada fiscal.

**No se tabula, va manual (criterio humano, no fórmula):**
- Fuerza mayor / clima: el sistema solo registra el motivo, el monto de
  reembolso queda a criterio del usuario.
- Depósito no reembolsable: puede pisar la tabla de arriba — pendiente de
  definir cómo se marca en una reserva puntual.
- Cuotas incompletas 45 días antes del viaje: regla ligada al módulo de
  crédito del core (cuotas/mora) — cancelar sin reembolso si no
  completaron el pago a tiempo. Requeriría un job programado. Queda para
  cuando se implemente esta fase.

**Decisión de alcance:** todo lo de esta sección (4.2) queda documentado y
listo, pero su implementación real se hace en **Fase 2**, no en el primer
lanzamiento. Para el lanzamiento inicial solo entra `reserva.estado:
activa | cancelada`, para que el reporte operativo (sección 8) pueda
filtrar reservas canceladas.

**Liberación de cupo al cancelar (esto SÍ entra en el primer lanzamiento,
no es parte de la Fase 2):** si la reserva cancelada estaba vinculada a
una `salida_mayorista` (vía su `opcion_mayorista`, sección 2.4), al pasar
`reserva.estado = cancelada` se debe **restar** esos mismos pasajeros de
`cupo_ocupado` en esa salida — mismo movimiento que la suma al aceptar,
en reversa. Sin esto, el cupo quedaría "ocupado" para siempre aunque el
cliente ya no vaya, y la salida se marcaría agotada antes de tiempo.

**Guía turístico:** se asigna normalmente un día antes del viaje — el
campo `guia_id` en `reserva_items` debe poder quedar vacío/pendiente sin
bloquear el resto del flujo.

### 4.3 Cambios en la reserva después de facturada
`Sale::isEditable()` bloquea edición una vez que tiene `xml`/`cdr` (ya
enviada a SUNAT). Si el cliente agrega un servicio nuevo a una reserva ya
facturada, hay dos caminos — **la decisión de cuál usar se le pregunta al
cliente primero**, el vendedor solo ejecuta lo que el cliente pidió:

**Camino 1 — "Todo en un solo documento":**
1. Se emite Nota de Crédito total sobre el `Sale` actual (mecanismo ya
   existente del core).
2. Se crea un `Sale` nuevo con `replaces_sale_id` apuntando al anterior
   (campo que **ya existe** en el modelo `Sale` del core — no se toca
   nada ahí), cubriendo **todos** los `reserva_items` — los de antes + el
   nuevo servicio.
3. Nueva fila en `reserva_ventas`.

**Camino 2 — "Documento adicional":**
1. Se crea un `Sale` nuevo, sin anular nada, cubriendo **solo** el/los
   `reserva_item(s)` nuevos.
2. Nueva fila en `reserva_ventas` — la reserva queda con 2+ ventas activas
   simultáneamente, cada una cubriendo distintos ítems.

### 4.4 Pagos por pasajero / grupos con varios pagadores
Caso real: un grupo (ej. alumnos de un colegio) viaja junto, pero cada
familia puede pagar de forma independiente, o un solo responsable
(colegio/coordinador) puede cobrar a todos y pagarle a la agencia en
bloque — **ambos casos se dan en la práctica**, no es uno u otro fijo.

Se resuelve con la misma tabla puente `reserva_ventas` de arriba, sin
inventar nada nuevo:
- **Un solo responsable:** 1 fila en `reserva_ventas`, 1 `Sale` con
  `client_id` = el colegio/coordinador, cubriendo todos los
  `reserva_pasajeros`, con su propio cronograma de `Installment` (core).
- **Cada familia paga aparte:** N filas en `reserva_ventas`, N `Sale`
  distintos — cada uno con su propio `client_id` (la familia), cubriendo
  solo su(s) `reserva_pasajero_ids`, cada uno con su propio cronograma de
  `Installment` independiente (mora, fechas de vencimiento — todo ya
  existe en el core). Un pasajero atrasado en su pago no afecta el
  cronograma de los demás.

### 4.5 Anticipos antes de que exista el Sale final
El módulo `Advance` del core está atado al **cliente**, no a una reserva
— y solo se "aplica" formalmente a una venta (`AdvanceApplication`) en el
momento de facturar. Antes de eso, si un cliente tiene varias reservas
abiertas, no hay forma de saber a cuál corresponde un adelanto en
particular.

```
reserva_anticipos   (etiqueta un adelanto contra una reserva específica,
                      ANTES de que exista el Sale final)
 - reserva_id
 - advance_id
 - monto_asignado     (cuánto de ese adelanto corresponde a esta reserva)
 - fecha_asignacion
```

Con esto, el saldo pendiente de una reserva se calcula sin haber
facturado todavía: `alternativa.total - SUM(reserva_anticipos.monto_asignado)`.
Y al momento de facturar (sección 6.2), esta etiqueta indica qué
adelantos aplicar en el `AdvanceApplication` formal del core.

**Se evaluó y descartó** usar una Nota de Venta (documento no-fiscal del
core) para llevar este control: la Nota de Venta nunca se envía a SUNAT,
así que un anticipo registrado ahí no cumpliría la norma de comprobante
de anticipo obligatorio — terminaría duplicando el registro del dinero
(una vez en el `Advance` real, otra en la NV solo para llevar la cuenta).
`reserva_anticipos` evita esa duplicidad porque solo etiqueta el `Advance`
real, no reemplaza ni compite con él.

Solo se pide anticipo cuando el cliente confirma la reserva Y la agencia
ya tiene confirmado con la mayorista (nunca antes) — así que en la
práctica no hay ambigüedad de "a cuál reserva corresponde" al momento de
recibirlo: se etiqueta de inmediato.

### 4.6 Cronograma de pagos a proveedores/mayoristas
`pago_proveedor` (sección 6) solo registra pagos **ya realizados** — no
existía un cronograma de lo que se debe pagar y cuándo (caso real: viaje
de fin de año, cronograma armado desde junio, tanto para lo que el
cliente le debe a la agencia como para lo que la agencia le debe a la
mayorista).

```
cronograma_pago_proveedor
 - proveedor_id / opcion_mayorista_id
 - numero_cuota
 - monto_programado
 - fecha_vencimiento
 - estado: pendiente | pagado | vencido
```
Cuando se registra un pago real en `pago_proveedor`, se vincula a la
cuota correspondiente de este cronograma.

---

## 5. Itinerarios

### 5.1 Dos niveles (decisión confirmada)
- **A nivel de plantilla de tour** (catálogo): por **día relativo**
  (Día 1, Día 2, Día 3...), no por fecha calendario — porque el mismo
  tour se vende con distintas fechas de salida durante el año.

  ```
  tour_itinerario_items
   - tour_id → paquetes_plantilla.id (confirmado 24-jul-2026, misma
                entidad — ver plan-modulo-tours-catalogo.md)
   - dia_relativo (1, 2, 3...)
   - hora    (nullable — no todo tour trae hora por parada, ej. Lamas
              Nativo solo tiene horario general de salida/retorno)
   - orden   (NUEVO 24-jul-2026 — secuencia de actividades del día
              cuando no hay hora exacta por parada)
   - destino_atractivo_id  (puede ser zona/lugar/atractivo, cualquier
                             nivel del árbol — ver 5.2)
   - descripción de la actividad
  ```

- **A nivel de reserva confirmada**: el día relativo se resuelve contra
  la fecha real de inicio del viaje, automáticamente:

  ```
  reserva_items
   - fecha = fecha_inicio_reserva + dia_relativo - 1   ← calculado
   - hora  (heredada del tour, editable por si ese día cambia el horario)
  ```

  Para ítems sueltos de una cotización personalizada (sin partir de un
  tour de catálogo), la fecha se asigna directamente al `reserva_item`,
  sin `dia_relativo` de por medio.

### 5.2 Catálogo de destinos/atractivos y servicios (actualizado 24-jul-2026 — ver `plan-modulo-tours-catalogo.md`)
**Se reemplaza el modelo plano anterior** por un árbol de 3 niveles,
validado con tours reales de la agencia (Full Day Alto Mayo, Tours Lamas
Nativo): zona → lugar → atractivo. Catálogo reutilizable, independiente
del proveedor que opera en ese lugar (evita subir la misma foto de un
atractivo N veces para N tours distintos que pasan por ahí).

```
destinos_atractivos (autoreferenciada, 3 niveles)
 - id
 - parent_id   (nullable: null=zona, 1 nivel=lugar, 2 niveles=atractivo)
 - nombre       (ej. "Alto Mayo" zona / "Moyobamba" lugar /
                  "Orquideario" atractivo)
 - tipo          -- 'zona' | 'lugar' | 'atractivo'
 - descripcion
 - fotos (varias por registro)
```

Caso real que validó el árbol: un tour a "Alto Mayo" (zona) incluye
Moyobamba y Rioja (lugares), y dentro de Rioja está Tioyacu (atractivo,
la naciente de agua). El transporte cobra distinto por **lugar**
(Moyobamba vs. Rioja), mientras que las entradas cobran por **atractivo**
específico (entrada al orquideario ≠ entrada a otro atractivo) — por eso
`destino_servicio` no se restringe a un solo nivel:

```
servicios (catálogo reutilizable — NUEVO 24-jul-2026, no existía)
 - id
 - nombre             (ej. "Traslado ida y vuelta", "Traslado
                        aeropuerto-hotel", "City tour medio día",
                        "Full day", "Hospedaje", "Entrada/Boleto")
 - tipo_proveedor_id   (opcional, filtra qué servicios aplican según el
                         tipo de proveedor — ej. "Hospedaje" no aplica
                         a Transporte)

destino_servicio (tabla puente)
 - destino_id    → destinos_atractivos.id, CUALQUIER nivel (zona/lugar/
                    atractivo) — no restringido a un solo nivel; el
                    transporte normalmente cuelga de nivel lugar, las
                    entradas de nivel atractivo
 - servicio_id    → servicios.id
```

Caso real completo: mismo proveedor (Transportes San Martín), mismo tipo
de servicio (transporte), pero dos `destino_servicio` distintos con
tarifa propia cada uno — "Traslado ida y vuelta" incluido en un full day
(S/90) vs. "Traslado aeropuerto-hotel" suelto para una reserva de solo
hotel (S/25). Ver `proveedor_servicios`/`proveedor_tarifas` en
`plan-modulo-proveedores.md`.

### 5.3 Asignación y tarifas de guías (actualizado 25-jul-2026)
Catálogo simple para datos personales, sin manejo de disponibilidad/
calendario (confirmado: los guías son freelance, trabajan con varias
agencias a la vez — la agencia no controla su calendario completo, solo
necesita saber quién está asignado, sin validar choques de horario):

```
guias
 - nombre, documento, teléfono
 - activo   (para desactivar sin borrar histórico)
```

**Tarifas — NUEVO 25-jul-2026:** el guía se paga por día, y varía según
destino y modalidad (un grupo que va a otra región tiene tarifa distinta
que un tour local de un día). Como el cliente ve "Guía de Turismo" dentro
del "Incluye" de los tours reales de la agencia (confirmado en
`plan-modulo-tours-catalogo.md`), el guía necesita costo **y** precio de
venta — mismo patrón que cualquier otro proveedor:

```
guia_tarifas
 - guia_id
 - destino_id            → destinos_atractivos.id (zona o lugar, ej.
                            "Alto Mayo" o "Cusco")
 - modalidad: dia_local | grupo_multidia
 - costo_diario            (lo que se le paga al guía por día)
 - tipo_margen: porcentaje | fijo
 - margen_valor
     -- precio_venta_diario = costo_diario + margen_valor
 - moneda
 - vigente_desde / vigente_hasta   (versionado, mismo patrón que
                                     proveedor_tarifas — nunca se
                                     sobrescribe un precio ya usado)
```

**Cálculo del costo total para un tour:** `costo_diario × número de días
del tour` — el número de días sale de `tour_itinerario_items` (el
`dia_relativo` máximo del tour). Un full day de 1 día usa `× 1`; un tour
de 3 días a otra región usa `× 3`.

**Sin piso de descuento** (a diferencia de `proveedor_tarifas`) — no se
pidió control de choques de horario ni bloqueo de precio para guías, así
que por ahora no lleva `descuento_maximo_pct`/`margen_minimo_pct`. Se
puede agregar después con el mismo patrón si hace falta.

En `items_incluidos` (paquetes_plantilla) y en `reserva_items`, "Guía de
Turismo" ahora referencia `guia_tarifas` (no solo `guias` directo) — el
campo `guia_id` en `reserva_items` se presenta como un select **con
búsqueda** (no texto libre) — queda referenciado y reportable, sin lógica
de choques de horario por ahora.

### 5.4 Tres documentos distintos
- **PDF de alternativa/cotización**: comercial, enfocado en precio,
  generado en la etapa de cotización.
- **PDF de itinerario**: operativo/experiencial, con fotos y horas por
  día ("Día 1 — miércoles 15 de agosto"), generado desde la `reserva` ya
  confirmada — no antes, porque recién ahí hay fechas y horas reales.
- **PDF de políticas y condiciones**: documento estático (reservas, pagos,
  cancelación, documentación, equipaje, etc.), el mismo para todos los
  clientes — no se genera dinámicamente, se sube una vez (con su
  fecha/versión) y se adjunta al paquete de documentos que recibe el
  cliente al aceptar una alternativa.

### 5.5 Vouchers para proveedores
Documento operativo distinto de los anteriores: confirma el servicio al
proveedor (no al cliente) — "estos pasajeros llegan tal fecha, con esto
incluido".

```
proveedores (campos nuevos)
 - telefono_whatsapp (nullable)
 - email (nullable)
 - mostrar_monto_voucher_default (boolean, default false)

vouchers
 - reserva_id
 - proveedor_id
 - reserva_item_ids     (solo los ítems de ESE proveedor, no toda la reserva)
 - incluye_montos       (lo marcado en ESE envío puntual — queda como historial)
 - canal_envio: whatsapp | email   (sugerido según tipo_proveedor, editable)
 - fecha_generado
 - fecha_enviado        (nullable — se llena cuando el usuario aprieta enviar)
```

- **Canal por defecto:** WhatsApp para proveedores cuyo `proveedor_tipo`
  no es mayorista (local/nacional), email cuando `proveedor_tipo.slug ==
  'mayorista'` (nacional/internacional) — ver reclasificación en 2.1.
- **Envío simple, sin integración real:** botón `wa.me` (WhatsApp) o
  `mailto:` (correo) que abre la app con el mensaje ya redactado — el
  usuario aprieta enviar. Ninguno de los dos puede adjuntar el PDF
  automáticamente (limitación del navegador, no del sistema); si quieren
  el PDF, se descarga y se adjunta a mano. El contenido va como texto
  prellenado.
- **Montos:** checkbox editable en cada envío, premarcado según el
  default del proveedor.

---

## 6. Integración con el core de ventas (nuevo — decisión confirmada)

El core (`Sale`, `SaleController`, `SalePayment`, más los módulos ya
construidos de Crédito/Amortizaciones, Caja, Adelantos, Series de
comprobantes) **ya resuelve pagos, crédito, caja y facturación SUNAT** de
forma robusta. Este módulo de viajes NO reimplementa nada de eso — al
aceptar una alternativa, la `reserva` genera/enlaza un `Sale` real y todo
lo de dinero pasa por ahí.

Esto **reemplaza** lo que se había definido antes como `reserva_pagos`
(tabla propia con tipo anticipo/saldo) — ya no hace falta, el core cubre
ese caso mejor:
- Pago al contado → `sale_payments`.
- Adelanto con comprobante SUNAT propio → módulo `Advance`/
  `AdvanceApplication` (`Sale.type='advance'`).
- Crédito en cuotas → módulo `Installment` (cronograma, mora,
  `condicion_pago`).

`pago_proveedor` (agencia → proveedor/mayorista) **sí se mantiene** — el
core de `Sale` es solo para lo que la agencia le cobra al cliente, no
tiene nada para lo que la agencia paga a sus proveedores:
```
pago_proveedor
 - proveedor_id            (nullable a opcion_mayorista_id cuando aplique
                             el caso internacional)
 - monto
 - moneda: PEN | USD
 - fecha
 - tipo: adelanto_reserva | pago_final
 - referencia_documento    (número de factura que da el proveedor, texto
                             libre — no es un comprobante que la agencia
                             emite)
```

### 6.1 Servicios de viaje como líneas de venta (sale_details)
`SaleDetail` exige hoy `product_id` (con stock, ISC, categoría). Un
servicio de viaje no tiene inventario real. Decisión: **no** hacer
`product_id` nullable (cambio más riesgoso al core compartido). En su
lugar, se reutiliza `Product` con productos **genéricos por tipo de
servicio** (no uno por cada tarifa de proveedor):
```
products (campo nuevo)
 - controla_stock (boolean, default true)
     → false para los productos genéricos de viaje, así el
       decrement/increment de stock se salta sin tocar la lógica de
       retail existente

Productos genéricos pre-creados (uno por tipo de servicio atómico):
 - "Servicio de Hotelería"
 - "Servicio de Transporte"
 - "Tour / Actividad turística"
 - "Boleto Aéreo"
 - "Otros Servicios Turísticos"
```
```
sale_details (campo nuevo)
 - descripcion_detalle (texto, nullable)
     → detalle real concatenado de qué incluye esa línea (ej. "Hotel Río
       Cumbaza (2 noches) + Tour a Lamas"), en vez de depender del
       product.title genérico
```

### 6.2 Agrupación de ítems al facturar (default: por tipo de servicio, editable)
Al generar el `Sale` desde una `reserva`, el sistema **propone por
defecto** una línea de factura por tipo de servicio (Alojamiento,
Transporte, Tours, etc.), agrupando los `reserva_items` correspondientes
— pero el usuario puede fusionar o separar líneas manualmente antes de
emitir.

```
sale_detail_items   (tabla puente)
 - sale_detail_id
 - reserva_item_id
```
Permite que una línea de factura represente 1 o varios `reserva_items`
sin perder trazabilidad — el reporte operativo (sección 8) y los
itinerarios siguen leyendo de `reserva_items` directamente, nunca de la
factura (la factura es solo una representación distinta de los mismos
datos, no la fuente de verdad operativa).

### 6.3 Restricción: no se puede agrupar libremente
**El usuario puede agrupar/desagrupar, pero el sistema no permite
fusionar en una sola línea dos ítems con distinto tratamiento
tributario**, aunque sean del mismo tipo de servicio (ej. un hotel
exonerado Amazonía y otro gravado normal no pueden ir en la misma línea
— SUNAT exige `tip_afe_igv` desagregado). El tratamiento tributario se
compara por lo definido en `proveedor_tarifas.tip_afe_igv` +
`destino_tributario` (sección 2.2), heredado por cada `reserva_item`
desde su proveedor de origen — no se pregunta por ítem, ni se calcula en
el momento.

### 6.4 Moneda
`Sale.currency` es un solo valor por venta (PEN o USD) — coincide
exactamente con `alternativas.moneda_cotizacion` (sección 3.4): el
cliente elige una sola moneda de presentación, no mixta. Los costos por
proveedor en distinta moneda ya se convierten antes, en
`alternativa_items.precio_convertido`.

### 6.5 Documentos del pasajero (DNI/pasaporte)
Se guardan **a nivel de perfil de cliente/pasajero**, reutilizables entre
reservas — no hay que volver a subir el mismo documento cada vez que esa
persona viaja de nuevo.

```
pasajeros_catalogo    (perfil reutilizable, independiente de la reserva)
 - cliente_id           (nullable — si el pasajero también es un cliente
                          registrado con cuenta propia)
 - nombre
 - nacionalidad
 - fecha_nacimiento     (permite derivar adulto/niño/infante automático)

pasajero_documentos
 - pasajero_catalogo_id
 - tipo_documento: dni | pasaporte | carne_extranjeria | otro
 - numero_documento
 - fecha_vencimiento
 - archivo                (almacenamiento PRIVADO — fuera de carpetas
                            públicas/servidas directamente; el acceso pasa
                            siempre por un endpoint autenticado que
                            verifica permiso, nunca un link directo
                            descargable o indexable)
 - fecha_registro

reserva_pasajeros (campo nuevo)
 - pasajero_catalogo_id  ← enlaza al perfil reutilizable
   (lo específico de ESE viaje — vuelos ida/vuelta, alimentación especial
   para ese tour puntual — se queda en reserva_pasajeros; lo que se
   reutiliza es solo identidad + documento)
```

```
configuracion_agencia
 - meses_margen_vencimiento_documento   (configurable, default 6 meses)
```
**Alerta al crear la reserva:** si `fecha_vencimiento` del documento es
anterior a `fecha_viaje + meses_margen_vencimiento_documento`, el sistema
avisa (vencido o por vencer) — aplica sobre todo a internacional, donde
la agencia necesita la copia del documento para el check-in. El listado
de pasajeros con documento vencido/por vencer es un reporte derivado de
esta misma tabla, no una tabla nueva.

**Menores de edad:** viajan con autorización de padres/tutores si no
viajan con ellos — pendiente de definir si esto también se sube como
documento en `pasajero_documentos` (`tipo_documento` adicional) o queda
fuera del sistema por ahora.

---

## 7. Criterios de UX para cuando se diseñen las pantallas (Fase 3)

El modelo de datos es robusto a propósito (moneda, tributación, mayoristas,
crédito) — pero eso no debe traducirse en pantallas sobrecargadas. Principio
a seguir cuando se diseñe el frontend:

- **Mostrar solo lo necesario en cada paso**, con valores por defecto ya
  resueltos (tipo de cambio, agrupación de factura, canal de voucher) —
  el usuario solo interviene si quiere cambiar algo, nunca arma nada desde
  cero si el sistema ya puede sugerirlo.
- **Lo avanzado se mantiene oculto hasta que el caso lo amerite**: mayoristas,
  cupos, tratamiento tributario mixto, cronogramas de crédito — no le
  aparecen a alguien cotizando un tour local simple.
- **El caso más común (venta directa de un servicio suelto, sección 4.1)
  debe sentirse tan simple como llenar un solo formulario**, no como
  recorrer las 5 pantallas del flujo completo.

### 7.1 Layout del cotizador (NUEVO 28-jul-2026 — validado con prototipo HTML)

**Decisión de diseño:** el cotizador NO reutiliza el wizard de tarjetas
numeradas de `views/sale/register.vue` (Ventas). Investigado contra
software real del rubro (Travefy, Ezus, Tourwriter) — todos convergen en
el mismo patrón de 3 capas, porque cotizar un viaje es exploratorio
(probar combinaciones, comparar precio) y no una transacción lineal de
un producto con stock:

```
┌ Biblioteca ──┐ ┌── Lienzo (día por día) ──────┐ ┌ Precio en vivo ─┐
│ tarifas del   │ │ pestañas: Alternativa A/B/+  │ │ líneas + total   │
│ destino,      │ │ clic en biblioteca → agrega  │ │ recalculado en   │
│ filtradas por │ │ al día activo                │ │ vivo, editable   │
│ destino_      │ │                              │ │ (descuento %,    │
│ servicio      │ │                              │ │ piso en rojo)    │
└───────────────┘ └──────────────────────────────┘ └───────────────────┘
```

- **Paso 0 (una sola vez por cotización, no por alternativa):** modal
  corto que crea el header — cliente (buscador + alta rápida, mismo
  patrón que `ClientFormQuick.vue`), destino, fecha de viaje tentativa
  (con opción "todavía no tiene fecha exacta"), y pasajeros. Los
  pasajeros se cargan por EDAD individual (obligatoria, sección 3.1),
  con botones rápidos "+Adulto/+Niño/+Infante" que sugieren una edad
  editable — el tipo (adulto/niño/infante) se deriva solo, no se elige
  a mano.
- **Toggle Local/Nacional vs. Internacional** encima de la biblioteca:
  cambia qué se ve en esa columna. Local/Nacional muestra la biblioteca
  de tarifas pre-cargadas (clic = agregar). Internacional muestra un
  **comparador de cotizaciones de mayorista** (tarjetas lado a lado, una
  por mayorista consultado, cada una con su matriz hotel×habitación —
  sección 2.4) en vez de biblioteca, porque acá no hay tarifa fija que
  listar: es cotización manual cada vez. Marcar una como "elegida" la
  inserta en el lienzo como un ítem más — mismo motor de
  `alternativa_items`, no una estructura paralela (confirma 3.3).
- **Tres formas de cobrar un ítem, cada una con su feedback visual en el
  lienzo** (probado en el prototipo, ver 3-ítem):
  - `tarifa_fija` (hotel, transporte privado) → stepper de `cantidad`
    (noches/vehículos) junto al precio, recalcula en vivo. **Para Hotel
    específicamente**: al elegirlo desde la biblioteca se muestra primero
    la matriz de `tipo_habitacion` (matrimonial/doble/triple/familiar,
    ver §2.2 y §2.4 — mismo componente matriz para proveedor local y
    para paquete/mayorista, un solo motor) — el vendedor elige la
    habitación, RECIÉN ahí se agrega al lienzo con su cantidad de
    noches. El prototipo probado simplificó esto a una sola línea de
    precio para testear el layout general más rápido; falta restaurar
    la matriz al construir 11b de verdad.
  - `por_persona` diferenciado (tour, pasaje aéreo) → precio se reparte
    solo según los pasajeros del header, mostrando el detalle del
    cálculo bajo el ítem (ej. "3 adultos × S/70 + 1 niño × S/45") — el
    vendedor no arma la cuenta a mano.
  - `por_persona` plano (traslado compartido, restaurante) → mismo
    precio para todos los pasajeros, sin diferenciar edad.
  - **manual / libre** (NUEVO 28-jul-2026) → no sale de la biblioteca —
    un botón aparte "+ Ítem manual" al pie de la biblioteca abre un
    campo de descripción + precio a mano. Sin validación de piso (no
    hay `proveedor_tarifa` de la que derivarlo). Pensado para casos
    puntuales sin proveedor registrado, no como atajo habitual — si un
    mismo ítem manual se repite seguido, es señal de que ese proveedor
    debería cargarse de verdad en el maestro (Sesión 11a).
- **Pestañas de alternativas** (hasta 5, sección 3.1) arriba del lienzo,
  cada una con su propio lienzo y su propio total — comparación lado a
  lado, no navegación entre pantallas separadas (mismo patrón que
  "Proposals" de Travefy).
- **Prototipo de referencia:** `prototipo-cotizador.html` (compartido
  fuera del repo, en la conversación de diseño) — clickeable, sin datos
  reales, usado para validar el flujo con el equipo antes de programar
  los componentes Vue. Cuando se arranque la Sesión 11b, partir de ese
  layout ya probado en vez de diseñar desde cero.

---

## 8. Reporte operativo por fecha (actualizado 25-jul-2026 — RESUELTO)

Requisito original: poder ver, para una fecha dada, qué pasajeros tienen
tours/paquetes/hotel/otros servicios ese día, a qué destino, en qué hotel
están, quién es su guía (si ya está asignado), datos relevantes del
pasajero (alimentación, discapacidad) y sus vuelos (horario ida/vuelta,
aerolínea).

Se resuelve como una vista/consulta sobre `reserva_items` +
`reserva_item_pasajero` + `reserva_pasajeros`, agrupada por fecha,
filtrando `reserva.estado != cancelada` — no es una tabla nueva, es un
reporte derivado del modelo ya definido arriba.

**Un solo reporte, con selector de rango de fecha** (hoy / esta semana /
rango personalizado) — no dos pantallas separadas para uso diario vs.
planificación, es el mismo reporte con distinto filtro por defecto.

**Columnas:** pasajero, servicio, destino, hora, guía asignado, hotel,
alimentación especial, discapacidad, vuelos. **Alerta visual** si falta
guía asignado, para detectarlo y resolverlo desde la misma pantalla.

**Acciones inline (confirmado 25-jul-2026):**
- Reasignar/asignar guía directamente desde el reporte
- Marcar check-in del pasajero

```
reserva_item_pasajero (campos nuevos)
 - checkin_realizado: boolean, default false
 - checkin_hora        (nullable, se llena al marcar)
```

**Dos formatos (confirmado — ambos, no uno u otro):**
- **Pantalla en el sistema:** tabla filtrable con las acciones inline de
  arriba.
- **PDF/impreso:** mismo filtro de fecha, versión de solo lectura sin
  botones de acción — pensado para repartir al equipo en campo sin
  acceso al sistema (imprimir o compartir por WhatsApp).

---

## 8bis. Sistema de recordatorios / notificaciones en el sistema (NUEVO 25-jul-2026)

Surgió al diseñar el reporte operativo, pero es un módulo transversal, no
exclusivo de reportes — resuelve dos módulos que ya estaban catalogados
pero nunca diseñados en `plan-modulo-planes-acceso.md`
(`aviso_pasaportes_vencer`, `felicitaciones_cumpleanos`), más casos
nuevos (pago a mayorista próximo, cotización estancada).

**Objetivo:** mensajes flotantes (notificación tipo toast) dentro del
sistema, mientras el usuario está trabajando — no push ni email, solo
en-app. Configurable para no saturar: posponer 1h/8h, omitir por tipo,
o forzado por el admin si lo considera importante.

```
tipos_recordatorio (catálogo — qué puede generar un recordatorio)
 - codigo: pago_proveedor_pendiente | cumpleanos_cliente |
           cotizacion_estancada | documento_por_vencer | personalizado
 - nombre
 - automatico: boolean   -- true = el sistema lo genera solo (pago
                             próximo, cumpleaños, cotización estancada,
                             documento por vencer); false = alguien lo
                             crea a mano (personalizado)

recordatorios
 - tipo_id
 - entidad_tipo: reserva | cotizacion | cliente | pago_proveedor | libre
 - entidad_id       (nullable si es libre/personalizado sin entidad)
 - titulo, mensaje
 - fecha_disparo     (cuándo debe aparecer)
 - usuario_id          (nullable si es para un rol completo)
 - rol_destino: vendedor | admin | todos
 - creado_por           (admin puede crear para otros; vendedor solo
                          para sí mismo)
 - forzado: boolean      -- si el admin lo marca importante, el vendedor
                             puede posponer pero NO descartar del todo —
                             vuelve a aparecer igual
 - estado: pendiente | visto | pospuesto | descartado

recordatorio_snooze_config (preferencia por usuario y tipo)
 - usuario_id
 - tipo_id
 - snooze_minutos: 60 | 480 | personalizado   ("1 hora", "8 horas")
 - omitir: boolean   (el usuario apaga ese tipo para sí mismo, salvo que
                       esté `forzado` por el admin — ahí no puede omitirlo)
```

**Disparadores automáticos confirmados, todos con default configurable
por agencia (no hardcodeado):**

```
configuracion_agencia (campos nuevos)
 - dias_aviso_pago_proveedor       (default 2 — avisa 2 días antes de
                                      cronograma_pago_proveedor.fecha_vencimiento)
 - dias_cotizacion_estancada        (default 15 — alternativa en estado
                                      'enviada' sin cambiar de estado por
                                      N días)
```
- **Pago a proveedor/mayorista próximo:** automático desde
  `cronograma_pago_proveedor.fecha_vencimiento` (ya existente, sección
  4.6), restando `dias_aviso_pago_proveedor`.
- **Cumpleaños de cliente:** automático desde
  `pasajeros_catalogo.fecha_nacimiento` (o del cliente), un día antes.
- **Cotización estancada:** automático, alternativa en `enviada` sin
  cambiar de estado por `dias_cotizacion_estancada` días.
- **Documento por vencer:** ya existía como alerta pasiva (sección 6.5,
  `meses_margen_vencimiento_documento`) — ahora también dispara
  recordatorio, mismo mecanismo, no duplica lógica.
- **Personalizado:** el admin o el vendedor crea uno manual, asociado a
  una reserva/cotización/cliente o libre (ej. "llamar a fulano mañana").

**Visibilidad por rol (confirmado 25-jul-2026):** el admin ve **todos**
los recordatorios pendientes de todos los vendedores en una sola vista
(supervisión de equipo) — cada vendedor ve solo los suyos.

---

## 9. Pendientes / preguntas abiertas

- **Gestión de proveedores a fondo** (altas, bajas, negociación de
  tarifas) — confirmado como módulo aparte, aún no abordado en detalle.
- Falta detallar los formularios CRUD predecesores (proveedores, tarifas,
  tipo de cambio) antes de tocar el flujo de cotización propiamente.
- **Tratamiento tributario mixto dentro de una misma reserva/venta**:
  `Sale.destino` y `Sale.es_exportacion` son un solo valor para TODA la
  venta (no por línea), mientras que `tip_afe_igv` sí es por línea. Si una
  reserva combina, por ejemplo, un hotel exonerado Amazonía con un vuelo
  de exportación, la leyenda de exoneración del PDF podría salir
  incompleta. Falta decidir: ¿se permite mezclar tratamientos distintos en
  una misma reserva (aceptando esa limitación visual), o se fuerza a
  generar `Sale` separados cuando eso pasa?
- **Autorización de menores de edad** (documento de viaje sin padres) —
  sin definir si entra al modelo de `pasajero_documentos` o queda fuera.
- **Depósito no reembolsable**: cómo se marca en una reserva puntual, sin
  chocar con la tabla `reglas_cancelacion` (Fase 2).
- **Portal web público** (venta de paquetes/tours empaquetados): proyecto
  aparte para el próximo año. `paquetes_plantilla` (sección 3.7) deja la
  base lista, pero el portal en sí no se diseña en este documento. Nota
  importante: NO se reutiliza el módulo `Order`/`OrderController` del
  core — ese es de otro tenant (umbosystem, retail de equipos
  informáticos) y no existe en la base de datos de este tenant.
- **Política de cancelación/reembolso completa** (sección 4.2): definida
  a nivel de diseño, implementación pospuesta a Fase 2 (la liberación de
  cupo al cancelar, 4.2, sí entra desde el inicio).
- **Agregar un pasajero nuevo a una reserva ya confirmada/aceptada**
  (encontrado 14-ago-2026, conversación con el usuario: caso real de una
  pareja donde uno viajaba solo y su pareja se suma después de ya hecha
  la reserva). Hoy `ReservaPasajeroController` solo tiene `update()`
  (completar datos de un pasajero que YA está en la reserva) — no existe
  `store()` para sumar uno nuevo, porque el mapeo
  `cotizacion_pasajero_id → reserva_pasajero_id` se arma una sola vez, en
  el momento exacto de aceptar la alternativa
  (`ReservaController::crearReservaDesdeAlternativa()`/
  `reconstruirMapaPasajeros()`). Agregar SERVICIOS nuevos a una reserva ya
  aceptada sí funciona hoy (agregar el ítem en la alternativa + botón
  "Sincronizar" en `reservas/detalle.vue`, sección 4.3) — lo que falta es
  específicamente la persona. La sección 4.3 (documento adicional /
  todo-en-un-documento) ya cubre cómo facturar el cargo incremental una
  vez que el pasajero exista; falta diseñar solo la parte de "cómo entra
  el pasajero nuevo a una reserva cerrada" (¿`ReservaPasajeroController::
  store()` directo sobre la reserva, sin pasar por `cotizacion_pasajeros`
  ya que esa cotización quedó "cerrada" al aceptar? ¿Qué pasa si eso
  además implica cambiar un ítem ya existente, ej. habitación individual
  → doble, no solo agregar uno?). Explícitamente fuera de alcance de la
  sesión del 14-ago-2026 (que se enfocó en los 3 fixes de la cotización
  antes de aceptar) — el usuario decidió resolverlo operacionalmente por
  fuera del sistema mientras tanto.

---

## 10. Historial de actualizaciones

| Fecha | Cambio |
|---|---|
| 22-jul-2026 | Primera versión consolidada: proveedores/tarifas, alternativas, reservas, itinerarios (2 niveles), catálogo de destinos |
| 22-jul-2026 | Sesión de maduración (parte 1): `proveedor_tarifa_id` nullable en `alternativa_items`; destino como filtro sugerido no bloqueante; flujo real de compra con mayoristas + `opcion_mayorista`; asignación de guías; plazo de limpieza configurable; moneda y tipo de cambio con historial y origen dia/agencia |
| 22-jul-2026 | Sesión de maduración (parte 2): integración con el core de ventas existente (`Sale`/`SaleController`/Crédito/Caja/Adelantos) reemplazando `reserva_pagos`; productos genéricos por tipo de servicio con `controla_stock=false`; agrupación de líneas de factura por tipo de servicio con restricción tributaria (`tip_afe_igv`, `destino_tributario`); venta directa (atajo de flujo); cupos/capacidad (15 con vuelo / 50 grupo, advertencia no bloqueante); `salidas_mayorista` (catálogo de paquetes armados en fechas fijas); vouchers para proveedores (WhatsApp/email simples); política de cancelación/reembolso definida para Fase 2; vencimiento de cotización configurable; documentos del pasajero reutilizables por perfil con almacenamiento privado y alerta de vencimiento (default 6 meses); `paquetes_plantilla` como base para futuro portal web (proyecto aparte, próximo año) |
| 22-jul-2026 | Sesión de maduración (parte 3 — revisión de huecos): clasificación de edad infante/niño/adulto (0-2/2-12/12+) con `precio_venta_infante` y edad obligatoria en `cotizacion_pasajeros`; `reserva_ventas` como tabla puente (reemplaza el `sale_id` simple) para soportar múltiples ventas por reserva; camino "todo en un documento" (Nota de Crédito + `replaces_sale_id` ya existente en el core) vs. "documento adicional" para cambios post-facturación, decidido por el cliente; liberación de cupo en `salidas_mayorista` al cancelar reserva (sí entra en el primer lanzamiento); pagos por pasajero/grupos con varios pagadores (colegios) vía `reserva_ventas`; `reserva_anticipos` para etiquetar adelantos del core contra una reserva antes de facturar (se descartó usar Nota de Venta por no cumplir la norma SUNAT de comprobante de anticipo); `cronograma_pago_proveedor` para lo que la agencia debe pagar a proveedores/mayoristas; sección nueva de criterios de UX para la Fase 3; queda abierto el tratamiento tributario mixto dentro de una misma venta (`Sale.destino`/`es_exportacion` a nivel de cabecera vs. `tip_afe_igv` por línea) |
| 24-jul-2026 | Reconciliación con `plan-modulo-proveedores.md` (sesión de cruce entre módulos): se reemplaza `tipo_proveedor: regular \| mayorista` por FK a catálogo formal `proveedor_tipos` (central + config por tenant); se confirma que guías turísticos se mantienen como tabla propia `guias` (no se migra a tipo de proveedor); se reemplaza el filtro "sugerido no bloqueante" de destino por modelo estructural `proveedor_servicios`, porque proveedores de transporte cobran distinto por destino (caso real: Lamas vs. Moyobamba) |
| 24-jul-2026 | Se reemplaza `fecha_inicio_vigencia`/`fecha_fin_vigencia` sueltas por catálogo reutilizable `temporadas` + `temporada_ocurrencias` en `proveedor_tarifas` (evita repetir fechas por proveedor cada año, permite agrupar reportes por temporada); se agrega `descuento_maximo_pct`/`margen_minimo_pct` como piso de descuento protegido al cotizar (pendiente de confirmación del usuario) |
| 24-jul-2026 | Modelo de descuento ágil en cotizaciones: `descuento_global_pct` a nivel de alternativa (se reparte a cada línea respetando su piso individual) + `descuento_pct`/`precio_convertido` sincronizados bidireccionalmente por línea en `alternativa_items` (renombrado desde `precio_calculado`, separado ahora en `costo_snapshot`/`precio_venta_snapshot`), con validación del piso en vivo mientras el vendedor edita. Formato de PDF hecho configurable por agencia (`configuracion_agencia.formato_descuento_pdf` / `mostrar_descuento_como_linea`) — las 3 variantes de visualización no son excluyentes a nivel de dato, solo cambian la plantilla |
| 24-jul-2026 | Sesión de módulo 2 (catálogo de destinos/tours, ver `plan-modulo-tours-catalogo.md`): se confirma que "tour" (`tour_itinerario_items.tour_id`) y `paquetes_plantilla` son la misma entidad, validado con documentos reales de tours (Full Day Alto Mayo, Tours Lamas Nativo); se agregan campos de cabecera a `paquetes_plantilla` (duración, horarios, lugar de recojo, no incluye, recomendaciones); `destinos_atractivos` pasa de plano a árbol de 3 niveles (zona/lugar/atractivo, autoreferenciado); se crea catálogo `servicios` (nuevo, no existía) y `destino_servicio` ahora puede apuntar a cualquier nivel del árbol, no solo a un destino plano — caso real: transporte cobra por lugar, entradas cobran por atractivo; se agrega campo `orden` a `tour_itinerario_items` para secuenciar actividades sin hora exacta |
| 25-jul-2026 | Sesión sobre paquetes locales/nacionales/internacionales, validada con 3 documentos reales de la agencia (Alto Mayo, Cusco, Panamá). Hallazgo principal: el precio no es un monto único — es una matriz por hotel × tipo de habitación (matrimonial/doble/triple/familiar), aplica a las 3 categorías, no solo internacional. Se crea `opciones_hotel`/`opciones_hotel_tarifas` compartida entre `paquetes_plantilla` y `opcion_mayorista`. Se documenta el flujo real de mayoristas (Nuevo Mundo, Falabella, Inter-agencias) — hoy pasa por Excel + Word manual, el sistema busca reemplazar ese paso. Se agrega margen automático por mayorista (`proveedores.margen_default_tipo/valor`, editable por línea). Se agregan tours opcionales (`opcion_mayorista_opcionales`, precio aparte del paquete base) y datos de vuelo (aerolínea, detalle) tanto a `paquetes_plantilla` como a `opcion_mayorista`. Se agrega `paquetes_plantilla.codigo`/`categoria` (local/nacional/internacional), confirmado con el patrón de códigos que ya usa la agencia (PDKM-CZ, PDKM-AM). Se agrega `cotizaciones.codigo` (prefijo libre + año + correlativo por prefijo) como identificador único de cotización. |
| 25-jul-2026 | Módulo de guías a fondo: confirmado que son freelance (trabajan con varias agencias, no se controla su calendario completo — sin choques de horario por ahora). Se agrega `guia_tarifas` (costo/margen por guía × destino × modalidad dia_local/grupo_multidia, versionado igual que `proveedor_tarifas`, sin piso de descuento por ahora). Resuelve el pendiente dejado en `plan-modulo-tours-catalogo.md` §6 sobre si "Guía de Turismo" en `items_incluidos` necesita tarifa propia — sí la necesita. |
| 25-jul-2026 | Reporte operativo por fecha resuelto (§8): un solo reporte con selector de rango (hoy/semana/personalizado), acciones inline (reasignar guía, marcar check-in — nuevo `reserva_item_pasajero.checkin_realizado`/`checkin_hora`), y versión PDF de solo lectura para repartir al equipo. Se agrega módulo nuevo transversal de recordatorios/notificaciones en-app (§8bis) — resuelve los módulos `aviso_pasaportes_vencer`/`felicitaciones_cumpleanos` que estaban catalogados en `plan-modulo-planes-acceso.md` sin diseñar, más casos nuevos (pago a mayorista próximo con `dias_aviso_pago_proveedor` default 2, cotización estancada con `dias_cotizacion_estancada` default 15, ambos configurables). Recordatorios con snooze (1h/8h/omitir) y flag `forzado` para que el admin marque uno como no descartable. Admin ve todos los recordatorios del equipo, vendedor solo los suyos. |
| 28-jul-2026 | Sesión de diseño UX del cotizador (Sesión 11, ver hoja de ruta): se descarta reutilizar el wizard de tarjetas de Ventas — se diseña layout de 3 columnas (biblioteca/lienzo día-por-día/precio en vivo) validado contra software real del rubro (Travefy, Ezus, Tourwriter) y probado con un prototipo HTML clickeable fuera del repo (§7.1). Se agrega `alternativa_items.cantidad` (hueco encontrado probando el prototipo: hotel se cobra por noche, transporte privado por vehículo — precio pasa a ser unitario). Se agrega §2.5: `PriceEngineService` como motor de precios único (evita margen duplicado por controller) y tabla nueva `cotizacion_pasaje_aereo` para vender un pasaje aéreo SUELTO (no vía mayorista) — con desglose de `cargos` en JSON (tarifa base + impuestos + TUA + fee de agencia), validado contra normativa MTC 2026 que obliga a las aerolíneas a desglosar estos cargos. Queda pendiente confirmar si `aerolinea` es FK a un tipo de proveedor nuevo o texto libre. |
| 28-jul-2026 | `proveedor_tarifas.tipo_habitacion` promovida de `diferenciador` (JSON libre) a columna explícita con el mismo enum que ya usa `opciones_hotel_tarifas` — RETROFIT sobre Sesión 5, ya mergeada. Motivo: se vende la habitación, no el hotel; el motor de precios (§2.5) necesita tratar "Hotel" igual sin importar si el ítem viene de un proveedor local o de un paquete/mayorista. §7.1 actualizado para que el ítem Hotel muestre la matriz de habitaciones antes de agregarse al lienzo (el prototipo probado lo había simplificado a una sola línea de precio para testear el layout más rápido). |
| 28-jul-2026 | Confirmado con el usuario: los servicios/actividades locales SÍ están casi siempre atados a un proveedor registrado (`destino_servicio` → `proveedor_servicios` → `proveedor_tarifas`), salvo casos puntuales — se agrega `alternativa_items.origen_tipo` (proveedor\|mayorista\|pasaje_aereo\|manual) como discriminador EXPLÍCITO en vez de inferir el origen del ítem por qué FK nullable está llena (frágil con 3-4 orígenes posibles). Se agrega el 4to origen, ítems **manual/libre** (`descripcion_manual`, sin proveedor registrado, sin validación de piso de descuento — no hay `proveedor_tarifa` de la que derivarlo), sin restricción de rol. RETROFIT sobre `alternativa_items`, tabla ya mergeada en Sesión 7 — misma migración que agrega `cantidad` (ver entrada anterior), ambas van en Sesión 11b. |
| 28-jul-2026 | Confirmado con el usuario: en actividades locales muchas veces se cotiza sin saber todavía qué proveedor específico va a operar — se asigna recién al reservar o días antes de la fecha. Se extiende la nulabilidad ya existente de `alternativa_items.proveedor_tarifa_id` para cubrir este caso (precio de referencia, sin comprometer proveedor) y se agrega `reserva_items.proveedor_tarifa_id` (nullable, reasignable) — **mismo patrón que `guia_id`** (§5.3, "se asigna normalmente un día antes"), aplicado ahora también al proveedor del servicio en general, no solo al guía. RETROFIT sobre `reserva_items`, tabla ya mergeada en Sesión 8 — va en Sesión 11c (reserva/pasajeros), no 11b. **Decidido:** sin alerta automática por recordatorio (§8bis) para `reserva_items` sin proveedor asignado — queda visible solo en el reporte operativo (§8), no dispara un `tipos_recordatorio` nuevo. |
| 28-jul-2026 | Cerradas las 2 preguntas que quedaban abiertas de la sesión de diseño anterior. **`cotizacion_pasaje_aereo.aerolinea` queda como texto libre**, no FK — mismo criterio que `vuelo_aerolinea` en `opcion_mayorista`/`paquetes_plantilla` (donde el proveedor con FK real es el mayorista, no la aerolínea). Confirmado con el usuario: la agencia no es agencia IATA, no hay relación comercial directa con aerolíneas que reportar. **Sin alerta automática de recordatorio** para `reserva_items` sin proveedor asignado — queda visible solo en el reporte operativo (§8), no se agrega un 5to `tipos_recordatorio`. Ninguna de las 2 decisiones requirió cambios de estructura, solo cerrar la ambigüedad documentada el 28-jul-2026 anterior. |
| 28/29-jul-2026 | **RETROFIT sobre `cotizaciones` (tabla mergeada en Sesión 7a):** `fecha_viaje_tentativa` (una sola fecha) reemplazada por `fecha_viaje_desde`/`fecha_viaje_hasta` (ambas nullable, con `after_or_equal` cuando ambas están cargadas) — el campo original no alcanzaba para cotizar con fecha de ida y vuelta conocidas. Confirmado antes de migrar: ningún tenant real (sandbox/umbo/negocio2/umbo-archivado) tenía todavía la tabla `cotizaciones` (rama sin mergear), sin datos que backfillear en la práctica — el backfill de la migración queda igual como red de seguridad. |
| 29-jul-2026 | **Sesión 11b2 — §3.7 implementado**: CRUD admin de paquetes/tours de plantilla construido (`feature/sesion-11b2-paquetes-plantilla`), cierra el hueco que había quedado desde Sesión 6 (tablas/modelos sin API/pantalla). Se agregan a la hoja de ruta las filas 11b2 (esta) y **11b3** (conectar al cotizador — "cargar desde plantilla", todavía sin construir, con las 3 diferencias reales frente a una copia 1:1 ya documentadas ahí). Detalle completo, incluido un bug real (`codigo` sin validar `unique()`, tiraba 500 en vez de 422), en `TODO.md`. |
| 29-jul-2026 | Confirmado con el usuario, sin cambio de estructura: el costo de un guía va mezclado dentro del precio de otro servicio (el tour que guía) — la asignación (`guia_id` en `reserva_items`, §5.3) es puramente operativa (quién opera, no qué se cobra). NO se agrega un 5to `origen_tipo='guia'` en `alternativa_items`; el guía no es un ítem cobrable con línea propia en el resumen de la reserva. Surgió al revisar un mockup de resumen de reserva que mostraba al guía como línea propia con precio — descartado. |
| 30-jul-2026 (frontend) | **Sesión 11b4b — frontend de `paquete_combo`**: extiende `paquetes/form.vue`/`detalle.vue`/`index.vue` (no pantallas nuevas). Verificado con Playwright real contra `agencia-demo`: selector de tipo, combo con 2 tours agregados vía "Tour completo", acordeón agrupado por día con lazy-load, itinerario derivado con offset correcto, preview de precio 100% cliente (confirmado sin POST de por medio) + guardado real, bloqueo de desactivación con modal + `componentes_inactivos`, listado con badges/conteo. 0 errores de consola/red. Ver historial de `plan-hoja-de-ruta-ejecucion.md` (fila 11b4b) para el detalle completo, incluido un hallazgo real: `tenants:migrate` no corre migraciones de `tenant/verticals/`, hubo que aplicarlas a mano contra `agencia-demo` antes de poder probar. |
| 30-jul-2026 | **Sesión 11b4 — REEMPLAZA el diseño original de esta fila** (tabla `tours` separada + `proveedor_tarifas.tour_id`, nunca implementado). Diseño nuevo: `paquetes_plantilla.tipo` (`tour_simple`\|`paquete_combo`) — un `paquete_combo` agrupa 2+ tours_simple vía `paquete_plantilla_items.paquete_plantilla_hijo_id` (mutuamente excluyente con `proveedor_tarifa_id`/`guia_tarifa_id`, profundidad máxima combo→tour_simple→ítems). Precio/itinerario del combo calculados en vivo (`ComboExplosionService`/`PriceEngineService`), nunca guardados stale. `tour_origen_id` nuevo en `alternativa_items`/`reserva_items` para agrupación visual "Día 1/Día 2". `es_referencial` nuevo en `proveedores`/`guias`. Ver §3.7 arriba para el detalle completo, incluidos 3 gaps reales documentados ahí (reporte operativo, bloqueo de pago a proveedor, disparador de recordatorio automático — los tres referenciaban mecanismos que el prompt de esta sesión asumía ya construidos pero que no existen en el código; confirmado por grep antes de escribir nada, no implementados en esta sesión). 17/17 tests verdes (`tests/Feature/AgenciaViajes/PaqueteComboTest.php`, primer test de todo el vertical Agencia de Viajes) contra Postgres real (`sistemafe_test_migrations`, transacción por test revertida), incluidas las 6 migraciones nuevas verificadas con `migrate`+`rollback`+`migrate` real. Solo backend — frontend es sesión aparte (11b4b). |
| 03-ago-2026 | **Sesión ad-hoc de UX, rama `feature/ux-catalogo-proveedores-tours`, mergeada a `main` (`c15890d`) — no es fila de `plan-hoja-de-ruta-ejecucion.md`.** Alcance acotado al tab "Incluye" de `tour_simple` (§3.7) — no toca `paquete_combo`, que ya tenía su propia tarjeta de precio desde 11b4b. Agrega precio visible (`precio_venta_adulto` del proveedor, o costo/margen del guía resuelto en el cliente con la misma fórmula porcentaje/fijo de `guia_tarifas`) en el picker de biblioteca y en cada línea de la lista de ítems agregados, más un bloque de totales (costo/venta/margen resultante, mismo semáforo 20% que el resto del vertical) calculado 100% en el cliente a partir de los ítems ya cargados — sin llamada nueva al backend, sin persistir nada. Suma simple entre ítems de distinta moneda (PEN/USD) sin conversión — mismo criterio ya aceptado en el "Precio del combo" de 11b4b (`preview.costoTotal`, ver arriba), no una regresión nueva. Ver `plan-modulo-proveedores.md` (historial, misma fecha) para la mitad de esta sesión que tocó el modal de tarifa. |
| 14-ago-2026 | **3 gaps reales del cotizador, señalados por el usuario en conversación (rama `fix/cotizador-pasajeros-fechas-nombre-alternativa`, entregada como parche fuera de un entorno con acceso a Postgres/tenant real — pendiente de aplicar y verificar de punta a punta por el usuario antes de mergear).** (1) Aumentar/quitar pasajeros de una cotización ya creada: el endpoint `PUT cotizaciones/{id}/pasajeros` existía desde Sesión 7a pero nunca se conectó a ninguna pantalla. Al conectarlo se encontró que además borraba y recreaba TODOS los `cotizacion_pasajeros` en cada guardado (ids nuevos siempre), dejando `alternativa_items.pax_incluidos` con ids colgando en silencio — ahora es diff por id, solo toca lo que realmente cambió. También se encontró que `modo_precio='por_persona'` congela el precio al crear el ítem y nada lo recalculaba después si la cantidad de pasajeros cambiaba — ahora se recalcula automático solo para el caso de alta confianza (`origen_tipo=proveedor` con una `proveedor_tarifa` real detrás, alternativa `borrador`/`enviada`, mismo cálculo exacto que `AlternativaItemController::crearItemProveedor()`); el resto (pasaje_aereo, mayorista, guía, o un ítem sin tarifa real detrás) no se adivina, queda listado en `items_para_revisar` para reprecio manual. Bloqueado por completo si la cotización ya tiene una alternativa `aceptada` (reserva real generada — tocarla después ya no cambia la reserva, solo la desincroniza en silencio). (2) Fechas de viaje que se guardaban pero no se mostraban al reabrir el formulario de edición: el backend las devuelve como timestamp ISO completo y `<input type="date">` necesita `YYYY-MM-DD` exacto — sin el recorte el campo quedaba en blanco aunque la fecha sí estaba guardada (visible correctamente en el badge de arriba, que sí recorta bien vía `formatFecha()`). (3) Nombre de una alternativa ya creada no se podía editar: el input solo existía en el form de creación; el backend (`PUT alternativas/{id}`) ya aceptaba `nombre` desde siempre, solo faltaba el control en pantalla (ícono de lápiz en el pill, mismo patrón que el de cabecera). Del lado de reservas ya confirmadas quedó un gap real relacionado pero fuera de alcance de esta sesión, documentado en la sección 9: agregar un pasajero nuevo a una reserva ya aceptada. |
