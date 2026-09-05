# AUDITORÍA ARQUITECTÓNICA PROFUNDA
## Agencia de Viajes — Sistema existente vs. modelo de dominio objetivo

### MODO DE TRABAJO: SOLO ANÁLISIS — NO IMPLEMENTAR

Esta tarea tiene como objetivo auditar la arquitectura actual del vertical **Agencia de Viajes** y determinar si debemos:

- conservar;
- refactorizar;
- extender;
- reemplazar;
- eliminar;
- o crear nuevas estructuras.

**NO modificar código durante esta tarea.**

No crear migraciones.
No ejecutar migraciones.
No modificar modelos.
No modificar controllers.
No modificar Vue.
No cambiar rutas.
No hacer commits.
No eliminar archivos.

El resultado esperado es un **diagnóstico arquitectónico profundo + propuesta de arquitectura objetivo + plan de refactorización**, no código.

---

# 1. DOCUMENTOS DE CONTEXTO

En el proyecto existen documentos de análisis previamente elaborados.

Deben ser utilizados como contexto inicial:

```text
auditoria-schema-modelos-agencia-viajes.md
auditoria-controllers-services-flujo-cotizacion-reserva.md
plan-refactor-mayoristas-tramos.md
plan-matriz-hoteles-cotizador.md
plan-fix-moneda-cotizador.md
```

Estos documentos NO deben considerarse automáticamente correctos.

Son levantamientos y decisiones previas.

Tu trabajo es:

1. leerlos;
2. entenderlos;
3. contrastarlos con el código real;
4. detectar inconsistencias;
5. señalar decisiones que siguen abiertas;
6. proponer una arquitectura coherente.

Si el documento contradice el código actual, reportarlo.

Si el documento contiene una decisión de negocio explícitamente cerrada, no reabrirla salvo que descubras que produce una contradicción arquitectónica grave.

---

# 2. OBJETIVO

Determinar cuál debería ser la arquitectura definitiva del vertical Agencia de Viajes antes de continuar implementando funcionalidades.

La pregunta principal NO es:

> "¿Qué tablas faltan?"

La pregunta es:

> **"¿El modelo actual representa correctamente el negocio real de una agencia de viajes y permite evolucionar sin continuar acumulando retrofits y excepciones?"**

Necesitamos especialmente analizar si la arquitectura actual está mezclando:

```text
Catálogo
Cotización
Propuesta
Venta
Reserva
Operación
Proveedor
Costo
Cobro al cliente
Pago al proveedor
```

---

# 3. CONTEXTO REAL DEL NEGOCIO

La agencia puede vender:

- tours;
- paquetes;
- hoteles;
- vuelos;
- traslados;
- guías;
- seguros;
- cruceros;
- servicios personalizados;
- servicios obtenidos mediante mayoristas;
- combinaciones de todos ellos.

Un viaje real puede contener múltiples destinos.

Ejemplo:

```text
Cliente de Cusco
        │
        ▼
Tarapoto
2 días
        │
        ├── vuelo
        ├── hotel
        ├── traslado
        └── tour local
        │
        ▼
México
10 días
        │
        ├── Cancún
        ├── Valladolid
        ├── Mérida
        └── Bacalar
```

La primera parte puede ser armada directamente por la agencia.

La segunda puede obtenerse consultando:

```text
Mayorista A
Mayorista B
Mayorista C
```

El cliente debe recibir la propuesta de la agencia.

La identidad del mayorista puede ser información interna y no debe filtrarse al cliente.

---

# 4. PRINCIPIO CENTRAL DEL DOMINIO

El sistema debe distinguir entre:

## Producto de catálogo

Algo previamente definido y reutilizable.

Ejemplo:

```text
Full Day Alto Mayo
```

## Servicio conocido

Algo que la agencia conoce comercialmente, aunque todavía no tenga proveedor operativo asignado.

Ejemplo:

```text
Traslado aeropuerto → hotel
Costo estimado: S/ 40
Proveedor operativo: pendiente
```

## Servicio cotizado bajo demanda

Algo que se obtiene específicamente para una solicitud.

Ejemplo:

```text
Hotel X
Mayorista Y
Costo USD 850
```

## Servicio personalizado

