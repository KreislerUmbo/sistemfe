# Adicional (para más adelante) — Crédito "Solo interés"

Estado: **diseñado, NO construido** (08-oct-2026). Decisión del usuario: guardarlo para más adelante y
construirlo solo si lo que ya existe no alcanza; antes de construir, madurarlo de nuevo con él.
Decisión tomada: **opción A** del interés mínimo (ver abajo).

## Mientras tanto: cómo se resuelve hoy
Préstamo en el que el cliente paga solo el interés cada mes y el capital "se vuelve a prestar"
(ej.: 1,500 y paga 100 al mes):
- Crear el crédito: capital 1,500 · tasa **6.6667 %** mensual (1,500 × 6.6667 % = 100.0005 → 100.00 con
  el redondeo a 0.10) · **1 cuota** mensual → cuota de 1,600.
- Cada mes, al recibir los 100: **Renovar** por 1,500 → la pantalla dice "El cliente paga S/ 100"
  (renovación ampliada, 1.21, en producción desde el 08-oct-2026). Cada mes es un crédito nuevo,
  encadenado por `credito_renovado_id`.
- Para devolver todo: **Liquidar**.
- Límites de esta forma: si se atrasa, la mora corre sobre la cuota completa (1,600), no sobre los 100
  (se puede crear el crédito sin mora o condonarla); y cada mes genera un número y un contrato nuevos.
Este adicional existe para resolver esos dos límites cuando el esquema se vuelva habitual.

## Qué es
Cada período el cliente paga solo el interés y el capital se devuelve en el último pago.
Ej.: 1,500 al 6.6667 % mensual en 12 meses → cuotas 1-11 de **100** (capital 0) y la cuota 12 de
**1,600** (1,500 + 100).

## Configuración
- Créditos › Configuración (`credito_configuracion`):
  - `solo_interes_habilitado` (bool, default `false`): sin esto nadie ve la opción y el backend la rechaza.
  - `metodo_calculo_default` (`simple_fijo` | `solo_interes`, default `simple_fijo`): forma preseleccionada
    en los créditos nuevos.
- Por crédito: selector **"Forma de pago"** — *Cuotas iguales* (capital + interés en cada cuota) o
  *Solo interés* (capital al final) — en Nuevo, Renovar, Corregir y Registrar crédito existente.
  Vista previa del cronograma al instante. Renovar hereda la forma del crédito anterior. Corregir puede
  cambiarla mientras no haya pagos (igual que el resto de condiciones, 1.9).
- Validación: mínimo **2 cuotas** (con 1 es idéntico a cuotas iguales).

## Comportamiento
| Tema | Cómo queda |
|---|---|
| Cronograma | El interés se reparte igual en todas las cuotas (misma regla de redondeo, 12.2); el capital va completo en la última. Único cambio de cálculo |
| Cobrar | Sin cambios: cada mes "Cobrar 100" |
| Liquidar | Sin cambios en la fórmula (1.7): capital + interés de períodos vencidos + proporcional del período en curso + mora + cargos, con el interés mínimo (ver decisión) |
| Mora | Sobre el saldo de la cuota atrasada: **100** en las cuotas de interés; 1,600 solo en la última |
| Abonar parte del capital | Con **Renovar** por el capital menor: "El cliente paga S/ 600" (100 interés + 500 capital); desde ahí el interés mensual baja |
| Al terminar las cuotas, si sigue | Renovar, como hoy |
| Contrato | Variable nueva `{forma_pago}`: "Pagos periódicos solo de interés; el capital se devuelve en el último pago" (o "Cuotas iguales de capital e interés"). La tabla del cronograma ya muestra cada cuota |
| Detalle / listado | Etiqueta "Solo interés" |
| Reportes, límites, panel | Sin cambios (trabajan con saldo de capital y cuotas) |

## Decisión: interés mínimo al liquidar antes — **opción A (elegida)**
Hoy liquidar antes cobra como mínimo `tasa_interes_minimo` (10 %) del capital: con 1,500 son 150, y
quien devuelve todo al mes (ya pagó 100) pagaría 50 de más.
**A:** al elegir "Solo interés", el formulario sugiere `tasa_interes_minimo` = la tasa de **un período**
(6.6667 % en el ejemplo): liquidar antes cobra como mínimo el período en curso. Editable por crédito.
(B, descartada: mantener el 10 % de la configuración.)

## Cambios técnicos previstos
- Migración `tenant/core/`: `creditos.metodo_calculo` admite `solo_interes` (reemplazar el check del enum
  en Postgres); `credito_configuracion` + `solo_interes_habilitado`, `metodo_calculo_default`.
  Aplicar con `tenants:migrate-verticales`.
- Motor: `MetodoCalculo::SoloInteres`; `GeneradorCronograma::generar()` reparte el capital según el método
  (todo en la última cuota). `CalculadoraLiquidacion`, `CalculadoraMora` y `AplicadorPagos` no cambian.
- Pasar el método: `DatosCredito`, `CondicionesCredito`, `CargadorCredito::condiciones()`,
  `CreditoDatosRequest` (+ regla "habilitado" y "mínimo 2 cuotas"), `CreditoBorradorService::atributos()`
  / `datosDe()`, `MigracionService`, `RenovacionService` (hereda), `CreditoResource` / `FormatoCredito`.
- Documentos: variable `{forma_pago}` en `PlantillaContratoService::VARIABLES` y su texto en
  `FormatoDocumento`.
- Frontend: selector en `FormCondiciones.vue` (solo si está habilitado; al elegir Solo interés sugiere el
  interés mínimo de un período), opciones en `configuracion.vue` / `helpers/creditos/configuracion.ts`,
  etiqueta en detalle y listado, tipos en `types/creditos.ts`.
- Tests: cronograma (11 × 100 + 1,600; redondeo), liquidación anticipada con mínimo de un período, mora
  sobre 100, abono de capital vía renovación, configuración apagada → 422, corregir el método sin pagos,
  renovación que hereda el método.

## Antes de construir
Revisar con el usuario si sigue haciendo falta (puede que la renovación mensual alcance) y si aparecieron
casos nuevos: plazo abierto sin número fijo de cuotas, interés semanal/quincenal, abonos a capital sin
renovar.
