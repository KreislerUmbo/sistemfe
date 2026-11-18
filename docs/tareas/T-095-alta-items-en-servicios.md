# T-095 — Sacar de `AlternativaItemController` el alta de ítems por `origen_tipo` a un servicio por origen, con tests propios

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-094 |
| **Reglas** | no cambia ninguna (refactor puro) |
| **Decisiones** | ninguna (las del análisis están en «Decisiones del corte») |
| **Rama** | `refactor/T-095-alta-items-servicios` |

## Contexto
`api-sistema-fe/app/Http/Controllers/AgenciaViajes/AlternativaItemController.php` tiene 1.888
líneas. Unas 600 son el alta de un ítem del cotizador (`POST alternativas/{id}/items`): `store()`
elige una de cinco funciones privadas según `origen_tipo`, cada una con su validación, su cálculo
de costo/venta y su `AlternativaItem::create()`. Hoy solo se prueban a través del controller, y
para tocar el cálculo de un hotel hay que abrir el archivo entero. Esta tarea pasa cada rama a su
servicio, con tests que llaman al servicio directo, **sin cambiar ni una columna guardada ni un
byte de la respuesta**. También recorta los comentarios del archivo que narran historia (el porqué
queda; la historia está en `git log`). Antes de empezar, leer el recorrido 64
(`docs/aprendizaje/64-recorrido-cotizador-sin-recargas.md` §2.1: qué devuelve `store`).

## Las cinco ramas y su servicio
Líneas al empezar (commit `1d9b522b`).

| `origen_tipo` | Hoy (líneas) | Servicio nuevo | Qué hace la rama |
|---|---|---|---|
| `manual` | `crearItemManual()` 1641–1688 | `AltaManual` | Valida y guarda lo escrito (costo, venta, moneda, cantidad, descripción, proveedor sugerido). Sin cálculo |
| `guia` | `crearItemGuia()` 1315–1385 | `AltaGuia` | Rechaza tarifa retirada o fuera de vigencia; `PriceEngineService::calcular()` sobre `costo_diario` con el margen de la tarifa; siempre `tarifa_fija` |
| `mayorista` | `crearItemMayorista()` 1222–1305 | `AltaMayorista` | La opción tiene que ser de esta alternativa y estar `elegida`; precio desde **una** de dos fuentes: tarifa de habitación (`opcion_hotel_tarifa_id`) u opcional (`opcion_mayorista_opcional_id`, costo 0); siempre `tarifa_fija` |
| `pasaje_aereo` | `crearItemPasajeAereo()` 1480–1533 + `validarPasajeAereo()` 1535–1591 + `calcularPasajeAereo()` 1593–1631 | `AltaPasajeAereo` | Valida, completa `pax_incluidos` con todos si llega vacío (T-007), calcula tarifas + cargos + fee por pasajero (T-076); crea el ítem **y** `cotizacion_pasaje_aereo` en una transacción |
| `proveedor` | `crearItemProveedor()` 1021–1160 + `aplicarCamaAdicional()` 1171–1210 | `AltaProveedor` | Tres fuentes de precio: tarifa real (`proveedor_tarifa_id`: rechaza retirada/no vigente, `ventaYCostoDeTarifa()`, cama adicional si `tarifa_fija`), hotel ad-hoc local (`opcion_hotel_tarifa_id`, siempre `tarifa_fija`) o precio de referencia escrito; tributario desde la tarifa si la hay |

Lo común a las cinco (hoy repetido en cada una): `alternativa_destino_id` (regla `exists` dentro de
la alternativa + primer destino por defecto), tratamiento tributario completado con el default de
la agencia, `precio_convertido` con `convertirMoneda()`, y recalcular el total de la alternativa
al final.

## Decisiones del corte
1. **La validación va dentro de cada servicio.** Las reglas dependen de la alternativa y viven
   junto al cálculo que las usa. Un rechazo (validación o regla de negocio) lanza
   `AltaItemException` con el mismo texto de hoy (`$validator->errors()->first()` en la
   validación). Mismo patrón que `AlternativaCreacionService`/`AlternativaCreacionException`.
2. **Una clase base abstracta `AltaItemBase`** con lo común: `validar(array $datos, array $reglas)`
   (lanza la excepción), `convertir(float, string $moneda, Alternativa)` y `cerrar(Alternativa)`
   (recalcula el total). El destino lo resuelven los métodos de `Alternativa` de la decisión 3.
   Cada servicio expone `crear(Alternativa $alternativa, array $datos): AlternativaItem`.