Algo que no existe previamente en catálogo.

Ejemplo:

```text
Traslado privado especial
```

La arquitectura debe soportar los cuatro casos sin obligar a crear falsos productos de catálogo.

---

# 5. PRIMERA REGLA DE AUDITORÍA

NO aceptar automáticamente las abstracciones existentes.

En particular evaluar críticamente:

```text
Cotizacion
Alternativa
AlternativaItem
PaquetePlantilla
DestinoAtractivo
DestinoServicio
Proveedor
ProveedorServicio
ProveedorTarifa
OpcionMayorista
OpcionHotel
Reserva
ReservaItem
SalidaOperativa
Guia
GuiaTarifa
CronogramaPagoProveedor
PagoProveedor
```

Preguntar para cada una:

```text
¿Representa un concepto real del dominio?

¿Tiene una sola responsabilidad?

¿Su nombre representa correctamente lo que contiene?

¿Depende de otra entidad que conceptualmente no debería?

¿Tiene demasiadas excepciones?

¿Fue creada para resolver un único caso?

¿Podría desaparecer si rediseñamos una relación?

¿Debería dividirse?

¿Debería fusionarse con otra?

¿Está en el nivel correcto del dominio?
```

---

# 6. PROBLEMA ESPECIAL: RETROFITS

El schema muestra un patrón fuerte de evolución mediante:

```text
crear tabla
↓
agregar FK
↓
agregar otro FK
↓
agregar otro origen
↓
agregar excepción
↓
agregar campo
↓
migrar concepto
↓
dejar columna muerta
```

Ejemplo especialmente importante:

```text
alternativa_items
```

actualmente puede relacionarse con:

```text
proveedor_tarifa_id
opcion_mayorista_id
opcion_hotel_tarifa_id
paquete_plantilla_id
guia_tarifa_id
tour_origen_id
proveedor_promovido_id
```

y además:

```text
origen_tipo
```

determina cuál de esas relaciones representa el origen.

Analizar si esto es:

### A

Una arquitectura flexible y válida.

### B

Una señal de que estamos utilizando una entidad genérica para representar demasiados conceptos distintos.

### C

Una combinación de ambas.

NO asumir que B es correcta.

---

# 7. PREGUNTA ARQUITECTÓNICA CENTRAL SOBRE ALTERNATIVA ITEM

Determinar si:

```text
AlternativaItem
```

debe continuar siendo el punto común de:

```text
proveedor
mayorista
manual
pasaje aéreo
guía
```

o si deberíamos introducir una abstracción diferente.

Analizar al menos estas alternativas:

### Alternativa A

Mantener:

```text
Alternativa
└── AlternativaItem
       ├── proveedor_tarifa
       ├── opcion_mayorista
       ├── guia_tarifa
       ├── pasaje_aereo
       └── manual
```

### Alternativa B

Introducir algo como:

```text
AlternativaItem
       │
       └── FuenteCotizacion
```

### Alternativa C

Separar conceptualmente:

```text
ItemCatalogo
ItemCotizado
ItemManual
ItemMayorista
```

### Alternativa D

Otra arquitectura que consideres mejor.

Comparar ventajas y desventajas.

---

# 8. VIAJE VS COTIZACIÓN

Determinar si el dominio necesita realmente una entidad:

```text
Viaje
```

independiente de:

```text
Cotizacion
```

Analizar la diferencia entre:

```text
Solicitud del cliente
Cotización
Alternativa
Reserva
Viaje operativo
```

No introducir `Viaje` solamente porque el ejemplo tiene múltiples destinos.

Determinar si el concepto correcto sería:

```text
Expediente
Solicitud
Itinerario
Viaje
```

o ninguno.

---

# 9. PROBLEMA MULTI-DESTINO

El caso real requiere:

```text
Tarapoto
        ↓
México
        ↓
Cancún
        ↓
Valladolid
        ↓
Mérida
        ↓
Bacalar
```

Analizar profundamente cómo representar:

```text
Destino
Orden
Fecha inicio
Fecha fin
Duración
Pasajeros
Servicios
Moneda
Proveedor
Fuente del precio
```

Determinar si necesitamos:

```text
Tramo
```

o si el concepto correcto sería:

