# T-038 — Monto en letras del XML según la moneda de la venta (H27)

| | |
|---|---|
| **Módulo** | VEN (todos los giros) |
| **Depende de** | ninguna |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 10, H27 (26-oct-2026) |
| **Rama** | `fix/T-038-monto-en-letras-moneda` |

## Contexto
`FacturacionElectronicaController::armarLeyendas()` arma la leyenda 1000 (monto en letras) con
`'SOLES'` fijo. Una boleta o factura en dólares llega a SUNAT con "… CON 00/100 SOLES". El PDF ya lo
hace bien (`Sale::resumen` mira `currency`) y las notas también (`armarLeyendasNota()`): el XML de la
venta queda distinto de su propio PDF.

## Alcance
- `armarLeyendas()` recibe la moneda de la venta y usa `DOLARES` si es `USD`, `SOLES` si no; mismo
  criterio que `Sale` y `NotaElectronicaController::armarLeyendasNota()`. Si se puede, un solo helper
  para los tres lugares.
- Revisar otros armados de leyenda 1000 fuera de estos dos controllers (adelantos, facturación de
  reservas) y corregirlos igual.

## Fuera de alcance
- Leyenda de Amazonía en el XML (H26): espera la respuesta del contador.
- Comprobantes ya enviados a SUNAT: no se reenvían.

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/Sale/FacturacionElectronicaController.php`
- `api-sistema-fe/app/Http/Controllers/Sale/NotaElectronicaController.php`
- `api-sistema-fe/app/Models/Sale/Sale.php`

## Criterios de aceptación
- [ ] **CA1** — Venta en USD → la leyenda 1000 termina en "DOLARES". → test
- [ ] **CA2** — Venta en PEN → sigue terminando en "SOLES". → test
- [ ] **CA3** — XML real de una factura en USD generado en local (sin enviar) con la leyenda correcta.
- [ ] **CA4** — Suite completa en verde.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no aplica
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: no aplica
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no cambia ningún monto, solo el texto
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: moneda desconocida → no asumir; solo `USD` y `PEN` existen hoy, confirmar
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan test --filter=Leyenda
php artisan test
```

---

## Resultado
- **Commits:** rama `fix/T-038-monto-en-letras-moneda` (ver `git log`).
- **Tests:** 3 nuevos (`MontoEnLetrasLeyendaTest`); suite completa 1538 en verde. CA3: el test arma
  el XML real con Greenter (`InvoiceBuilder`, sin firmar ni enviar) de una factura en USD y otra en
  PEN y comprueba "… CON 00/100 DOLARES/SOLES" y que coincida con el PDF.
- **Cómo quedó:** `App\Services\MontoEnLetras::comprobante($monto, $moneda)`, un solo lugar para
  la leyenda 1000 de la venta (`armarLeyendas()`, ahora recibe la moneda), la de la nota
  (`armarLeyendasNota()`) y el PDF (`Sale`/`Note::resumenImpresion()`). Sin moneda → soles
  (ventas antiguas); una moneda distinta de PEN/USD lanza excepción en vez de decir "SOLES".
- **Otros armados de la leyenda 1000:** adelantos y facturas de reserva pasan por
  `FacturacionElectronicaController::enviarSunat()`, así que quedan corregidos con este cambio.
  Créditos (`FormatoDocumento::enLetras()`) es solo en soles y no se tocó.
- **Desviaciones de la tarea y por qué:** ninguna.
- **Recorrido:** sin documento propio (cambio de un solo punto, explicado arriba y en el
  comentario de `MontoEnLetras`).

## Hallazgos fuera de alcance
- Ninguno.
