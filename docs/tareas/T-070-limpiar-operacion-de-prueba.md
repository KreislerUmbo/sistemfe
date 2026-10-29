# T-070 — Comando para borrar la operación de prueba de un tenant de Agencia (conserva la configuración)

| | |
|---|---|
| **Módulo** | PLA + AGE |
| **Depende de** | ninguna |
| **Reglas** | — (no cambia reglas de negocio) |
| **Decisiones** | Acordado con el usuario el 29-oct-2026 (abajo, "Decisiones tomadas") |
| **Rama** | `feat/T-070-limpiar-operacion-prueba` |

## Contexto
La agencia (dakamu en producción) todavía no opera en campo. Sus cotizaciones, reservas, ventas,
pagos y caja son de prueba y se hicieron con reglas que ya cambiaron (ej. `RDKM-1026-0000012`:
un tour partido en dos líneas porque se cotizó antes de "exonerado = Amazonía"). Esos datos
confunden y obligan a cuidar casos que la operación real nunca va a generar. Se necesita un
comando **reutilizable** (el usuario lo va a correr él mismo más adelante) que borre la
operación y deje intacta la configuración.

**Por qué no la cascada de la base:** no todas las relaciones tienen FK (`sale_details`,
`sale_payments` y `cash_movements.reference_*` no apuntan a `sales` con FK; las referencias
polimórficas no tienen ninguna), y donde sí hay `ON DELETE CASCADE` puede arrastrar de más.
El comando borra por una **lista explícita**, en orden de hijos a padres.

## Decisiones tomadas (29-oct-2026)
- En dakamu no se envió nada a SUNAT producción → se pueden borrar comprobantes y reiniciar series.
- **No se tocan** los tours (`paquetes_plantilla` y todo lo suyo) ni las tarifas de proveedores.
- Los **clientes se conservan** (y su catálogo de pasajeros); solo su `saldo_a_favor` vuelve a 0.
- El **tipo de cambio registrado** (`tipo_cambio_agencia`) se borra: era de prueba.
- Proceso: backup → probar en local con copia del servidor → solo el tenant de la agencia →
  recorrido completo de aceptación → recién entonces SUNAT producción.

## Alcance
Comando `php artisan agencia-viajes:limpiar-operacion {tenant} {--ejecutar}`.

1. **Sin `--ejecutar` = simulación** (por defecto): imprime, por tabla, cuántas filas se
   borrarían y cuántos archivos; qué correlativos se reinician; y no escribe nada.
2. **Con `--ejecutar`**: pide escribir el ID del tenant para confirmar; borra todo en **una
   transacción** (o todo o nada); después del commit borra los archivos; imprime el resumen.
3. **Guardias** (abortan antes de borrar, con mensaje claro):
   - el tenant no existe;
   - `tenants.giro` ≠ `agencia_viajes` (el comando solo conoce las tablas de Agencia + núcleo);
   - `tenants.sunat_modo` = producción **y** hay alguna venta con `sunat_sent_at` no nulo
     (un número enviado a SUNAT producción no se puede reusar);
   - hay filas en alguna tabla `credito_*`, `creditos` o `prendas` (no es un tenant de Agencia puro).
4. **Tablas que se vacían** (orden de hijos a padres; el constructor confirma el orden con las FK):
   - Ventas y SUNAT: `sale_detail_items`, `sale_details`, `sale_payments`, `installments`,
     `note_details`, `notes`, `sales` (incluye soft-deleted).
   - Cobros y anticipos: `payment_applications`, `payment_refunds`, `payment_receipts`,
     `advance_applications`, `advance_refunds`, `advances`.
   - Caja: `cash_movements`, `cash_session_totals`, `cash_session_denominations`, `cash_sessions`
     (las **cajas** `cash_registers` y los `cash_concepts` se conservan).
   - Reservas: `reserva_item_vuelo_pasajero`, `reserva_item_pasajero`, `reserva_ventas`,
     `reserva_anticipos`, `reserva_items`, `reserva_pasajeros`, `reserva`.
   - Salidas: `salida_vehiculo_reservas`, `salida_vehiculos`, `salida_servicios`, `salidas_operativas`.
   - Cotizador: `cotizacion_envios`, `cotizacion_pasaje_aereo`, `alternativa_items`,
     `opciones_hotel_tarifas`, `opciones_hotel`, `opcion_mayorista_opcionales`,
     `opcion_mayorista_tours`, `opcion_mayorista`, `alternativa_destinos`, `alternativas`,
     `cotizacion_pasajeros`, `cotizaciones`.
   - Proveedores (operación, no catálogo): `pago_proveedor`, `cronograma_pago_proveedor`.
   - Otros módulos con operación: `commercial_quote_anticipos`, `commercial_quote_items`,
     `commercial_quotes`, `order_items`, `orders`, `recordatorios`, `recordatorio_snooze_config`.
   - Tipo de cambio: `tipo_cambio_agencia`.