```text
Etapa
Segmento
Componente
Destino
Itinerario
```

No asumir que "tramo" es correcto.

Explicar cuál concepto es semánticamente correcto y por qué.

---

# 10. REGLA IMPORTANTE SOBRE DESTINO

Evaluar y validar esta hipótesis:

```text
Destino
≠
Origen del precio
```

Por ejemplo:

```text
Destino:
Tarapoto

Origen del precio:
Mayorista
```

es perfectamente válido.

También:

```text
Destino:
México

Origen del precio:
Agencia
```

puede ser válido.

Por lo tanto:

```text
local
nacional
internacional
```

no debería determinar automáticamente:

```text
catálogo
mayorista
manual
proveedor
```

Auditar dónde está actualmente acoplada esta lógica.

---

# 11. MAYORISTAS

Auditar:

```text
OpcionMayorista
SalidaMayorista
OpcionMayoristaOpcional
OpcionHotel
OpcionHotelTarifa
```

Determinar qué representa realmente:

```text
OpcionMayorista
```

¿Es:

- una cotización recibida;
- una alternativa;
- una oferta;
- una salida;
- una contratación;
- un proveedor;
- una combinación de servicios?

Determinar cuál debería ser el concepto correcto.

---

# 12. MATRIZ DE MAYORISTAS + HOTELES

Debe analizarse el caso:

```text
Mayorista A
 ├── Hotel 1
 ├── Hotel 2
 └── Hotel 3

Mayorista B
 ├── Hotel 1
 ├── Hotel 2
 └── Hotel 3

Mayorista C
 ├── Hotel 1
 ├── Hotel 2
 └── Hotel 3
```

La agencia puede elegir:

```text
Mayorista B
+
Hotel 2
```

pero el cliente debe recibir:

```text
Hotel 2
Precio
Servicios
Condiciones
```

sin necesariamente recibir:

```text
Mayorista B
```

Auditar la arquitectura actual y coordinarla con:

```text
plan-matriz-hoteles-cotizador.md
```

No crear una segunda solución incompatible.

---

# 13. PROVEEDOR NO REGISTRADO

El sistema debe poder registrar una propuesta recibida de un mayorista/proveedor aunque:

```text
el proveedor no esté previamente registrado;
el hotel no esté en catálogo;
el servicio no exista;
```

Pero eso NO significa que todo deba convertirse automáticamente en un registro permanente.

Analizar:

```text
Proveedor registrado
Proveedor ad-hoc
Proveedor promovido
Proveedor referencial
```

y determinar si actualmente estos conceptos están mezclados.

---

# 14. COMERCIAL VS OPERATIVO

Esta es una de las auditorías más importantes.

Actualmente existe una diferencia entre:

```text
AlternativaItem
```

y:

```text
ReservaItem
```

Analizar si la arquitectura realmente separa:

## Comercial

```text
qué se ofreció
precio
costo
margen
moneda
descuento
proveedor comercial
```

de:

## Operativo

```text
qué se debe ejecutar
fecha
hora
guía
proveedor operativo
salida
pasajeros
check-in
```

Ejemplo:

```text
Traslado
Costo: S/40
Venta: S/50

Proveedor operativo:
PENDIENTE
```

Luego:

```text
2 días antes
↓
se asigna operador
```

Determinar si el modelo actual soporta esto correctamente.

---

# 15. SNAPSHOTS

Auditar todos los lugares donde existen:

```text
*_snapshot
precio
costo
moneda
tipo de cambio
margen
```

Determinar:

```text
¿Qué debe quedar congelado?
¿Qué debe seguir vivo?
¿Cuándo se congela?
¿Quién es la fuente de verdad?
```

Especialmente:

```text
Cotizacion
Alternativa
AlternativaItem
Reserva
ReservaItem
ProveedorTarifa
OpcionHotelTarifa
```

Identificar cualquier lugar donde un cambio posterior de catálogo pueda alterar accidentalmente una operación histórica.

---

# 16. MONEDA

Existe actualmente:

```text
Alternativa.moneda_cotizacion
Alternativa.tipo_cambio_aplicado
Alternativa.tipo_cambio_origen
```

Pero el caso real puede tener:

```text
Tarapoto → PEN
México → USD
```