3. **Tres helpers se mudan a su lugar natural**, porque los usan también métodos que se quedan en
   el controller (y otros archivos):
   - `resolverTratamientoTributario()` (hoy `public static` del controller) →
     `ConfiguracionAgencia::completarTratamientoTributario(?string, ?string): array`, junto a
     `tratamientoTributarioDefault()`. Se actualizan **todos** sus usos: el controller
     (`actualizarTarifa()` ~257, `promoverAProveedor()` ~1808) y `VentaDirectaController`
     (**cinco**: ~722, ~742, ~819, ~822 y ~900). Se borra del controller (no queda envoltorio):
     al terminar, `grep -rn "AlternativaItemController::resolverTratamientoTributario" app` no
     encuentra nada.
   - `recalcularTotalAlternativa()` → `Alternativa::recalcularTotal(): void`. El controller tiene
     18 llamadas (más la definición): pasan a `$alternativa->recalcularTotal()`.
   - `reglaAlternativaDestinoId()`/`resolverAlternativaDestinoId()` → métodos de `Alternativa`:
     `$alternativa->reglaDestinoId()` (la regla `exists` dentro de la alternativa) y
     `$alternativa->destinoIdODefault(?int $explicito): ?int` (el explícito o el primer destino).
     Los usan los servicios y `desdePlantilla()` (~446, ~457); el controller no depende de una
     clase de servicios. Se borran del controller.
4. **`store()` arma la respuesta directo** (ya no decodifica y re-codifica un `JsonResponse`):
   `match` → servicio + mensaje de hoy; `catch (AltaItemException)` → `{code: 422, message}` con
   HTTP 422. La respuesta de éxito es exactamente la de hoy:
   `{code: 200, message, alternativa_item: AlternativaItem::with(self::RELACIONES_LIENZO)->find($item->id)->toArray(), alternativa: $alternativa->fresh()->toArray()}`.
   **No** devolver el `$alternativa` en memoria: puede traer relaciones cargadas por el camino
   (autorización, servicios) y la respuesta cambiaría. `conItemCompletoYAlternativa()` desaparece.
   Venta directa (`VentaDirectaController` ~208) lee `code`, `message` y `alternativa_item.id`
   de esta respuesta: no cambian.
5. **Pasaje aéreo:** `AltaPasajeAereo` expone también `validar()` y `calcular()` públicos, porque
   `previewPasajeAereo()` y `actualizarPasajeAereo()` (que se quedan en el controller) usan las
   mismas. Para el controller, un rechazo de `validar()` se convierte en el mismo 422 de hoy.
6. **Transacciones como hoy:** solo el pasaje aéreo envuelve su alta en `DB::transaction`. Las
   otras cuatro siguen sin transacción propia (ver Hallazgos).

## Alcance
- Carpeta nueva `api-sistema-fe/app/Services/AgenciaViajes/AltaItem/`: `AltaItemException`,
  `AltaItemBase`, `AltaManual`, `AltaGuia`, `AltaMayorista`, `AltaPasajeAereo`, `AltaProveedor`.
- `AlternativaItemController`: `store()` despacha a los servicios (inyectados por constructor);
  se borran `crearItem*()`, `aplicarCamaAdicional()`, `validarPasajeAereo()`,
  `calcularPasajeAereo()`, `conItemCompletoYAlternativa()`, `resolverTratamientoTributario()`,
  `recalcularTotalAlternativa()`, `reglaAlternativaDestinoId()`, `resolverAlternativaDestinoId()`.
  `contarPasajerosPorTipo()` **se queda**: lo usa la rama clásica (~601).
- `ConfiguracionAgencia::completarTratamientoTributario()`, `Alternativa::recalcularTotal()`,
  `Alternativa::reglaDestinoId()` y `Alternativa::destinoIdODefault()`.
