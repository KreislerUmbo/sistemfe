# T-134 — Proveedor: RUC no único con aviso, direcciones, alta rápida y «sin documento»

| | |
|---|---|
| **Módulo** | COM |
| **Depende de** | T-133 |
| **Reglas** | — |
| **Decisiones** | `plan-tours-venta-simple-costo-real.md` §3.7c («Paso 1») |
| **Rama** | `feat/T-134-proveedor-ruc-y-alta-rapida` |

## Contexto
Con el catálogo único, un proveedor se puede crear desde Compras o Caja con pocos datos, y un
mismo RUC puede ser varios establecimientos (cadena de hoteles). Hay que evitar duplicados sin
bloquear el caso legítimo, y que Compras pueda crear un proveedor en segundos.

## Alcance
- Columnas nuevas: **dirección fiscal** (de la consulta RUC) y **dirección del local** si es distinta. Migración en `tenant/core/`.
- El RUC **no es único**: al crear uno con RUC existente, aviso con los existentes (nombre, ciudad, dirección) y confirmar «es otro establecimiento» o ir al existente.
- **Ciudad visible** en listas y buscadores («Casa Andina — Tarapoto»).
- **Alta rápida** (Compras → «Nuevo proveedor»): RUC → razón social y dirección con la consulta de RUC existente; resto opcional; marcado «datos incompletos».
- **Proveedor no domiciliado** (revisión del 24-nov, §3.7 del plan): hotel u operador del extranjero en un paquete internacional. Marca «no domiciliado», **país** y documento del exterior (tipo y número, sin validación de RUC); no pasa por la consulta RUC. Lo necesita el Registro de Compras (parte de no domiciliados). Migración en `tenant/core/`.
- Listado **«proveedores sin documento»** para limpiar (excluye los `es_referencial` y no cuenta como «sin documento» a un no domiciliado con su documento del exterior).
- **Teléfono**: en el recorrido de la fase 2 los 3 proveedores de una orden no tenían teléfono (WhatsApp inútil). Mostrar el aviso «sin teléfono» al crear y permitir completarlo desde el listado.
- Permisos de quién crea proveedores y desde dónde: `proveedores.gestionar` (creado en T-133).

## Fuera de alcance
- Modelo de sucursales por RUC (paso 2; solo si las cadenas se vuelven frecuentes).
- La regla «al registrar la primera compra se pide completar el RUC»: va en T-135.
- Condiciones de pago por defecto en la ficha (T-142) y comisiones (T-145).
- Vínculo con el catálogo central de aerolíneas (T-146).

## Dónde tocar
- El controlador base de proveedores que crea T-133 → `store()`, `index()` (filtros), endpoint de aviso por RUC. Lo turístico sigue en `AgenciaViajes/ProveedorController.php`.
- `api-sistema-fe/app/Models/Proveedor.php` (columnas, ya movido por T-133) y su migración nueva.
- Consulta de RUC: buscar el servicio existente que usa Clientes antes de crear otro (`grep -ri "consulta.*ruc\|ruc" app/Services`).
- `admin-start-kit/src/views/agencia-viajes/proveedores/` (formulario) y `ProveedorBuscador.vue`.

## Criterios de aceptación
- [ ] **CA1** — Crear un proveedor con un RUC ya usado devuelve el aviso con los existentes; con confirmación se crea.
- [ ] **CA2** — Escribir un RUC válido en el alta rápida trae razón social y dirección fiscal; un RUC inválido no rompe el formulario.
- [ ] **CA3** — El buscador muestra la ciudad al lado del nombre.
- [ ] **CA4** — El listado «sin documento» no incluye referenciales.
- [ ] **CA5** — Un usuario sin `proveedores.gestionar` no ve el alta rápida ni puede llamarla por API.
- [ ] **CA6** — Un proveedor no domiciliado se crea con país y documento del exterior sin pedir RUC, y no aparece en «sin documento».

## Seguridad y correctitud
- [ ] Permisos / Policy: `proveedores.gestionar` para crear, también desde Compras.
- [ ] Entrada: validación del RUC (11 dígitos, tipo de documento); el servicio de consulta no devuelve datos internos en errores.
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: sí.
- [ ] Aislamiento de tenant, concurrencia, dinero, fechas, tributario, `v-html`: no aplica.

## Cómo verificar
```
php artisan test --filter=Proveedor
```
Pantalla: alta de un proveedor con RUC repetido en agencia-demo.

---

## Resultado
- **Commits:** `3f230f25` (backend), `f3108624` (pantallas), `ffbd59f9` (dorados de T-095).
- **Tests:** CA1, CA2, CA3, CA4 y CA6 en `ProveedorRucYAltaRapidaTest`; CA5 en
  `ProveedorBaseTest::test_t134_ca5_el_alta_rapida_pide_gestionar` (HTTP, tenant nuevo); CA2 y CA3
  también en `helpers/proveedor.test.ts`. Suite del backend: 2484 tests, 2483 en verde; falló solo el intermitente conocido (`Fila13Fase1cFacturacionTest::test_descuento_del_combo…`), que solo pasa (34/34). Frontend: Vitest 360/360,
  `type-check` y `build` limpios.
- **Prueba en pantalla (agencia-demo):** alta rápida con un RUC real (trae razón social, dirección
  fiscal y ciudad), RUC inexistente (mensaje y el formulario sigue), RUC repetido en alta rápida y
  en Agencia (aviso y «crear igual»), no domiciliado, filtro «sin documento» y «ver todos»
  (41 turísticos). Los proveedores de prueba se borraron. La ciudad en el buscador y en la
  contraparte de Caja se verificó por test, no en pantalla.
- **Al desplegar:** `tenants:migrate-verticales` (4 columnas nuevas en `proveedores`).
- **Desviaciones de la tarea y por qué:**
  - Columna `ciudad` en texto libre (decidido: no hay ubigeo desde T-083). La llena la provincia de
    la consulta RUC, o el distrito si no hay provincia.
  - `direccion` queda como dirección del local; `direccion_fiscal` es nueva. El formulario de Agencia
    ahora pone la dirección de SUNAT en la fiscal, no en la del local.
  - «Datos incompletos» se calcula (sin documento, teléfono o dirección), no es una columna.
  - El aviso de repetido compara el número de documento (de cualquier tipo); en un no domiciliado,
    además el país. Incluye inactivos.
  - Un no domiciliado exige país; el documento del exterior es opcional (sin él sale en «sin
    documento»).
  - El RUC de 11 dígitos se valida solo cuando el documento cambia, para no bloquear la edición de
    datos viejos.
  - La consulta de documento ya no devuelve el mensaje de la excepción (traía la URL con el token) y
    lo registra en el log sin el token.
  - Se actualizaron tests de T-132, T-133 y T-095 que comparan las columnas del proveedor.

## Hallazgos fuera de alcance
- `searchDocument` reenvía el código HTTP de apisperu: un 401 (token vencido) haría que el panel
  cierre la sesión del usuario. Tampoco valida el formato del número antes de armar la URL.
- El aviso de documento repetido solo está en el alta. No avisa al **editar** y poner un documento
  que ya existe, ni al «Promover a proveedor» desde el cotizador
  (`AlternativaItemController::promoverAProveedor`, `OpcionHotelController`), que crean directo.
- El país es texto libre: no hay catálogo. El Registro de Compras de no domiciliados pide el código
  de país de SUNAT (tabla 35).
- La base local de agencia-demo tiene un ítem de menú viejo (`suppliers.index`, anterior a T-133) que
  da error en consola; el seeder ya está bien.