dentro de una misma cotización.

Coordinar obligatoriamente con:

```text
plan-fix-moneda-cotizador.md
```

Determinar:

1. nivel correcto de moneda;
2. nivel correcto de tipo de cambio;
3. moneda de costo;
4. moneda de venta;
5. conversión;
6. snapshot;
7. redondeo;
8. margen;
9. histórico.

No implementar.

---

# 17. COTIZACIÓN VS RESERVA

Auditar cuidadosamente:

```text
Cotizacion
Alternativa
Reserva
```

Actualmente:

```text
Cotizacion
↓
Alternativa
↓
aceptación
↓
Reserva
```

Determinar exactamente qué se congela en cada etapa.

Especialmente revisar:

```text
cliente
destino
fechas
pasajeros
precio
moneda
tipo de cambio
proveedor
hotel
servicio
margen
```

Una reserva histórica NO debe depender accidentalmente de una cotización modificada posteriormente.

---

# 18. RESERVA VS OPERACIÓN

Auditar:

```text
Reserva
ReservaItem
SalidaOperativa
ReservaPasajero
ReservaItemPasajero
ReservaItemVueloPasajero
```

Determinar si:

```text
Reserva
```

representa correctamente el compromiso comercial/operativo.

Y si:

```text
SalidaOperativa
```

representa correctamente una agrupación operativa.

Ejemplo:

```text
Tour Alto Mayo
fecha 15/09

Reserva A
Reserva B
Reserva C
       ↓
SalidaOperativa
       ↓
Guía
```

Evaluar qué debe agruparse y qué debe permanecer por reserva.

---

# 19. PASAJEROS POR TRAMO

Analizar si el modelo futuro debe permitir:

```text
Viaje
 ├── Tarapoto
 │     ├── Pax 1
 │     ├── Pax 2
 │     └── Pax 3
 │
 └── México
       ├── Pax 1
       └── Pax 2
```

sin duplicar pasajeros innecesariamente.

Evaluar dónde debe vivir:

```text
pax_incluidos
```

si continúa existiendo.

---

# 20. PAGOS DEL CLIENTE

Auditar:

```text
Sale
Advance
Installment
ReservaAnticipo
ReservaVenta
```

Determinar correctamente:

```text
Precio de venta
Cuenta por cobrar
Anticipo
Saldo
Pago
Factura
Reserva
```

No duplicar el sistema financiero existente si el core ya resuelve el problema.

Determinar solamente qué relación necesita el vertical Agencia de Viajes.

---

# 21. PAGOS A PROVEEDORES

Auditar:

```text
CronogramaPagoProveedor
PagoProveedor
```

Actualmente existe schema, pero verificar nuevamente si realmente existe flujo funcional.

Debe poder soportarse:

```text
Reserva A → proveedor X → USD 2,000
Reserva B → proveedor X → USD 1,500
Reserva C → proveedor X → USD 3,000
```

y eventualmente:

```text
Pago consolidado:
USD 6,500
```

Evaluar si necesitamos:

```text
ObligacionProveedor
CuotaProveedor
PagoProveedor
AplicacionPagoProveedor
```

o si el modelo actual puede evolucionar sin crear esas entidades.

No agregar entidades por sofisticación.

---

# 22. PROVEEDOR VS MAYORISTA

Determinar si:

```text
Mayorista
```

debe ser:

```text
Proveedor con tipo = mayorista
```

o:

```text
Entidad independiente
```

o:

```text
rol/capacidad del proveedor
```

El hecho de que un mayorista pueda vender:

```text
local
nacional
internacional
```

debe ser considerado.

No usar geografía como sustituto del rol comercial.

---

# 23. PROVEEDOR REFERENCIAL

Auditar:

```text
Proveedor.es_referencial
Guia.es_referencial
proveedor_promovido_id
proveedor_sugerido_manual
```

Determinar si estos conceptos son coherentes o si representan intentos diferentes de solucionar el mismo problema.

Especialmente:

```text
Proveedor referencial
≠
Proveedor real
```

Debe quedar claro cuándo puede:

- cotizar;
- vender;
- reservar;
- generar obligación;
- recibir pago.

---

# 24. COSTOS

Auditar:

