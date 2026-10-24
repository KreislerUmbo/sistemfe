# Agencia de Viajes — mapa de módulos y dónde están los requisitos

> **Para qué sirve:** es la puerta de entrada al vertical. Dice qué hace cada módulo, en qué
> archivos vive y en qué documento están sus requisitos. No reemplaza a los planes: los ordena.
> **Estado vigente de cada fila:** `plan-hoja-de-ruta-ejecucion.md`.
> Creado el 23-oct-2026, verificado contra el código de la rama `feat/f1-facturacion-por-servicio`.
> Cuando se agregue o cierre un módulo, actualizar este mapa en el mismo commit.

---

## 1. El recorrido de una venta, de punta a punta

```
 CATÁLOGOS (se cargan una vez)           VENTA                 RESERVA                 COBRO              OPERACIÓN
 destinos · proveedores · tarifas   →   cotización     →     reserva aceptada   →   facturación   →   salidas operativas
 guías · temporadas · tours             (alternativas)        pasajeros, servicios     (SUNAT)           reporte operativo
 configuración de la agencia            venta directa         anticipos                                  órdenes de servicio
                                                                                                         [Compras — fase 3]
```

---

## 2. Módulos construidos

Cada módulo: **qué hace** (lo que el usuario puede hacer), **pantallas** (`admin-start-kit/src/views/agencia-viajes/`),
**backend** (`api-sistema-fe/app/Http/Controllers/AgenciaViajes/` y `app/Services/AgenciaViajes/`) y **requisitos**
(documentos en `docs/planning/agencia-de-viajes/`, salvo que se indique otra carpeta).

### 2.1 Catálogos (datos maestros)

| Módulo | Qué hace | Pantallas | Backend | Requisitos |
|---|---|---|---|---|
| **Destinos y servicios** | Árbol de destinos y atractivos con fotos; catálogo de servicios (sin duplicados) asociado a cada destino; buscador por texto, tipo y "sin servicios" | `destinos/index`, `destinos/form` | `DestinoAtractivoController`, `DestinoServicioController`, `ServicioController`, `AmenidadController` | `plan-modulo-cotizaciones-reservas.md` §5.2 |
| **Proveedores y tarifas** | Proveedores por tipo (hotel, transporte, mayorista…); servicios que dan en cada destino; tarifas con costo, margen, precio, IGV y destino tributario; activar o desactivar tarifas; versionado | `proveedores/index`, `proveedores/form`, `proveedores/detalle` | `ProveedorController`, `ProveedorServicioController`, `ProveedorTarifaController`, `ProveedorTipoConfigController` | `plan-modulo-cotizaciones-reservas.md` §2 |
| **Guías** | Guías con sus tarifas (activar, desactivar, versionar) | `guias/index`, `guias/detalle` | `GuiaController`, `GuiaTarifaController` | `plan-modulo-cotizaciones-reservas.md` §5.3 |
| **Temporadas** | Temporadas y sus fechas, que eligen el precio de un tour según la fecha | `temporadas/index` | `TemporadaController`, `TemporadaOcurrenciaController`, `TemporadaResolverService` | `plan-tours-venta-simple-costo-real.md` §3.12 |
| **Tours y paquetes (catálogo)** | Tour simple o combo; precio por persona (adulto/niño × nacional/extranjero) y por temporada; costo estimado; "Incluye" con costo e IGV por servicio; itinerario y fotos; hora y lugar de recojo; uso (venta o solo itinerario) | `paquetes/index`, `paquetes/form`, `paquetes/detalle` | `PaquetePlantillaController`, `PaquetePlantillaItemController`, `TourItinerarioItemController`, `ContenidoTourController`, `TourCostoEstimadoService`, `ComboValidationService`, `ComboExplosionService` | `plan-tours-venta-simple-costo-real.md` §3.1, §3.2 y §3.4 (fase 1a) |
| **Conversión de tours** | Pasar los tours antiguos al modelo nuevo: propone precio, costo e IGV y el usuario confirma | `paquetes/conversion` | `ConversionToursController`, `ConversionToursService`, `DiagnosticoToursService` | `plan-tours-venta-simple-costo-real.md` §3.11 (fase 1c) |
| **Configuración de la agencia** | Perfil, logos, cuentas bancarias, condiciones generales (texto del PDF), tratamiento tributario por defecto, correo de envío, sigla y numeración de códigos | `configuracion/index`, `configuracion/codigos` | `ConfiguracionAgenciaController`, `ConfiguracionAgenciaPdfController`, `CondicionesGeneralesController`, `CuentaBancariaController`, `ConfiguracionCorreoController`, `ConfiguracionCodigosController`, `CodigoGeneradorService` | `plan-modulo-codigos-numeracion.md`, `plan-modulo-envio-correo-cotizacion.md` |