- `VentaDirectaController`: solo el cambio de nombre de los cinco usos del tratamiento tributario.
- Recortar en todo `AlternativaItemController` (y no copiar a los servicios) los comentarios que
  narran historia: «Sesión 11b/M3/12f-1…», fechas («26-ago-2026:»), «bug real encontrado», «hasta
  esta sesión…», «antes vivía…», citas a documentos de `docs/planning/` que ya no están
  (`plan-modulo-cotizaciones-reservas.md`, prompts `PEGAR-EN-CLAUDE-CODE-*`). Queda el porqué en
  presente («un hotel ad-hoc es siempre por habitación»); una referencia útil a una tarea va como
  `ver T-…`. Esto cubre, para este archivo, la línea de «Por analizar» de comentarios con citas
  rotas (hallazgo de T-097). Fuera del controller, solo los comentarios que nombran un método
  que esta tarea mueve o borra (p. ej. `ConfiguracionAgencia.php` ~58 cita
  `resolverTratamientoTributarioDefault()`, que no existe; `PenalidadPasajeroService` ~59 y ~125):
  se actualizan al nombre nuevo.
- Tests nuevos (ver «Criterios»).

## Fuera de alcance
- `desdePlantilla()`, `crearItemClasicoDesdeEntrada()` y `desdePlantillaModoNuevo()`: también
  crean ítems de proveedor y guía, pero con otro contrato (entradas ya calculadas por
  `ComboExplosionService`, sin tributario). Su tributario lo toca T-029; no se unifican con
  `AltaProveedor`/`AltaGuia`.
- `actualizarPasajeAereo()`, `actualizarManual()`, `promoverAProveedor()`, `update()` y el resto
  de los métodos de edición: se quedan en el controller (solo cambian las llamadas a los helpers
  mudados).
- Cambiar textos de mensajes, aunque haya voseo («Marcá la opción…»): ver Hallazgos.
- Agregar validaciones o transacciones que hoy no existen (ver Hallazgos).
- Las copias de `calcularTotalEfectivo(...)['total']` + `update` en otros archivos y la copia
  privada de `resolverTratamientoTributario()` en `OpcionHotelController` (~483): ver Hallazgos.
- Frontend: no cambia (la respuesta es la misma).

## Dónde tocar
Líneas de `AlternativaItemController.php` al empezar (commit `1d9b522b`):
- `store()` 67–95, `RELACIONES_LIENZO` 97–110 (queda), `conItemCompletoYAlternativa()` 114–127.
- `reglaAlternativaDestinoId()` 57, `resolverAlternativaDestinoId()` 62 (usos fuera de las
  ramas: 446 y 457 en `desdePlantilla`; el resto en las ramas).
- Ramas: tabla de arriba.
- `previewPasajeAereo()` 1394, `actualizarPasajeAereo()` 1417–1477 (usan validar/calcular).
- `resolverTratamientoTributario()` 1858–1870 (usos internos: 257, 1808; externos:
  `VentaDirectaController.php` 722, 742, 819, 822 y 900).
- `contarPasajerosPorTipo()` 1872 (se queda), `recalcularTotalAlternativa()` 1882 (18 llamadas).
- `app/Models/AgenciaViajes/ConfiguracionAgencia.php` → junto a `tratamientoTributarioDefault()`.
- `app/Models/AgenciaViajes/Alternativa.php` → método nuevo `recalcularTotal()`.
- Precedente de servicio + excepción: `app/Services/AgenciaViajes/AlternativaCreacionService.php`.
- Fixture de tests: copiar el patrón de `tests/Feature/AgenciaViajes/T032RespuestasParaActualizacionParcialTest.php`
  (Postgres `sistemafe_test_migrations`, transacción que se deshace, `ConAccesoATodasLasReservas`).

## Pasos (un commit cada uno; tests del área en verde al final de cada uno)
0. **Test de caracterización, contra el código de hoy** (antes de mover nada):
   `tests/Feature/AgenciaViajes/T095AltaItemCaracterizacionTest.php`. Por cada `origen_tipo`
   (proveedor con tarifa `por_persona`, proveedor `tarifa_fija` con cama adicional, proveedor
   hotel ad-hoc, proveedor precio de referencia, mayorista con habitación, mayorista con opcional,
   guía, pasaje aéreo, manual en USD sobre alternativa en PEN) llama a `store()` y fija: **todas**
   las columnas de la fila creada (y de `cotizacion_pasaje_aereo`), el `total` de la alternativa
   y **el JSON completo de la respuesta** (`code`, `message`, `alternativa_item` con sus
   relaciones y `alternativa`), comparado entero tras normalizar ids, `created_at`/`updated_at`
   y `fecha_cotizado`. Más los rechazos que hoy no tienen test: mayorista de
   otra alternativa, mayorista no `elegida`, tarifa de habitación de otra opción, manual sin
   `descripcion_manual` (mensaje exacto). Commit solo con el test, en verde sobre `main`.
   **Este archivo no se vuelve a editar en los pasos siguientes.**
