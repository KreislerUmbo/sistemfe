# Inicio con datos reales (Dashboard) — retail y agencia de viajes

Estado: **construido** (02-oct-2026). Reemplaza la demo de la plantilla Rizz
(`views/dashboards/analytics`: visitas, navegadores, tráfico; datos inventados). El giro
`creditos` no usa esta pantalla: tiene su Panel (`docs/planning/creditos/04d-reportes.md`) y la
ruta `/admin` lo redirige allí.

## Decisiones (aprobadas por el usuario, 02-oct-2026)
1. Montos **separados por moneda** (PEN, USD), sin convertir con tipo de cambio.
2. **Ventas netas** = ventas − notas de crédito aceptadas, con el bruto al lado.
3. La **Nota de Venta interna** cuenta como venta, informada aparte de lo fiscal.
4. En el menú, "Dashboards" pasa a llamarse **"Inicio"**.

## Cómo funciona
- `GET dashboard` (`DashboardController` → `App\Services\Dashboard\DashboardService`): sin
  `permission:` propio (como `me/menu`); cada bloque sale solo si el giro lo usa y el usuario tiene
  el permiso de la pantalla de origen. El backend suma todo; el frontend solo muestra.
- Fecha de negocio de una venta: `sales.date` (emisión); si falta, `created_at` en hora de Lima.
- Las ventas excluyen comprobantes de adelanto (`type='advance'`) y eliminadas.

| Bloque | Giro | Permiso | Origen del dato |
|---|---|---|---|
| Ventas hoy / mes (+ variación vs. mes anterior a la misma fecha) | ambos | `list_sale` | `sales` + `notes` 07 aceptadas |
| Ventas últimos 30 días (bruto, una moneda a la vez) | ambos | `list_sale` | `sales` |
| Pendientes SUNAT (por enviar = sin correlativo; con error = correlativo sin CDR; notas) | ambos | `list_sale` (+ `list_nota_electronica` para notas) | `Sale::soloDocumentosFiscales()` (incluye adelantos; la NV nunca va a SUNAT) |
| Por cobrar (saldo y vencido por moneda) | ambos | `list_sale` | `CreditSummaryCalculator`, igual que Cuentas por Cobrar |
| Más vendidos del mes (por cantidad) y sin stock | retail | `list_sale` | `sale_details`; stock solo de productos con `controla_stock`, sin el producto ficticio `ADELANTO-001` |
| Cotizaciones del mes (estado resumido, conversión) | agencia | `cotizaciones.ver(_todas)` | `Cotizacion::propias()` + `estadoResumen()` |
| Próximos 7 días: viajes, salidas operativas, servicios sin asignar | agencia | `reservas.ver(_todas)` | `Reserva::propias()`, `SalidaOperativa`, `AsignacionOperativa` |
| Cajas abiertas | ambos | `cash.view_all` | `cash/dashboard` existente (lo llama el frontend) |

`AsignacionOperativa` (nuevo, `app/Services/AgenciaViajes/`) es el criterio de "sin guía o
proveedor" extraído del Reporte Operativo, sin cambiarlo (sus 23 tests siguen verdes).

## Verificación
- `tests/Feature/Dashboard/DashboardTest.php`: 7 tests con resultado exacto (neto con NC
  aceptada/rechazada, NV, USD aparte, comparación con el mes anterior, fecha de negocio, SUNAT,
  por cobrar con vencidas, top y sin stock, agencia, permisos y giro).
- Vitest `helpers/dashboard.test.ts` (formato por moneda, variación, fecha).
- Navegador: umbo y agencia-demo sin errores ni desborde a 360 px; créditos sigue en su Panel.

## Hallazgo corregido de paso (Caja)
`CashSessionController::dashboard()` calculaba `elapsed_hours` con `diffInHours()`, que en
Carbon 3 devuelve decimales y con signo ("Abierta hace 186.42596101166663 h" en Caja › Historial).
Corregido a horas enteras absolutas (aprobado por el usuario), con
`tests/Feature/Cash/CashDashboardHorasTest.php`. Era el único `diffInHours()` del código. Misma
familia que `project_carbon3_diffindays_sign_bug` en memoria.

## Al desplegar
`MenuItemsSeeder` (renombra a "Inicio") + limpiar la caché de menú de cada tenant.
