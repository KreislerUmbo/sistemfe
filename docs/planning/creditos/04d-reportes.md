# Fase 4d — Panel de inicio y reportes

Estado: **diseño aprobado** (02-oct-2026), con el reporte 8 (Agenda de cobranza) agregado a pedido del
usuario. No hay código escrito de esta fase.
Reglas de fondo: Plan §1.16 (reportes) y `00-reglas-y-modelo.md`. Sale de la rama
`feat/creditos-fase4c1-ajustes`.

## Principios (Plan §1.16, se mantienen)
- **Una sola fuente de verdad:** saldos, mora y atraso salen de las mismas funciones del motor
  (`CargadorCredito` + `AplicadorPagos` + `SaldoCredito`), nunca de fórmulas SQL paralelas.
- **Fecha de corte** en todo reporte ("al día X"): mismo corte = mismo resultado.
- **Montos en centavos**; los totales son la suma exacta de las filas.
- **Base caja** para ingresos: interés y mora "ganados" = efectivamente cobrados. Se dice en pantalla.
- Cada indicador tiene un "¿cómo se calcula?" en lenguaje simple.
- **Según rol:** quien no tiene `creditos.ver_todos` ve solo su cartera (`AlcanceCartera`), también en reportes.
- Meta: < 2 s; listados paginados.

## Qué existe y qué falta
| Pieza | Estado |
|---|---|
| Tabla `credito_cartera_diaria` (foto por crédito y día) | Existe, **vacía**: nadie la llena. Tiene `cobrador_id`; **falta `asesor_id`** |
| Datos de ingresos (aplicaciones por concepto, condonaciones, interés descontado, castigos) | Existen todos |
| Gráficos | ApexCharts (registrado global en `main.ts`, lo usa la plantilla Rizz) |
| Exportar | DomPDF y `maatwebsite/excel` ya instalados (los usa Caja) |
| Mockup del panel | `mockups/5-panel-inicio.html` (contenido, no estilo) |

## Reportes v1
| # | Reporte | Contenido | Quién lo ve |
|---|---|---|---|
| 1 | **Panel de inicio** | Cartera activa (capital por cobrar, n.º de créditos y clientes) · Por cobrar hoy vs cobrado hoy (%) · Cartera en riesgo % · Caja vs créditos (cuadra / no cuadra) · Cobrado últimos 14 días (gráfico) · Los 10 más atrasados | Todos; cada uno sobre su cartera |
| 2 | **Cartera** | Créditos activos y castigados: cliente, asesor, capital prestado, saldo capital, saldo interés, mora, pagos hechos/total, próximo vencimiento, días de atraso. Filtros: asesor, rango de atraso, estado | Todos (su cartera) |
| 3 | **Morosidad** | Rangos 1-7 · 8-15 · 16-30 · 31-60 · +60 días (cantidad y saldo) + % cartera en riesgo, total y **por asesor** | Todos (su cartera) |
| 4 | **Ingresos y desembolsos** | Por día / semana / mes: capital prestado, capital recuperado, interés cobrado, mora cobrada, cargos cobrados, mora condonada, interés descontado por liquidación anticipada, saldo a favor devuelto | Solo `creditos.ver_todos` |
| 5 | **Por asesor** | Por asesor y período: créditos colocados (cantidad y capital, según `creditos.asesor_id`), cobrado, cartera vigente, % en riesgo. Base para comisiones (no las calcula) | Solo `creditos.ver_todos` |
| 6 | **Castigados y recuperos** | Castigados (cliente, fecha, saldo al castigar) y recuperos cobrados por período | Solo `creditos.ver_todos` |
| 8 | **Agenda de cobranza** (vencimientos próximos) | Quiénes vencen mañana, pasado mañana, en los próximos 7 días o en una fecha/rango, para organizar la logística de cobro. Ver sección propia | Todos (su cartera: el cobrador ve su agenda) |
| 7 | **Control y auditoría** | Anulaciones de pagos, correcciones, condonaciones, autorizaciones de excepción, traspasos de cartera, devoluciones de saldo: quién, cuándo, monto, motivo. Alerta si un usuario supera `umbral_alerta_anulaciones` | Solo `creditos.ver_todos` |

**Fuera de 4d:** "Cobrar al garante" (Fase 7, garantes) y promesas/gestiones (Fase 8, cobradores).
La **Cobranza del día** ya existe como pantalla operativa; no se duplica.

## Reporte 8 — Agenda de cobranza
Mira **hacia adelante** (la Cobranza del día solo muestra hoy y lo atrasado).
- **Accesos rápidos:** Mañana · Pasado mañana · Próximos 7 días · Fecha o rango (hasta 31 días).
- **Agrupado por día** y, dentro, **por cobrador** (o asesor si "el asesor también cobra"), con totales
  por día y por cobrador: cuotas, clientes y monto a cobrar.