1. Base común: `AltaItemException`, `AltaItemBase`, `ConfiguracionAgencia::completarTratamientoTributario()`,
   `Alternativa::recalcularTotal()`; el controller y `VentaDirectaController` pasan a usarlos.
   `store()` con el `try/catch` y la respuesta directa (todavía llamando a las privadas).
2. `AltaManual` + `tests/Feature/AgenciaViajes/AltaItem/AltaManualTest.php`.
3. `AltaGuia` + test.
4. `AltaMayorista` + test.
5. `AltaPasajeAereo` (con `validar`/`calcular` públicos; `previewPasajeAereo()` y
   `actualizarPasajeAereo()` los usan) + test.
6. `AltaProveedor` (con la cama adicional) + test.
7. Comentarios: recorte en todo el controller; medir líneas (`wc -l`).

## Tests que ya cubren el alta (red de regresión: no se editan)
Llaman a `AlternativaItemController::store()` y tienen que pasar sin tocarlos en cada paso:

| Rama | Tests |
|---|---|
| proveedor | `PrecioPorPasajeroTest` (cama adicional: suma y 4 rechazos, edad real), `SesionM3HotelAdhocLocalTest` (ad-hoc y precio de referencia), `ProveedorTarifaDesactivarTest::test_item_nuevo_rechaza_tarifa_desactivada`, `T028VersionVigenteTarifaTest::test_ca3_*`, `NinoSinPrecioPagaComoAdultoTest`, `SesionM4ElegirGrupoTest::test_crear_item_proveedor_*` (`grupo_opcion_id`), `Fila13Fase1bTourCotizacionTest` |
| mayorista | `OpcionMayoristaOpcionalComoItemTest` (opcional, opcional de otra opción), `SesionM2TrazabilidadHotelReservaTest::test_crear_item_mayorista_escribe_opcion_hotel_tarifa_id` |
| guía | `GuiaComoItemRealTest`, `GuiaTarifaDesactivarTest::test_item_nuevo_rechaza_tarifa_desactivada`, `GuiaTarifaVersionVigenteTest` (vieja y futura) |
| pasaje aéreo | `AlternativaItemPasajeAereoTest`, `PasajeAereoPorPasajeroTest`, `PasajeAereoVigenciaTarifaTest`, `CapitalizacionNombresTest::test_pasaje_aereo_*` |
| manual | `T032RespuestasParaActualizacionParcialTest` (respuesta completa y alta rechazada) y como dato en decenas de tests de reserva |
| las cinco | `Sesion12f1BackendMultidestinoTest::test_item_*` (destino por defecto, explícito y de otra alternativa) |
| a través de venta directa | `VentaDirecta*Test`, `T048VentaDirectaCrearYCobrarTest` |

Huecos que cubre el paso 0: los tres rechazos del mayorista, la validación del manual, la
conversión de moneda del manual y el tributario completado con el default en cada rama.

## Criterios de aceptación
- [ ] **CA1** — El test de caracterización pasa en `main` (paso 0) y después de cada paso, sin
  editarlo → `php artisan test --filter=T095AltaItemCaracterizacionTest` y
  `git log -p --follow tests/Feature/AgenciaViajes/T095AltaItemCaracterizacionTest.php` con un solo commit.
- [ ] **CA2** — Los tests de la tabla «red de regresión» pasan sin cambios → `git diff main --stat -- tests/`
  solo muestra archivos nuevos.
- [ ] **CA3** — Cada servicio tiene su test que lo llama directo (sin controller): el caso feliz
  de cada fuente de precio, cada rechazo con su mensaje, el destino por defecto y el tributario
  completado con el default de la agencia → `tests/Feature/AgenciaViajes/AltaItem/*Test.php`.
- [ ] **CA4** — En el controller ya no están `crearItem*`, `aplicarCamaAdicional`,
  `validarPasajeAereo`, `calcularPasajeAereo`, `conItemCompletoYAlternativa`,
  `resolverTratamientoTributario`, `recalcularTotalAlternativa`, `reglaAlternativaDestinoId` ni
  `resolverAlternativaDestinoId` (y nadie en `app/` los llama); `store()` ocupa ≤ 35 líneas;
  el archivo queda ≤ 1.350 líneas → grep + `wc -l` (anotar el número en Resultado).