### 2.2 Venta

| Módulo | Qué hace | Pantallas | Backend | Requisitos |
|---|---|---|---|---|
| **Cotizador** | Cotización con pasajeros (tipo, edad, residencia) y varias alternativas; cada alternativa por destinos y días; agregar servicios desde la biblioteca, tours (una línea por tour en modo nuevo), hoteles con matriz de opciones, mayoristas con sus opcionales, pasaje aéreo suelto, ítems manuales; moneda y tipo de cambio; descuentos; recálculo al cambiar pasajeros; estado (borrador, enviada, aceptada, vencida…) | `cotizador/index`, `cotizador/nueva`, `cotizador/editar` | `CotizacionController`, `AlternativaController`, `AlternativaDestinoController`, `AlternativaItemController`, `BibliotecaCotizadorController`, `OpcionHotelController`, `OpcionMayoristaController`, `TipoCambioAgenciaController`, `PriceEngineService`, `TourLineaService` | `plan-modulo-cotizaciones-reservas.md` §3; `plan-matriz-hoteles-cotizador.md`; `auditoria-arquitectonica-agencia-viajes.md` (multidestino); `plan-fix-moneda-cotizador.md` |
| **PDF y envío de la cotización** | PDF comercial por alternativa (itinerario, incluye, fotos, condiciones); envío por correo desde el sistema | (desde `cotizador/editar`) | `AlternativaPdfService`, `MembreteAgenciaPdfService`, `CondicionesGeneralesPdfService`, `CotizacionEnvioController`, `CorreoAgenciaService` | `plan-mejora-pdf-cotizacion-cliente.md`, `plan-modulo-envio-correo-cotizacion.md` |
| **Venta directa** | Atajo para vender un servicio suelto sin pasar por el cotizador; crea por dentro cotización y reserva con el código de la venta | `venta-directa` | `VentaDirectaController` | `plan-modulo-cotizaciones-reservas.md` §4.1; hoja de ruta (bloques 2-5 pendientes) |

### 2.3 Reserva

| Módulo | Qué hace | Pantallas | Backend | Requisitos |
|---|---|---|---|---|
| **Reserva** | Aceptar una alternativa crea la reserva (copia pasajeros con su vínculo a la cotización y los servicios con sus fechas); fecha propia de la reserva; reprogramar el viaje (respeta las fechas puestas a mano); sincronizar servicios agregados después; reasignar mayorista u hotel; confirmar el tratamiento tributario de servicios de la Amazonía; facturación externa; cancelar (devuelve cupo, desengancha salidas) | `reservas/index`, `reservas/detalle` | `ReservaController` | `plan-modulo-cotizaciones-reservas.md` §4; CLAUDE.md "fecha de una Reserva" |
| **Pasajeros y servicios de la reserva** | Datos de cada pasajero (catálogo de pasajeros, documentos, vuelos propios); agregar pasajero; asignar pasajeros a servicios; vuelo de la agencia por pasajero; editar guía, proveedor, fecha y hora de un servicio; check-in. **Quitar pasajero: bloqueado hasta "Modificar reserva" (F4)** | `reservas/detalle` (pestañas Pasajeros, Servicios, Asignación) | `ReservaPasajeroController`, `ReservaItemController`, `ReservaItemPasajeroController`, `QuitarPasajeroReservaService` | hoja de ruta, filas F4 y 11c |
| **Anticipos de la reserva** | Cobrar un anticipo desde la reserva (con su cliente, que puede ser otro), quitarlo si no se aplicó; se descuenta al facturar al mismo cliente | `reservas/detalle` (modal "Cobrar anticipo") | `ReservaAnticipoController` (usa el módulo Adelantos del sistema base) | hoja de ruta, F1; `docs/planning/retail-facturacion-core/historial-archivo.md` (Adelantos) |

### 2.4 Facturación de la reserva