```text
costo estimado
costo contratado
costo real
precio venta
margen
```

Determinar si el sistema actual puede distinguirlos.

Ejemplo:

```text
Costo estimado:
S/ 40

Costo contratado:
S/ 42

Costo real:
S/ 45

Venta:
S/ 50
```

No asumir que todos son necesarios.

Determinar cuáles son realmente necesarios en el dominio.

---

# 25. PRICE ENGINE

Auditar:

```text
PriceEngineService
```

Determinar qué cálculos deben pertenecer al motor de precios y cuáles están fuera de lugar.

Verificar especialmente:

```text
margen
descuento
precio mínimo
moneda
tipo de cambio
cargos
precio de venta
combo
```

Determinar si el servicio está correctamente delimitado o si se está convirtiendo en un "God Service".

---

# 26. PAQUETE VS TOUR

Actualmente:

```text
PaquetePlantilla
```

representa:

```text
tour_simple
paquete_combo
```

La documentación indica que ambos conceptos se consolidaron deliberadamente.

No reabrir esta decisión salvo que el código revele una contradicción grave.

Evaluar:

```text
PaquetePlantilla
PaquetePlantillaItem
TourItinerarioItem
ComboExplosionService
ComboValidationService
```

y determinar si el modelo sigue siendo coherente con el nuevo modelo multi-destino.

---

# 27. COLUMNAS MUERTAS

Buscar todas las columnas y relaciones que:

- existen en schema;
- existen en modelos;
- pero ya no son utilizadas por ningún flujo.

Ejemplo conocido:

```text
alternativa_items.opcion_hotel_tarifa_id
alternativa_items.paquete_plantilla_id
```

Determinar:

```text
¿Eliminar?
¿Mantener temporalmente?
¿Migrar?
¿Reutilizar?
```

No eliminar nada.

Solo recomendar.

---

# 28. REGLAS DE NEGOCIO VS BASE DE DATOS

Auditar reglas como:

```text
uno de N campos
máximo 5 alternativas
una opción elegida
cupo
margen mínimo
proveedor referencial
estado
```

Determinar cuáles deberían ser:

```text
CHECK
UNIQUE
FK
índice parcial
validación de dominio
servicio de dominio
controller
```

Evitar colocar reglas de negocio complejas solamente en controllers.

Pero tampoco convertir todo en constraints de PostgreSQL si eso dificulta evolución.

---

# 29. BUGS REALES

Los siguientes problemas deben ser tratados como señales arquitectónicas:

### Orden de items

PostgreSQL no garantiza orden físico y ya hubo un bug real.

### Slug de proveedor tipo

Seeder y producción llegaron a tener valores inconsistentes.

### Vuelo de pasajeros

Se tuvo que separar:

```text
reserva_item_pasajero
```

de:

```text
reserva_item_vuelo_pasajero
```

para evitar que una acción de asignación eliminara accidentalmente información de vuelo.

Analizar qué nos enseñan estos bugs sobre:

- responsabilidades;
- acoplamiento;
- límites de entidades;
- normalización.

---

# 30. UX DEL COTIZADOR

Auditar el frontend real.

No diseñar todavía.

Determinar cuántos pasos necesita actualmente un vendedor para:

```text
Crear cliente
Crear cotización
Agregar destino
Agregar servicio
Agregar proveedor
Consultar mayorista
Agregar hotel
Modificar precio
Enviar cotización
Aceptar
Crear reserva
```

Comparar contra la experiencia deseada:

```text
Nueva cotización

Cliente
Pasajeros

Viaje

[ Tarapoto ]
servicios...

[ México ]
mayoristas...

[ Agregar destino ]

[ Cotizar ]
```

El sistema puede tener internamente muchas entidades.

El vendedor NO debería tener que administrarlas manualmente.

---

# 31. PRINCIPIO UX

Aplicar:

> **Complejidad interna, simplicidad externa.**

El frontend debe operar con conceptos de negocio.

No obligar al vendedor a pensar en:

```text
proveedor_servicios
proveedor_tarifas
opcion_mayorista
opcion_hotel
alternativa_items
reserva_items
```

si el sistema puede resolver esas relaciones automáticamente.

---

# 32. ARQUITECTURA OBJETIVO