- [ ] **CA5** — Sin comentarios de historia en el controller ni en `AltaItem/` →
  `grep -nE "Sesi[oó]n [0-9M]|[0-9]{1,2}-[a-z]{3}-20[0-9]{2}|bug real|hasta esta sesi|PEGAR-EN|hoja-de-ruta|plan-modulo-" `
  sin resultados.
- [ ] **CA6** — Suite completa del backend en verde contra Postgres (intermitente conocido:
  `Fila13Fase1cFacturacionTest::descuento_del_combo…`).

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no aplica (sin rutas nuevas). `store()` sigue llamando a
  `autorizarCotizacion('update', …)` **antes** de despachar; los servicios no autorizan.
- [ ] Aislamiento de tenant: no aplica (mismas consultas, todas tenant).
- [ ] Validación de entrada / archivos: se mueve tal cual; ninguna regla cambia (CA1).
- [ ] Concurrencia (`lockForUpdate`): no aplica (no cambia).
- [ ] Dinero en centavos: no aplica (se conserva el cálculo actual sin tocarlo).
- [ ] Fechas con `HoraPeru`: se conserva `HoraPeru::hoyTexto()` en la vigencia de guía y
  `HoraPeru::aUtc()` en `tarifa_valida_hasta`. `fecha_cotizado => now()` es un instante (UTC): queda.
- [ ] Tributario sin fallback silencioso: el completado con el default de la agencia es el
  comportamiento de hoy; se mueve, no se cambia.
- [ ] Sin `v-html` sin sanitizar: no aplica.
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica.

## Cómo verificar
```
php artisan test --filter='T095AltaItemCaracterizacionTest|AltaItem'
php artisan test --filter='PrecioPorPasajeroTest|SesionM3HotelAdhocLocalTest|ProveedorTarifaDesactivarTest|T028VersionVigenteTarifaTest|NinoSinPrecioPagaComoAdultoTest|SesionM4ElegirGrupoTest|Fila13Fase1bTourCotizacionTest|OpcionMayoristaOpcionalComoItemTest|SesionM2TrazabilidadHotelReservaTest|GuiaComoItemRealTest|GuiaTarifaDesactivarTest|GuiaTarifaVersionVigenteTest|AlternativaItemPasajeAereoTest|PasajeAereoPorPasajeroTest|PasajeAereoVigenciaTarifaTest|CapitalizacionNombresTest|T032RespuestasParaActualizacionParcialTest|Sesion12f1BackendMultidestinoTest|VentaDirecta|T048VentaDirectaCrearYCobrarTest'
php artisan test   # suite completa, al final
```
Prueba funcional opcional (la respuesta no cambia, la cubre CA1): en agencia-demo, agregar en el
cotizador un ítem de cada tipo y ver que aparece con su nombre sin recargar.

Tamaño: grande, en 8 commits → la construye Sonnet en otra sesión (`docs/proceso.md` §5).

---

## Resultado
<!-- Lo completa quien construye, al terminar. -->
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
<!-- Problemas vistos y NO arreglados. Se convierten en tareas o en "Por analizar". -->
- (del análisis) Las altas de proveedor, mayorista, guía y manual crean el ítem y después
  recalculan el total sin transacción: si el recálculo falla, el ítem queda con el total viejo.
  Desde venta directa no pasa (la envuelve su transacción).
- (del análisis) `pax_incluidos.*` solo valida que el pasajero exista, no que sea de esta
  cotización; en mayorista ni eso (no tiene regla `pax_incluidos.*`).
- (del análisis) Mensaje con voseo en el alta de mayorista: «Marcá la opción de mayorista como
  elegida…».
- (del análisis) `calcularTotalEfectivo(...)['total']` + `update(['total' => …])` está copiado en
  `AlternativaController` (~375), `OpcionHotelController` (~344), `OpcionMayoristaController`
  (~277), `PenalidadPasajeroService` (~130) y `RecalculoPorPersonaService` (~147): podrían usar
  `Alternativa::recalcularTotal()`. Y `OpcionHotelController::resolverTratamientoTributario()`
  (~483) es copia del que esta tarea muda a `ConfiguracionAgencia`.
- 
