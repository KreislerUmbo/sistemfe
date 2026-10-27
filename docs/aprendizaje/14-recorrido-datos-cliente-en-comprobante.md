# Recorrido: los datos del cliente se copian al comprobante (T-064)

27-oct-2026, tarea **T-064** (`docs/tareas/T-064-datos-cliente-copiados-al-emitir.md`), obs. 18 de
`docs/requerimientos/observaciones-pruebas-usuario.md`.

---

## 1. El problema

El PDF de una factura, boleta o nota imprimía el documento, la razón social y la dirección **del
cliente tal como está hoy**, no como estaba al emitir. Si alguien corregía un cliente (por ejemplo,
un RUC mal escrito), **todos** sus comprobantes anteriores se reimprimían con el dato nuevo y
dejaban de coincidir con el XML que SUNAT ya había aceptado. Lo mismo pasaba con el XML (al
reenviar) y con el QR.

La venta ya guardaba una copia de **un** dato del cliente: `cod_tipo_doc_cliente` (el código SUNAT
del tipo de documento). La solución sigue ese mismo criterio para los demás.

## 2. Archivos

| Archivo | Nuevo o cambiado | Qué hace |
|---|---|---|
| `api-sistema-fe/database/migrations/tenant/core/2026_10_27_100000_add_datos_cliente_to_sales_y_notes.php` | **nuevo** | 4 columnas en `sales` y `notes` y las llena en los comprobantes existentes |
| `api-sistema-fe/app/Models/Concerns/CopiaDatosDelCliente.php` | **nuevo** | trait: copia al crear, `copiarDatosDelCliente()` y `datosClienteImpresos()` |
| `api-sistema-fe/app/Models/Sale/Sale.php`, `Note.php` | cambiado | usan el trait; columnas en `$fillable` |
| `api-sistema-fe/app/Http/Controllers/Sale/NotaElectronicaController.php` | cambiado | la nota recibe la copia de su venta |
| `api-sistema-fe/app/Http/Controllers/Sale/SaleController.php` | cambiado | al editar, rehace la copia |
| `api-sistema-fe/app/Http/Controllers/Sale/FacturacionElectronicaController.php` | cambiado | al enviar sin CDR, rehace la copia |
| `api-sistema-fe/app/Http/Controllers/Greenter/GreenterService.php` | cambiado | el receptor del XML sale de la copia |
| `api-sistema-fe/app/Services/QrCodeService.php` | cambiado | el número de documento del QR sale de la copia |
| `api-sistema-fe/resources/views/pdf/a4`, `ticket80mm`, `nota_a4`, `nota_ticket80mm` | cambiado | imprimen la copia |
| `api-sistema-fe/tests/Feature/DatosClienteEnComprobanteTest.php` | **nuevo** | 6 tests |

## 3. Las columnas

En `sales` y en `notes`:

| Columna | Copia de | Para |
|---|---|---|
| `tipo_doc_cliente` | `clients.type_document` ("RUC", "DNI"…) | el rótulo del PDF |
| `num_doc_cliente` | `clients.n_document` | PDF, XML y QR |
| `razon_social_cliente` | `clients.full_name` | PDF y XML |
| `direccion_cliente` | `clients.address` | PDF (T-065 permitirá elegir otra) |

Los comprobantes que ya existían se llenaron con los datos **actuales** del cliente: es lo que el
PDF mostraba hasta ahora. Lo que se envió de verdad está en el XML guardado, no en columnas.

## 4. Cuándo se copia y cuándo ya no cambia

```
crear la venta ──► se copia sola (trait, evento "creating")
     │
editar la venta (sin CDR) ──► se vuelve a copiar      SaleController::update()
     │
enviar a SUNAT (sin CDR) ──► se vuelve a copiar       FacturacionElectronicaController::enviarSunat()
     │
SUNAT acepta (tiene CDR) ──► la copia ya no cambia nunca

nota de crédito/débito ──► copia la de su venta (no la del cliente de hoy)
```

¿Por qué se vuelve a copiar antes de que SUNAT acepte? Porque mientras el comprobante no fue
aceptado, lo correcto es enviar los datos buenos: si el vendedor corrige el RUC antes de enviar,
el XML sale corregido. Una vez aceptado, el comprobante es un documento cerrado.

¿Por qué la nota copia de la venta? Una nota de crédito modifica una factura: su receptor tiene
que ser **el mismo** de esa factura, aunque después el cliente haya cambiado.

## 5. Las funciones, una por una

**`CopiaDatosDelCliente::bootCopiaDatosDelCliente()`** — Laravel llama solo a los métodos
`boot<NombreDelTrait>()` al cargar el modelo. Aquí registra un evento `creating`: justo antes de
insertar una venta o una nota, si nadie llenó `razon_social_cliente`, la copia del cliente. Por eso
no hubo que tocar los tres lugares que crean ventas (venta, factura de reserva, adelanto): el
modelo se encarga.

**`copiarDatosDelCliente()`** — lee el cliente (incluso uno borrado, con `withTrashed()`) y llena
las 4 columnas. **No guarda**: quien la llama decide cuándo, dentro de su propia transacción.

**`datosClienteImpresos()`** — devuelve los 4 datos para imprimir: la copia si existe; si no (un
comprobante viejo sin copia), el cliente, como antes. El PDF, el XML y el QR usan siempre esta
función, nunca `$venta->client->...` directo.

## 6. Tests

`tests/Feature/DatosClienteEnComprobanteTest.php`:

| Test | Comprueba |
|---|---|
| `la_venta_copia_los_datos_del_cliente_al_crearse` | el evento `creating` |
| `corregir_el_cliente_no_cambia_el_pdf_ni_el_xml_de_un_comprobante_aceptado` | PDF A4 y ticket dibujados a HTML, y el receptor del XML |
| `comprobante_anterior_sin_copia_sigue_mostrando_el_cliente` | el respaldo de `datosClienteImpresos()` |
| `al_enviar_sin_cdr_se_toman_los_datos_corregidos` | la copia se rehace al enviar (sin SUNAT: corta antes con 422) |
| `un_comprobante_aceptado_no_rehace_la_copia_al_reenviar` | con CDR no cambia |
| `la_nota_de_credito_lleva_los_datos_de_su_venta` | la NC creada por `NotaElectronicaController::store()` |

## 7. Ejercicios

1. Busca con `grep -rn "client->n_document" api-sistema-fe/app` dónde más se lee el documento del
   cliente directamente. ¿Alguno es un comprobante (debería usar la copia) o son listados (está
   bien que muestren el cliente de hoy)?
2. Si mañana el comprobante también debe guardar el **correo** del cliente, ¿qué tres lugares
   tocarías? (Pista: la migración, el trait y `$fillable`.)
3. En `enviarSunat()`, ¿qué pasaría si la copia se rehiciera **después** de reservar el
   correlativo y el envío fallara? ¿Cambia algo para el usuario?