5. **Clientes:** se conservan; `clients.saldo_a_favor` vuelve a 0 (nace de operaciones borradas).
6. **Correlativos que se reinician:**
   - `serie_comprobantes.correlativo_actual` = `correlativo_inicial - 1` en todas las series
     (las series en sí se conservan).
   - `codigo_secuencias.ultimo_correlativo` = 0 **solo** para `cotizacion` y `venta_directa`
     (el de reserva deriva de cotización; ver `configuracion_codigos.deriva_de`).
     **No** `tour` ni `paquete`: los tours se conservan y sus códigos no deben repetirse.
   - El correlativo de recibos (`payment_receipts.numero_recibo`), si sale de una secuencia:
     el constructor busca de dónde sale (`grep -rn numero_recibo app`) y lo reinicia igual.
7. **Archivos:** borrar del disco del tenant los XML/CDR/PDF de las ventas y notas borradas
   (`sales.xml`, `sales.cdr`; ver cómo se resuelven en T-069 / `ArchivoSunatTest`),
   y `cash_movements.attachment_path`.
   Un archivo que no existe no es error (se cuenta como "no encontrado").
8. Al final, limpiar la caché del tenant (`cache`), para que Inicio y los contadores no
   muestren datos viejos.

## Fuera de alcance
- **No se tocan:** `paquetes_plantilla`, `paquete_plantilla_items`, `paquete_plantilla_precios`,
  `tour_itinerario_items`, `contenido_tour`; `proveedores` y todo `proveedor_*` (tarifas,
  servicios, amenidades, alojamiento), `guias`, `guia_tarifas`, `salidas_mayorista`,
  `reglas_cancelacion`; `destinos_atractivos`, `destino_servicio`, `servicios`;
  `configuracion_*`, `cuentas_bancarias`, `companies`, `sunat_configs`, `branches`,
  `serie_comprobantes` (solo su correlativo), `note_series`, `payment_methods`, `products`,
  `categories`; `clients`, `client_addresses`, `pasajeros_catalogo`, `pasajero_documentos`; `users`, `roles`, `permissions`, `model_has_*`,
  `role_has_permissions`, `role_audit_logs`, `permission_shadow_logs`; `migrations`;
  `ajustes_*` (bitácoras de migraciones de datos); todo `credito_*`.
- Otros giros (retail, créditos): si hace falta, es otra tarea con su propia lista.
- No se crea ruta HTTP ni pantalla: solo consola.
- No se borra ni recrea el tenant.

## Dónde tocar
- `api-sistema-fe/app/Console/Commands/LimpiarOperacionAgencia.php` (nuevo). Modelo de
  comando con modo solo lectura: `DiagnosticarTours.php`; uso de `$tenant->run(...)`: `ArchiveTenant.php`.
- Lista de tablas como constante del comando (un solo lugar), con un comentario por grupo.
- Test: `api-sistema-fe/tests/Feature/AgenciaViajes/LimpiarOperacionAgenciaTest.php` (nuevo).

## Criterios de aceptación
- [ ] **CA1** — Sin `--ejecutar`, el comando imprime los conteos y no cambia ninguna fila ni
  archivo → test: `simulacion_no_modifica_nada`
- [ ] **CA2** — Con `--ejecutar` (confirmado), las tablas de §4 quedan en 0 y las de "No se
  tocan" conservan exactamente sus filas (comparar conteos antes/después) → test:
  `ejecutar_borra_operacion_y_conserva_configuracion`
- [ ] **CA3** — Tours, ítems de tours y tarifas de proveedores quedan idénticos (mismos IDs y
  valores) → mismo test, comparación explícita