| Módulo | Qué hace | Pantallas | Backend | Requisitos |
|---|---|---|---|---|
| **Facturar reserva** | **Facturar:** todo lo pendiente en un comprobante, al cliente de la cotización. **Facturación especial:** por grupo de pasajeros, cada uno con su cliente, o solo servicios (p. ej. vuelos a un RUC y el resto a otro). Vista previa con "qué entra y cuánto" (en Facturación especial, con casillas para dejar servicios pendientes); reparto de la línea de tour por pasajero; bloqueo si mezcla tratamientos tributarios; moneda distinta con tipo de cambio y motivo; sucursal y serie; anticipos. Una NC aceptada libera lo acreditado | `reservas/detalle` + `components/AgenciaViajes/FacturarReservaSimpleModal.vue`, `FacturarReservaEspecialModal.vue`, `OpcionesFacturacionCampos.vue`, `DetalleComprobantePreview.vue`, `ClienteComprobanteSelector.vue`; composable `composables/agencia-viajes/useOpcionesFacturacion.ts` | `ReservaFacturacionController`; modelo `ReservaVenta` (`itemIdsFacturados()`, `pasajeroIdsFacturados()`, `liberarPorNotaDeCredito()`); `TourLineaService` (reparto); la venta y el envío a SUNAT son del sistema base | hoja de ruta, filas 11u/11v/11w, F1, F3; `plan-tours-venta-simple-costo-real.md` §6, fase 1c (reparto de la línea de tour) |

### 2.5 Operación

| Módulo | Qué hace | Pantallas | Backend | Requisitos |
|---|---|---|---|---|
| **Salidas operativas** | Agrupa por tour y fecha las reservas que viajan juntas (compartidas o privadas); estados programada/confirmada/realizada/cancelada; servicios a reservar (cantidad automática); vehículos con asignación de reservas; guía, hora y lugar de recojo; órdenes de servicio en PDF; avisos de lo que cambió; reprogramar la salida entera | `salidas/index` + `components/AgenciaViajes/SalidaServiciosVehiculos.vue`, `SalidaOrdenesServicio.vue`, `SalidaReprogramar.vue` | `SalidaOperativaController`, `SalidaServicioController`, `SalidaVehiculoController`, `SalidaEngancheService`, `SalidaServiciosService`, `SalidaOperacionService` | `plan-tours-venta-simple-costo-real.md` §3.6 y §3.13 (fase 2, decisiones D6-D17 en §6) |
| **Reporte operativo** | Qué se opera cada día: filtros por fecha y por destino, tour, hotel o servicio; check-in de pasajeros; reasignar guía o proveedor; Excel y PDF | `reporte-operativo/index` | `ReporteOperativoController`, `AsignacionOperativa` | `plan-modulo-cotizaciones-reservas.md` §8; hoja de ruta, filas 11d/11e |

### 2.6 Transversal

| Tema | Qué hace | Dónde | Requisitos |
|---|---|---|---|
| **Roles y permisos** | 4 roles de Agencia (Administrador, Supervisor, Vendedor, Contador); el vendedor solo ve sus cotizaciones y reservas en los listados; **cada acción sobre una reserva pasa por `ReservaPolicy`** (dueño o `reservas.ver_todas`) | `app/Policies/ReservaPolicy.php`, `CotizacionPolicy.php`, `app/Models/Concerns/EscopablePorVendedor.php`, `database/seeders/AgenciaViajesRolesSeeder.php` | `fase1b-roles-permisos.md`; hoja de ruta F3 |
| **Tratamiento tributario** | IGV por servicio (Gravado 10, Exonerado 20, Inafecto 30); en Agencia, exonerado = Amazonía (regla D18); el XML solo lleva la leyenda 1000 | `Concerns/ExoneradoEsAmazonia` (7 modelos), `admin-start-kit/src/helpers/tratamientoTributarioAgencia.ts` | `plan-tours-venta-simple-costo-real.md` (D18); hoja de ruta F2 |
| **Códigos y numeración** | Códigos únicos por tipo de documento (T/P/C/R/V) con contador con bloqueo | `CodigoGeneradorService` | `plan-modulo-codigos-numeracion.md` |
| **Planes de acceso por módulo** | Qué módulos ve cada tenant según su plan (diseño reconciliado con el Panel Superadmin) | Panel Superadmin | `plan-modulo-planes-acceso.md` |

---

## 3. Diseñado o anotado, sin construir

