# Créditos — Fase 3: API (backend)

Leer junto con `00-reglas-y-modelo.md` (las reglas se citan por número, no se repiten aquí).
Rama sugerida: `feat/creditos-fase3-api` desde `feat/creditos-fase1-motor`.
**Antes de codificar**: mostrar lista de rutas, FormRequests, servicios y sus firmas públicas; esperar aprobación.

## Alcance
Núcleo de créditos con caja. **Fuera de esta fase**: garantes, prendas, cobradores/cartera (fases 6-8), documentos PDF (fase 4b), reportes y foto diaria (fase 4c), frontend (fase 4).

## Arquitectura
- Servicios en `App\Services\Creditos\`: orquestan transacción → carga de estado → **motor** (`Motor\*`) → persistencia → caja → auditoría. Ninguna regla de cálculo fuera del motor.
- Un "cargador" que arma los DTOs del motor desde Eloquent (condiciones, cuotas vigentes, pagos válidos, condonaciones, cargos, castigos, feriados, config) y un "persistidor" que escribe el `ResultadoAplicacion` (aplicaciones nuevas `generacion+1`, acumulados de cuotas, estados). Reutilizable por cobro, anulación, retroactivo y liquidación.
- Toda escritura: `DB::transaction` + `lockForUpdate()` del crédito + `clave_idempotencia` (misma clave → devuelve el resultado anterior, no duplica) + registro en `credito_auditoria`.
- Controllers delgados: FormRequest → servicio → API Resource. Montos de entrada solo `monto_recibido`/`capital`; todo total lo calcula el backend.
- Rutas en el grupo tenant autenticado existente + `permission:creditos.*`. "Hoy" = `America/Lima`.

## Endpoints
| Método y ruta | Acción | Permiso | Reglas |
|---|---|---|---|
| `GET creditos` | Listado con filtros (estado, cliente, atraso) | ver | 1.14 |
| `POST creditos/preview` | Cronograma + resumen sin guardar | crear | 1.1-1.3 |
| `POST creditos` | Crear borrador (+ advertencias de límites) | crear | 1.10 |
| `PUT creditos/{id}` | Editar borrador | crear | 1.9 |
| `POST creditos/{id}/activar` | Límites (bloqueo) + número + contrato pendiente + desembolso en caja | crear | 1.10, §3 |
| `GET creditos/{id}` | Detalle: resumen, cuotas vigentes, exigible hoy | ver | 1.4 |
| `GET creditos/{id}/estado-cuenta` | Pagos, aplicaciones, condonaciones, cargos | ver | — |
| `POST creditos/{id}/pagos/cotizar` | Cómo se aplicaría un monto + excedente | cobrar | 1.4-1.5 |
| `POST creditos/{id}/pagos` | Cobrar (destino de excedente) | cobrar | 1.4-1.6, §3 |
| `POST creditos/{id}/pagos/{pago}/anular` | Extorno con recálculo + bloqueos | anular propio / anular_pago | 1.8 |
| `PATCH creditos/{id}/pagos/{pago}` | Solo referencia/observaciones | cobrar | 1.8 |
| `GET creditos/{id}/liquidacion?fecha=` | Cotizar liquidación | cobrar | 1.7 |
| `POST creditos/{id}/liquidar` | Liquidar (recalcula, `es_cierre`) | cobrar | 1.7 |
| `POST creditos/{id}/corregir` | Nueva versión de cronograma (sin pagos) | corregir | 1.9 |
| `POST creditos/{id}/anular` | Anular crédito sin pagos + reverso desembolso | corregir | 1.9 |
| `POST creditos/{id}/reprogramar/preview` y `POST creditos/{id}/reprogramar` | Reprogramar fechas | reprogramar | 1.9 |
| `POST creditos/{id}/condonar-mora` | Condonar mora (total/parcial) | condonar_mora | 1.6 |
| `POST creditos/{id}/castigar` y `POST creditos/{id}/revertir-castigo` | Castigo manual / reversión | castigar | 1.11 |
| `POST creditos/{id}/renovar/preview` y `POST creditos/{id}/renovar` | Renovación | crear | 1.12 |
| `POST creditos/migrar` | Registrar crédito existente | migrar | 1.12 |
| `POST creditos/{id}/pagos` con `fecha_pago` pasada | Pago retroactivo | pago_fecha_anterior | 1.12 |
| `GET creditos/cobranza-del-dia` | Cuotas que vencen hoy + vencidas, con exigible | cobrar | 1.4 |
| `GET/PUT clientes/{id}/ficha-credito` + archivos | Ficha de cobro | ver / crear | — |
| `GET clientes/{id}/resumen-credito` | Créditos activos, deuda, disponible, puntualidad (para el formulario) | crear | 1.10 |
| `GET/PUT creditos/configuracion` · CRUD `feriados` | Config del tenant | configurar | — |
| `POST creditos/{id}/autorizaciones` | Autorizar excepción de límite | autorizar_excepcion | 1.10 |

## Procesos diarios (scheduler existente)
- `creditos:escalamiento` (madrugada, America/Lima): bloqueo por atraso, castigo automático a `dias_para_castigo`, marca de "cobrar al garante". Idempotente; audita cada cambio de estado.
- La foto diaria de cartera y `apta_para_venta` van en sus fases (4c y 7).

## Validaciones (FormRequest)
Capital > 0 y múltiplo de 0.10 · tasa entre 0 y `tasa_maxima` · cuotas 1..`max_numero_cuotas` · fechas coherentes · `destino_excedente` válido · `clave_idempotencia` requerida en escrituras · fecha retroactiva ≤ `dias_max_pago_retroactivo` · motivo obligatorio en anular/corregir/reprogramar/condonar/castigar/autorizar/retroactivo.

## Tests de la fase (parte "persistencia y caja" de §8/12.9)
- Activar: número asignado solo al activar; desembolso en caja; bloqueo por límites y autorización con motivo; propio garante no autorizable.
- Cobro: aplicaciones y acumulados correctos; excedente devolver (salida de caja, caja cuadra), adelanto, saldo a favor; `PagoExcedeDeuda`; idempotencia (doble envío = 1 pago); concurrencia (dos cobros simultáneos no duplican aplicación).
- Anulación: pago intermedio → resultado igual a registrar solo los válidos; reverso de caja con sesión cerrada; bloqueo por cierre insuficiente y por reprogramación posterior; permiso propio vs ajeno.
- Liquidación 3,700 del ejemplo 1.7 persistida con cuotas `pagada` y 500 condonado; anular liquidación reabre el crédito.
- Corregir (versión 2), anular crédito, reprogramar (+10 días, mora mantenida/condonada, cargo), condonar.
- Castigo manual/automático, reversión sin mora retroactiva, pago como recupero, cliente bloqueado.
- Renovación: caja solo salida neta 700; crédito anterior cerrado sin caja.
- Migración: modo rápido y detallado, sin caja, límites solo advierten.
- Retroactivo: reaplica posteriores; rechazo fuera de plazo; caja en sesión actual.
- Cobrador/permisos: rutas protegidas; errores sin datos internos.

## Verificar en código antes de empezar
- Cómo `CashCorrectionService` y las sesiones de caja se usan desde otro módulo (firma real).
- Scheduler/cron existente y cómo se registran comandos por tenant.
- Almacenamiento de archivos por tenant (ficha: DNI/foto) y límite de tamaño.

## Decisiones aprobadas (2026-09-30)
1. **Idempotencia general**: tabla `credito_operaciones` (`clave` unique, `operacion`, `credito_id`, `usuario_id`, `hash_solicitud`, `respuesta` json, `created_at`). Misma clave + mismo hash → misma respuesta; misma clave + distinto contenido → 409. `credito_pagos.clave_idempotencia` se mantiene como garantía en BD.
2. **Anular renovación** = anular el crédito nuevo (solo si no tiene pagos válidos; si los tiene, primero se anulan): revierte la salida neta de caja, anula el pago `renovacion` y reabre el crédito anterior.
3. **Corregir con cambio de capital**: reverso completo del desembolso original + nuevo desembolso por el capital corregido (3 movimientos en caja).
4. **Visibilidad del cobrador**: permiso explícito nuevo `creditos.ver_todos` (Admin y Cajero lo tienen; Cobrador no). Sin él, listado, detalle, cobro y cobranza del día se filtran por `cartera_asignaciones` vigentes por cliente; sin asignaciones no ve nada. Endpoint mínimo `PUT clientes/{id}/cobrador` (`creditos.cartera.asignar`) para asignar/reasignar con historial (la gestión completa de cartera sigue en fase 8).
5. **Escalamiento**: bloqueo por atraso y "cobrar al garante" se calculan en vivo (no se guardan). El job `creditos:escalamiento` solo castiga automáticamente (`credito_castigos` + `credito_auditoria`). El bloqueo por castigo también es derivado; el admin lo supera con la autorización de excepción (1.10), sin flag adicional.