Después de auditar el sistema, proponer una arquitectura objetivo.

No asumir previamente nombres.

Debe responder:

```text
¿Qué representa una solicitud?

¿Qué representa un viaje?

¿Qué representa una cotización?

¿Qué representa una alternativa?

¿Qué representa un item?

¿Qué representa un destino?

¿Qué representa un servicio?

¿Qué representa un proveedor?

¿Qué representa una propuesta de proveedor?

¿Qué representa una reserva?

¿Qué representa una operación?

¿Qué representa una obligación de pago?

¿Qué representa un pago?
```

---

# 33. DIAGRAMA

Producir un diagrama textual completo.

Ejemplo únicamente orientativo:

```text
Cliente
   │
   ▼
Solicitud / Expediente
   │
   ├── Itinerario
   │      ├── Destino A
   │      ├── Destino B
   │      └── Destino C
   │
   ├── Cotización
   │      ├── Alternativa A
   │      ├── Alternativa B
   │      └── Alternativa C
   │
   ├── Reserva
   │
   └── Operación
```

El diagrama final debe surgir del análisis, no de este ejemplo.

---

# 34. MATRIZ DE DECISIÓN

Entregar una tabla:

| Entidad actual | Problema | Decisión | Nueva entidad | Riesgo | Prioridad |
|---|---|---|---|---|---|
| Cotizacion | ... | conservar/refactorizar | ... | ... | ... |
| Alternativa | ... | ... | ... | ... | ... |
| AlternativaItem | ... | ... | ... | ... | ... |
| OpcionMayorista | ... | ... | ... | ... | ... |
| Reserva | ... | ... | ... | ... | ... |

Usar únicamente:

```text
CONSERVAR
REFACTORIZAR
EXTENDER
REEMPLAZAR
NUEVO
ELIMINAR
```

---

# 35. DECISIONES ABIERTAS

Crear una sección explícita:

```text
[DECISIÓN REQUERIDA]
```

para cualquier decisión que dependa del negocio.

No inventar decisiones.

Especialmente revisar:

```text
¿Una reserva por viaje o por tramo?

¿Un adelanto para todo el viaje o por tramo?

¿Pasajeros diferentes por tramo?

¿Moneda por tramo?

¿Cronograma por reserva o consolidado?

¿Quién define el cronograma de proveedor?

¿Se guarda proveedor en cotización o solamente al contratar?

¿El día del itinerario continúa entre tramos o se reinicia?
```

Si alguna de estas decisiones ya está cerrada en los documentos del proyecto, marcarla como:

```text
DECISIÓN CERRADA
```

y no volver a preguntarla.

---

# 36. PLAN DE REFACTORIZACIÓN

Después del análisis, crear un plan por fases.

No asumir fases previamente.

Cada fase debe indicar:

```text
Objetivo
Tablas afectadas
Modelos afectados
Controllers afectados
Services afectados
Frontend afectado
Migraciones
Datos existentes
Riesgo
Dependencias
```

Ejemplo:

```text
FASE 0
Preparación y tests

FASE 1
Modelo base del dominio

FASE 2
Multi-destino

FASE 3
Cotización

FASE 4
Mayoristas

FASE 5
Reserva

FASE 6
Operación

FASE 7
Cobros

FASE 8
Pagos a proveedores
```

Pero modificar completamente este orden si el análisis determina otro más seguro.

---

# 37. REGLA DE MIGRACIÓN

No asumir que debemos destruir y recrear el schema.

Evaluar:

```text
Migración incremental
```

vs.

```text
Refactor mayor
```

Para cada cambio indicar:

```text
¿Se puede migrar sin perder datos?

¿Qué datos requieren transformación?

¿Qué datos históricos deben preservarse?

¿Existe compatibilidad temporal?

¿Se puede hacer en dos etapas?

¿Se necesita script de backfill?
```

---

# 38. COMPATIBILIDAD

Analizar impacto sobre:

- cotizaciones existentes;
- alternativas existentes;
- reservas existentes;
- PDFs;
- facturación;
- reportes;
- operaciones;
- APIs;
- frontend;
- permisos;
- numeración;
- proveedores.

Una arquitectura nueva NO debe destruir información histórica.

---

# 39. TESTS