| Tema | Qué haría | Documento |
|---|---|---|
| **Modificar reserva (F4 etapa 2)** | Desde la reserva, una versión nueva de la alternativa en el cotizador; ver la diferencia con lo vendido; PDF de modificación; penalidad al quitar pasajeros; aplicar solo la diferencia a la reserva | hoja de ruta, fila F4; `plan-modulo-cotizaciones-reservas.md` §4.3 (cambios después de facturada) |
| **Compras (fase 3)** | Comprobantes recibidos contado o crédito, pagos desde Caja o Banco, gastos sin comprobante, rendiciones, tabla única de proveedores, comisiones, SIRE | `plan-tours-venta-simple-costo-real.md` §3.7-§3.8 |
| **Rentabilidad y registros (fases 4-5)** | Cobrado contra gastado por salida; Registro de Ventas y Compras | `plan-tours-venta-simple-costo-real.md` §3.8, §3.9 y §6 |
| **Endoso de pasajeros (13E)** | Enviar o recibir pasajeros entre agencias | `plan-endoso-pasajeros.md` (versión 2, sin aprobar) |
| **Ley de la Amazonía (F2)** | Interruptor "la empresa cumple la ley", leyendas 2001/2002 en PDF y XML para todos los giros, expediente de sustento | hoja de ruta, fila F2 |
| **Recordatorios (11f) y pago a proveedor (11g)** | Motor de avisos; pagos a proveedores y mayoristas | hoja de ruta; `plan-modulo-cotizaciones-reservas.md` §4.6 |
| **Venta directa, bloques 2-5** | Total en vivo, cobrar y emitir en el mismo paso, varias líneas | hoja de ruta (Venta Directa) |

---

## 4. Documentos de esta carpeta: cuál leer y cuál es historia

| Documento | Para qué | Estado |
|---|---|---|
| `00-mapa-de-modulos.md` | Este mapa | vigente |
| `plan-hoja-de-ruta-ejecucion.md` | **Estado de cada fila** (qué está hecho, qué sigue) y últimas actualizaciones | vigente — fuente del estado |
| `plan-modulo-cotizaciones-reservas.md` | **Requisitos base** de proveedores, tarifas, cotización, reserva, itinerarios, integración con ventas | vigente (referencia de diseño) |
| `plan-tours-venta-simple-costo-real.md` | Requisitos de tours, salidas, Compras, rentabilidad; decisiones D6-D18 | vigente — en ejecución |
| `plan-endoso-pasajeros.md` | Endoso entre agencias | diseño sin aprobar |
| `plan-matriz-hoteles-cotizador.md` + `plan-ejecucion-matriz-hoteles-cotizador.md` | Opciones de hotel en una alternativa | completo (referencia) |
| `auditoria-arquitectonica-agencia-viajes.md` + `plan-ejecucion-multidestino-mayoristas.md` | Multidestino y mayoristas | completo salvo 12g |
| `plan-fix-moneda-cotizador.md` | Moneda en el cotizador | parcial (3 puntos abiertos) |
| `plan-mejora-pdf-cotizacion-cliente.md` | PDF comercial | completo (referencia) |
| `plan-modulo-codigos-numeracion.md` | Códigos y numeración | completo |
| `plan-modulo-envio-correo-cotizacion.md` | Envío de la cotización por correo | construido |
| `plan-modulo-planes-acceso.md` | Planes y acceso por módulo | diseño, parte construido en el Panel Superadmin |
| `fase1b-roles-permisos.md` | Roles y permisos de Agencia | completo |
| `pendientes-vigencia-aerea-y-calendarios.md` | Vigencia de la tarifa aérea | hecho |
| `sincronizacion.md` | Cómo mantener sincronizados repo, Proyecto y sesiones de planificación | vigente (protocolo) |
| `historial-archivo.md` | Historia del 25-jul al 12-ago-2026 | archivo |
| `PEGAR-EN-CLAUDE-CODE-13-fase1a/1b/1c-*.md` | Briefs ya ejecutados | archivo — no volver a pegar |
| `PEGAR-EN-CLAUDE-CODE-temporada-plantilla.md` | Brief reemplazado por la fila 13 | archivo — no ejecutar |
| `plan-precio-tour-por-persona.md` | Alternativa evaluada | **descartado** |
| `plan-refactor-mayoristas-tramos.md` | Modelo de tramos | **superado** por `alternativa_destinos` |
| `mockup-pdf-cotizacion.html` | Maqueta del PDF aprobada | referencia |

**Manual de código (para entender y mantener):** `docs/aprendizaje/` — `02-mapa-del-sistema.md` explica cómo
rastrear cualquier acción; `03` a `10` recorren, pantalla por pantalla hasta la tabla, lo construido desde la fase 1c.
