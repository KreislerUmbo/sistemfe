# T-006 — PDF de la modificación para el cliente

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-003 (T-004 para que salga la penalidad) |
| **Reglas** | R-AGE-09 |
| **Decisiones** | — (contenido acordado: requerimiento, pregunta 6) |
| **Rama** | `feat/T-006-pdf-modificacion` |

## Contexto
Antes de aplicar, el vendedor le manda al cliente qué cambia y cuánto. El PDF se arma con la
diferencia de T-003 (no recalcula nada propio) y con el mismo membrete que el PDF de
cotización.

## Alcance
- `GET reservas/{id}/modificacion/pdf`: la modificación abierta. Con `?modificacion_id=` puede
  pedir una ya aplicada; en ese caso usa `diferencia_aplicada`, no recalcula.
- Contenido:
  - membrete (`MembreteAgenciaPdfService`);
  - código de la reserva y fecha de emisión (`HoraPeru`);
  - cliente;
  - **solo las líneas que cambian** (agregado, quitado, cambio de precio, ajuste) con lo
    vendido y lo nuevo;
  - pasajeros agregados y quitados;
  - penalidades con su motivo;
  - totales: vendido, nuevo, diferencia, ya facturado o pagado (anticipos), saldo.
- Nombres de los servicios en audiencia cliente: `ReservaController::resolverNombreItem(...,
  'cliente')`, para no filtrar el mayorista (ver el fix C1).
- Logo con `StorageUrl::resolveParaPdf()` (DomPDF no carga URLs remotas).
- Nombre de archivo: `modificacion_<codigo_reserva>_<DD-MM-YYYY>.pdf`.

## Fuera de alcance
- Enviarlo por correo (después, sobre `CotizacionEnvioController`).
- Cambiar el PDF de cotización.

## Archivos probables
- `app/Services/AgenciaViajes/ModificacionPdfService.php` (nuevo)
- `resources/views/pdf/agencia/modificacion-reserva.blade.php` (nuevo)
- `ReservaModificacionController`
- `tests/Feature/AgenciaViajes/ReservaModificacionPdfTest.php`

## Criterios de aceptación
- [ ] **CA1** — El PDF se genera (200, `application/pdf`) para una modificación abierta con un
  pasajero agregado y una penalidad.
- [ ] **CA2** — Sale solo lo que cambia; una línea `igual` no aparece.
- [ ] **CA3** — Los totales del PDF coinciden con los de `GET …/diferencia`.
- [ ] **CA4** — Con un mayorista, el PDF no muestra su nombre (`descripcion_publica`).
- [ ] **CA5** — El logo se ve. Verificación visual: rasterizar con Ghostscript y revisar la
  imagen (ver la memoria `reference_pdf_visual_verification_ghostscript`).
- [ ] **CA6** — Para una modificación aplicada, sale lo que se aplicó aunque la reserva haya
  cambiado después.
- [ ] **CA7** — Permisos: sin `reservas.modificar` o sin poder ver la reserva, 403.

## Seguridad y correctitud
- [ ] Permisos / Policy: igual que T-002
- [ ] Aislamiento de tenant: logo vía `StorageUrl::resolveParaPdf()`
- [ ] Validación de entrada: `modificacion_id` debe ser de **esta** reserva
- [ ] Concurrencia: no aplica
- [ ] Dinero en centavos: se muestra lo que da T-003, formateado una sola vez
- [ ] Fechas con `HoraPeru`: fecha de emisión e instantes con `HoraPeru::deUtc()`
- [ ] Tributario: no aplica
- [ ] `v-html` / escape: textos libres (motivo) con `{{ }}`, nunca `{!! !!}`
- [ ] Migraciones: no aplica

## Cómo verificar
```
php artisan test --filter=ReservaModificacionPdf
```
Generar uno real en `agencia-demo` y rasterizarlo con `gswin64c.exe` para revisarlo.

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