Antes de cualquier implementación futura, proponer tests para:

### Dominio

- precios;
- moneda;
- margen;
- descuentos;
- snapshots.

### Cotización

- alternativas;
- mayoristas;
- hoteles;
- múltiples destinos.

### Reserva

- congelamiento;
- pasajeros;
- fechas;
- items.

### Operación

- asignación;
- salidas;
- guías;
- proveedores.

### Finanzas

- anticipos;
- saldo;
- obligaciones;
- pagos;
- pagos consolidados.

### Seguridad

- proveedor oculto;
- información pública;
- documentos.

---

# 40. RESULTADO FINAL OBLIGATORIO

El informe final debe tener exactamente estas secciones:

## 1. Resumen ejecutivo

## 2. Arquitectura actual

## 3. Problemas arquitectónicos encontrados

## 4. Entidades correctamente diseñadas

## 5. Entidades con responsabilidades mezcladas

## 6. Modelo de dominio recomendado

## 7. Modelo multi-destino recomendado

## 8. Modelo de cotización recomendado

## 9. Modelo de mayoristas recomendado

## 10. Modelo de reserva recomendado

## 11. Modelo operativo recomendado

## 12. Modelo financiero recomendado

## 13. Moneda y tipo de cambio

## 14. Comercial vs operativo

## 15. Información pública vs interna

## 16. Comparación actual vs objetivo

## 17. Decisiones abiertas

## 18. Decisiones que ya están cerradas

## 19. Plan de refactorización

## 20. Riesgos

## 21. Tests necesarios

## 22. Recomendación final

---

# 41. REGLA MÁS IMPORTANTE

No intentes justificar la arquitectura existente.

No intentes justificar tampoco la arquitectura propuesta en los documentos.

Tu trabajo es encontrar la mejor arquitectura posible **a partir del negocio real + código real + datos existentes**.

Si el sistema actual está mejor que nuestra propuesta:

> conservarlo.

Si nuestra propuesta es mejor:

> justificar el cambio.

Si ambas tienen problemas:

> proponer una tercera alternativa.

---

# 42. CRITERIO DE CALIDAD

La arquitectura recomendada debe cumplir simultáneamente:

```text
✓ Correcta para el negocio real
✓ Fácil de mantener
✓ Extensible
✓ Histórica
✓ Segura
✓ Compatible con facturación
✓ Compatible con operación
✓ Compatible con proveedores
✓ Compatible con múltiples monedas
✓ Compatible con múltiples destinos
✓ Compatible con cotizaciones ad-hoc
✓ Compatible con catálogo
✓ Compatible con mayoristas
✓ Compatible con servicios personalizados
✓ UX rápida para el vendedor
```

Pero evitar:

```text
✗ Sobreingeniería
✗ Entidades innecesarias
✗ Abstracciones genéricas sin necesidad
✗ Polimorfismo solamente por "flexibilidad"
✗ Duplicación de datos
✗ Campos que representan múltiples conceptos
✗ Lógica crítica únicamente en Vue
✗ Dependencia de "local/nacional/internacional"
✗ Dependencia innecesaria del proveedor
✗ Dependencia innecesaria del catálogo
```

---

# 43. INSTRUCCIÓN FINAL

Antes de emitir cualquier recomendación:

1. Leer todos los documentos indicados.
2. Inspeccionar el código real.
3. Inspeccionar migrations.
4. Inspeccionar modelos.
5. Inspeccionar controllers.
6. Inspeccionar services.
7. Inspeccionar frontend.
8. Inspeccionar rutas.
9. Buscar referencias reales de cada entidad.
10. Buscar columnas y relaciones sin uso.
11. Buscar duplicación de lógica.
12. Buscar contradicciones entre documentación y código.
13. Reconstruir los flujos reales.
14. Comparar contra los casos de negocio.
15. Recién después proponer la arquitectura.

NO escribir código.

NO implementar.

NO modificar archivos.

NO hacer commits.

La tarea termina cuando exista un diagnóstico suficientemente sólido para que el equipo pueda tomar la decisión:

> **"Continuamos sobre la arquitectura actual"**

o:

> **"Hacemos un refactor estructural antes de continuar."**

La recomendación debe ser explícita y estar justificada.