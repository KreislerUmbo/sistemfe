# T-107 — Elegir la dirección del comprobante al emitir (pantallas) (H61)

| | |
|---|---|
| **Módulo** | VEN + AGE + ADE + NOT (todos los giros) |
| **Depende de** | T-065 |
| **Reglas** | ayudas sin fricción (por defecto la fiscal, sin preguntar) |
| **Decisiones** | obs. 18, H61 (aprobada 27-oct-2026); D1-D3 en `docs/tareas/T-065-cliente-otras-direcciones.md` |
| **Rama** | `feat/T-107-elegir-direccion-al-emitir` |

## Contexto
T-065 deja las otras direcciones en el cliente, la API para listarlas y agregarlas, el modal
`DireccionClienteModal.vue` y `client_address_id` en todos los endpoints de emisión. Falta que el
usuario pueda elegirla en las pantallas donde emite. Esta tarea es solo frontend.

## Alcance
- Componente nuevo `components/Clientes/SelectorDireccionComprobante.vue`:
  - Props: `clienteId`, `v-model` (`client_address_id`, `null` = fiscal), `direccionFiscal` (texto).
  - Carga `GET clients/{id}/direcciones` al cambiar el cliente; si cambia el cliente, vuelve a `null`.
  - ~~Solo se muestra si el cliente tiene otras direcciones activas.~~ **Cambio del usuario en la
    revisión (19-nov-2026): se muestra siempre**, como el único campo de dirección (sin cliente,
    deshabilitado con «Elige un cliente»). Opciones: "Fiscal — <dirección>" (por defecto) y cada
    dirección extra como "<nombre> — <dirección>". En venta y edición reemplaza al texto «Dirección»,
    que no se enviaba al backend.
  - Enlace "+ Agregar dirección": abre `DireccionClienteModal.vue`; al guardar, la deja elegida.
    Como el selector está oculto sin otras direcciones, el enlace se muestra solo, debajo del
    cliente (sin alertas ni preguntas).
  - Select `-sm`, igual que el resto del formulario.
- Usarlo y mandar `client_address_id` en:
  - Venta retail: `views/sale/register.vue` (payload ~línea 1688) y `views/sale/edit.vue` (~1647; carga el valor guardado).
  - Factura de reserva: `components/AgenciaViajes/FacturarReservaSimpleModal.vue` (~212) y `FacturarReservaEspecialModal.vue` (~426).
  - Venta directa con cobro: el bloque de cobro que se envía a `venta-directa` (mismos campos de cobro que la factura de reserva: `CobroFacturaCampos.vue` / `OpcionesFacturacionCampos.vue`, ver qué pantalla lo arma).
  - Anticipo: `views/advances/create.vue` (~213).
  - Nota: `views/sale/nota-create.vue`: propone la dirección de la venta modificada (su
    `client_address_id`), editable con el mismo selector; si la venta usó la fiscal, preselecciona la fiscal.

## Fuera de alcance
- Backend, migración y ficha del cliente: T-065.
- Dirección en el XML (retirada sin construir, obs. 18).
- Ticket 80 mm: hoy no imprime dirección y sigue así.

## Dónde tocar
- `admin-start-kit/src/components/Clientes/SelectorDireccionComprobante.vue` (nuevo)
- Las 7 pantallas o componentes de arriba, en el bloque del cliente y en el payload de emisión
- `register.vue` (2176 líneas) y `edit.vue`: buscar `client_selected` y `client_id:` con grep, no leerlos enteros

## Criterios de aceptación
- [ ] **CA1** — Cliente sin otras direcciones → el selector muestra solo la fiscal, con "+ Agregar dirección" (cambio de la revisión: antes no aparecía). → prueba funcional
- [ ] **CA2** — Cliente con 2 sucursales → venta retail con "Sucursal Tarapoto" → PDF con esa dirección; la siguiente sin tocar el selector → fiscal. → prueba funcional
- [ ] **CA3** — "+ Agregar dirección" desde la emisión → queda en el cliente (se ve en la ficha) y en el comprobante. → prueba funcional
- [ ] **CA4** — NC de esa venta → propone "Sucursal Tarapoto"; cambiarla a la fiscal → la NC sale con la fiscal. → prueba funcional
- [ ] **CA5** — Factura de reserva (simple y especial), venta directa con cobro y anticipo en agencia-demo: eligen y la copian. → prueba funcional
- [ ] **CA6** — Usuario con `register_sale` sin `edit_client` → puede elegir y agregar. → prueba funcional
- [ ] **CA7** — `type-check` sin errores nuevos; Vitest del selector (visible/oculto, reset al cambiar cliente) si se extrae lógica.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no hay rutas nuevas (las de T-065)
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: la valida el backend (T-065); el front no confía en el select
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: los nombres de dirección se muestran con interpolación, nunca `v-html`
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
cd admin-start-kit && npm run type-check
```
Prueba funcional (Playwright o a mano), `http://umbo.sistemafe.test:5173` y
`http://agencia-demo.sistemafe.test:5173`: un cliente con RUC sin otras direcciones (CA1) y otro
con "Sucursal Tarapoto" y "Sucursal Yurimaguas" (creadas en la ficha, T-065). Recorrer venta →
editar → NC en umbo; factura de reserva, venta directa con cobro y anticipo en agencia-demo.
Verificar el PDF A4 de cada comprobante (Ghostscript solo al final).

