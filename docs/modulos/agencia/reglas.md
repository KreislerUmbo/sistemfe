# Agencia de Viajes — Reglas

> Qué hace el sistema **hoy** (o debe hacer, en lo marcado *en construcción*). Se edita en su
> lugar, sin historial: el historial es `git log`. El estado de las tareas está en
> `docs/backlog.md`.
> **En formación (24-oct-2026):** se completa a medida que una tarea toca cada parte del
> módulo (`docs/proceso.md` §6). Lo que todavía no está aquí sigue en `docs/planning/
> agencia-de-viajes/` (puerta de entrada: `00-mapa-de-modulos.md`).

## Modificar una reserva aceptada — *en construcción (T-002 a T-007)*

Decisiones: D-AGE-030, D-AGE-031, D-AGE-032, D-AGE-033.

- **R-AGE-01** — Una reserva activa tiene como máximo **una modificación abierta**. No se
  puede modificar una reserva cancelada.
- **R-AGE-02** — Una modificación es una cotización propia (`origen = 'modificacion'`), con
  copia de los pasajeros actuales de la reserva y de la alternativa vigente. No aparece en los
  listados comerciales ni se puede aceptar por el camino normal.
- **R-AGE-03** — La versión vendida no se edita nunca. La diferencia se calcula por línea y
  por pasajero, comparando la versión vigente con la nueva: `agregado`, `quitado`,
  `cambió de precio`, `igual`, `para revisar`, `requiere nota de crédito`.
- **R-AGE-04** — Lo facturado no se modifica ni se quita al aplicar. Una línea de tour con
  cualquier parte facturada cuenta como facturada. Si su precio sube, la diferencia entra como
  servicio "Ajuste — <servicio>"; si baja, o se quita algo facturado, requiere nota de crédito.
- **R-AGE-05** — Cada pasajero no facturado que se quita lleva exactamente una penalidad: un
  servicio con monto (≥ 0), motivo obligatorio y tratamiento de IGV elegido, sin valor por
  defecto.
- **R-AGE-06** — No se aplica mientras haya líneas `para revisar` sin confirmar,
  `requiere nota de crédito` sin resolver, o pasajeros quitados sin penalidad.
- **R-AGE-07** — Aplicar es todo o nada: en una transacción, con lock de la reserva y la
  diferencia recalculada adentro. Si no coincide con la que vio el usuario, no se aplica y se
  avisa.
- **R-AGE-08** — Al aplicar, la reserva conserva sus datos operativos (nombres, documentos,
  check-in, salidas, fechas manuales, proveedores reasignados, vuelos de agencia). Los
  servicios no facturados toman el precio de la versión nueva; los facturados siguen en la
  versión vendida, que queda `reemplazada`.
- **R-AGE-09** — Toda modificación aplicada o descartada queda registrada: quién, cuándo, la
  diferencia y las penalidades. Se puede pedir un PDF de la modificación para el cliente.
- **R-AGE-10** — Modificar una reserva requiere el permiso `reservas.modificar`, además de
  poder editar esa reserva (`ReservaPolicy::update`).
- **R-AGE-11** — El precio o el descuento de un servicio de una alternativa aceptada no se
  puede cambiar desde el cotizador (T-001).