- [ ] **CA4** — Series a `correlativo_inicial - 1`; secuencias `cotizacion` y `venta_directa`
  en 0; `tour` y `paquete` sin cambio → test: `reinicia_solo_correlativos_de_operacion`
- [ ] **CA5** — Los clientes y sus pasajeros del catálogo se conservan, con `saldo_a_favor = 0`;
  `tipo_cambio_agencia` queda vacía → test: `conserva_clientes_y_borra_tipo_cambio`
- [ ] **CA6** — Aborta sin borrar nada si: giro no es agencia; `sunat_modo` producción con una
  venta enviada; hay filas en `creditos` → test: `guardias_abortan`
- [ ] **CA7** — Si una tabla falla a mitad, no se borra nada (transacción) → test: `falla_revierte_todo`
- [ ] **CA8** — Borra los archivos XML/CDR de las ventas borradas del disco del tenant y no
  falla si alguno no existe → test: `borra_archivos_de_ventas` (con `Storage::fake`)
- [ ] **CA9** — Prueba manual en local contra una **copia de la base de dakamu** (ver "Cómo verificar").

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no aplica (solo consola, sin ruta).
- [ ] Aislamiento de tenant: todo dentro de `$tenant->run()`; nunca la conexión central;
  archivos solo del disco del tenant.
- [ ] Validación de entrada / archivos: tenant obligatorio; confirmación escribiendo el ID.
- [ ] Concurrencia: correrlo con la agencia sin usuarios conectados (avisar en la salida del comando).
- [ ] Dinero en centavos: no aplica (no calcula montos).
- [ ] Fechas con `HoraPeru`: no aplica.
- [ ] Tributario sin fallback silencioso: guardia de SUNAT producción (CA6).
- [ ] Sin `v-html` sin sanitizar: no aplica.
- [ ] Migraciones nuevas: no aplica (no cambia el esquema).

## Cómo verificar
```
php artisan test --filter=LimpiarOperacionAgenciaTest
php artisan agencia-viajes:limpiar-operacion agencia-demo            # simulación
php artisan agencia-viajes:limpiar-operacion agencia-demo --ejecutar # solo en local
```
Prueba con copia de producción (CA9), en este orden:
1. En el servidor: backup de la base `tenantdakamu` (`pg_dump`) y del storage del tenant; guardarlos.
2. Restaurar ese dump en local como tenant de prueba y correr la simulación; revisar los conteos con el usuario.
3. Ejecutar en local; abrir la agencia: tours y tarifas iguales, listados de cotizaciones/reservas/ventas vacíos, caja sin sesiones.
4. Recién después, en el servidor, con el usuario: simulación → `--ejecutar` → recorrido de aceptación
   (cotizar → reservar → anticipo → facturar en beta → nota de crédito → cerrar caja).

---

## Resultado
- **Commits:** ver rama `feat/T-070-limpiar-operacion-prueba`.
- **Tests:** 8 nuevos en `LimpiarOperacionAgenciaTest` (CA1-CA8). Suite completa contra Postgres: 1663 pasan, 0 fallan (29-oct).
- **Desviaciones de la tarea y por qué:**
  - La lógica vive en `app/Services/AgenciaViajes/LimpiarOperacionPrueba.php` y el comando solo
    elige el tenant y confirma: los tests corren sobre la base de tests (con transacción), sin crear un tenant físico.
  - Guardias agregadas: una tabla de la base que no está en ninguna de las dos listas frena el
    comando (para que una tabla nueva no se olvide), y también una FK desde una tabla conservada
    con filas hacia una que se borra.
  - Recibos: `payment_receipts.numero_recibo` sale del último recibo (no de una secuencia), así
    que vuelve a `REC-00001` solo al vaciar la tabla.
  - Sin `--con-clientes`: el usuario decidió conservar siempre los clientes (29-oct).
- **Verificado en local:** simulación en agencia-demo (572 filas, 14 archivos, 0 tablas sin
  clasificar); confirmación incorrecta no borra; credishirley rechazado por giro y créditos.
- **Pendiente (CA9):** simulación contra una copia de la base de dakamu, con el usuario.

## Hallazgos fuera de alcance
- `CodigoGeneradorService` lee la secuencia con `whereNull('periodo')` aunque `cotizacion` tenga
  `incluye_periodo`: el correlativo es global, no por mes (puede ser a propósito; no verificado). No se tocó.