---

## Resultado
- **Commits:** ver `git log feat/T-107-elegir-direccion-al-emitir` (un commit).
- **Tests:** nuevo `test_t107_la_nota_con_client_address_id_null_…` en `DireccionClienteTest`; `DireccionClienteTest` completo en verde (13). **No corrí la suite completa** ni Vitest del selector (no se extrajo lógica aparte); `type-check`: sin errores nuevos (queda el previo de `edit.vue` ~1788, `selectClient(sale.client)`).
- **Prueba funcional (agencia-demo):** CA1 (sin otras direcciones solo sale «Agregar dirección»), CA3 (agregar desde anticipo → queda elegida y la venta 26 guardó `client_address_id` y `direccion_cliente`), CA4 propuesta (NC de esa venta abre con «Sucursal Tarapoto»), y en `register.vue` el selector aparece con fiscal + sucursal. **Pendiente de probar a mano:** PDF A4 de cada comprobante, edición de venta retail (`edit.vue`), factura de reserva simple/especial, venta directa con cobro, CA6 (usuario sin `edit_client`), y todo en umbo (sin credencial; no se reseteó nada).
- **Desviaciones de la tarea y por qué (backend mínimo, la tarea decía solo frontend):**
  1. `SaleResource` no exponía `client_address_id`; sin él `edit.vue` no podía cargar ni reenviar el valor guardado.
  2. `NotaElectronicaController::store()` usaba `filled()`: un `null` explícito se trataba como «no enviado» y la NC heredaba la sucursal, así que no se podía pasar a la fiscal (CA4). Ahora `has()`: null = fiscal (texto = `clients.address` actual). Además se acepta la misma dirección de la venta aunque se haya desactivado después (si no, proponerla daba 422).
  3. `components.d.ts` se regeneró solo (registra el selector).
- **Detalles de diseño:** el selector solo vuelve a `null` cuando cambia de un cliente a otro (no en la primera carga), para respetar el valor hidratado al editar; muestra la dirección ya elegida aunque esté desactivada. En `register.vue`/`edit.vue` solo hay el uso del componente, la variable y el campo del payload.

- **Revisión (Opus, 19-nov-2026):**
  - **Regresión corregida:** con `has()`, la NC desde la pantalla (que manda `null` cuando la venta usó la fiscal) copiaba la fiscal **actual** del cliente: deshacía T-064 para toda NC. Ahora, si la dirección elegida es la misma de la venta, la nota conserva el texto copiado en la venta; solo al cambiar se copia de nuevo (de sucursal a fiscal: la fiscal de hoy, la única que hay). Test `test_la_nota_con_la_misma_direccion_que_la_venta_conserva_el_texto_de_la_venta`.
  - **Un solo campo de dirección** (pedido del usuario): el selector se muestra siempre y en venta/edición reemplaza al texto «Dirección», que no se enviaba (se quitó `client_address` de `register.vue` y `edit.vue`).
  - En los modales de factura de reserva la opción dice solo «Fiscal»: el cliente que reciben no trae `address` (habría que tocar dos endpoints); queda así.
  - Prueba funcional en agencia-demo (Chromium por script): venta nueva sin cliente (deshabilitado), con ECORUTAS (fiscal + 2 sucursales) y con SELVA TOURS (solo fiscal + agregar); edición de la NV 27 abre con «Sucursal Tarapoto»; NC de la venta 26 la propone; PDF A4 de la NV 27 imprime «Jr. Lima 150 (editada)». Quedan en agencia-demo la NV 27 (NV01 sin número, a ECORUTAS, S/ 118, por cobrar) y el anticipo 26 de Sonnet. Sin probar: factura de reserva y venta directa con cobro en pantalla (el backend lo cubren los tests), CA6 y umbo.

## Hallazgos fuera de alcance
- Ninguno (el de la NC era una regresión de esta tarea: corregida en la revisión).