- **Cada fila:** cliente, teléfono (llamar / WhatsApp), dirección de cobro + referencia + distrito (para
  armar la ruta) y enlace al mapa, crédito y n.º de cuota ("3 de 30"), forma de pago, monto pendiente
  de esa cuota, y marca **"con atraso"** si el cliente ya debe otra cuota.
- **Monto:** lo pendiente de la cuota (total − pagado: los adelantos ya aplicados se descuentan). Una
  cuota que todavía no vence no tiene mora. Las fechas ya vienen corridas por domingos y feriados.
- **Opción "incluir atrasados"** para planificar la ruta completa del día (lo vencido sigue pendiente).
- **Filtros:** cobrador/asesor, distrito.
- **Recordatorio por WhatsApp** por cliente: abre WhatsApp con un mensaje ya escrito ("Hola X, le
  recordamos su pago de S/ Y que vence el …"). Manual; el envío automático queda para después.
- **Exportar:** PDF "hoja de ruta" con **una página por cobrador** (para imprimir y llevar) y Excel.

## Definiciones (las del "¿cómo se calcula?")
- **Cartera activa** = saldo de capital de créditos `activo` (los castigados van aparte; Plan §1.19).
- **Cartera en riesgo %** = saldo de capital de créditos con atraso mayor a sus días de gracia ÷
  cartera activa (criterio SBS).
- **Por cobrar hoy** = exigible a hoy (cuotas que vencen hoy + vencidas + mora), igual que la
  Cobranza del día. **Cobrado hoy** = pagos válidos de hoy (cobro + liquidación).
- **Ingresos (base caja):** aplicaciones **vigentes** de pagos **válidos** por concepto, agrupadas por
  la fecha del pago. **No se cuentan** los pagos `saldo_inicial` (anteriores al sistema, registrados al
  migrar) ni el uso de saldo a favor como ingreso nuevo (ese dinero ya entró cuando se generó).
- **Interés descontado** = `interes_condonado` de las cuotas cerradas por liquidación anticipada, en la
  fecha de la liquidación.
- **Caja vs créditos:** cobrado en créditos del día = entradas de caja `credito_pago` − vueltos
  `credito_devolucion_excedente` del día. Si no cuadra: alerta con la diferencia.

## Foto diaria de cartera
- Comando `creditos:foto-cartera` a las 23:50 (hora de Lima), por tenant de giro créditos, igual que
  el castigo automático (tolerante a errores). Guarda por crédito: saldo capital, saldo interés, mora,
  días y rango de atraso, estado, **asesor** y cobrador del día.
- Migración: `credito_cartera_diaria.asesor_id`.
- Sirve para **tendencias e históricos** ("¿cómo estaba la morosidad a fin de mes?"). Los reportes de
  **hoy** se calculan en vivo con el motor.
- **Velocidad en vivo:** carga en bloque (créditos + cuotas vigentes + pagos válidos en pocas
  consultas, no una por crédito) y caché de 5 minutos por tenant y usuario. Se mide con un dataset de
  500 créditos; si no llega a < 2 s, el panel lee la foto de la noche + los pagos de hoy.

## Pantallas
- **Menú Créditos:** "Panel" primero y "Reportes" (una página con pestañas por reporte).
- Todas con el estilo de Agencia/Ventas (cards con `card-header`, `-sm`, `table-sm`); tarjetas en
  celular, tablas en escritorio; filtros rápidos Hoy · Esta semana · Este mes · Rango.
- **Clic en cualquier número → detalle** (tarjeta → lista filtrada → crédito).
- Exportar cada reporte a **PDF** (con logo, "generado por" y fecha, como el Reporte Operativo de
  Agencia) y a **Excel**.

## Permiso nuevo
`creditos.reportes`: ver Reportes (Administrador y Cajero). El Panel solo pide `creditos.ver`.
Los reportes marcados "Solo `creditos.ver_todos`" además lo exigen en el backend.

## Orden de construcción
1. Servicio de consultas en bloque + definiciones con tests de dataset conocido (cada indicador con
   resultado esperado exacto), foto diaria (comando + migración `asesor_id`).
2. API de los 7 reportes (filtros, corte, alcance de cartera) + exportación PDF/Excel.
3. Panel de inicio.
4. Agenda de cobranza (la más usada en el día a día: va antes que el resto de pestañas).
5. Página Reportes con las demás pestañas.
6. Verificación en creditos-demo (con datos reales del demo) y medición de tiempos.

## Decisiones (aprobadas 02-oct-2026)
1. El **Panel** reemplaza al Dashboard genérico de la plantilla como página de inicio del tenant de créditos.
2. Exportar a **PDF y Excel**.
3. Los **pagos de créditos migrados** (anteriores al sistema) no cuentan como ingresos del período.
4. Se agrega el **reporte 8, Agenda de cobranza** (pedido del usuario).
