# T-134 — Proveedor: RUC no único con aviso, direcciones, ciudad, alta rápida y «sin documento»

| | |
|---|---|
| **Módulo** | COM |
| **Depende de** | T-133 |
| **Reglas** | — |
| **Decisiones** | `plan-tours-venta-simple-costo-real.md` §3.7c («Paso 1»); revisión de la tarea del 25-nov-2026 (abajo) |
| **Rama** | `feat/T-134-proveedor-ruc-y-alta-rapida` |

## Contexto
Con el catálogo único, un proveedor se puede crear desde Compras o Caja con pocos datos, y un
mismo RUC puede ser varios establecimientos (cadena de hoteles). Hay que evitar duplicados sin
bloquear el caso legítimo, y que Compras pueda crear un proveedor en segundos.

**Estado del código al empezar (después de T-133):**
- Hoy crean proveedores **dos controladores**. El de Agencia
  (`AgenciaViajes\ProveedorController::store/update`, rutas `proveedores`, permiso
  `agencia.proveedores`, con su propia validación) y el base (`App\Http\Controllers\ProveedorController`,
  rutas `proveedores-base`, permisos `proveedores.ver` / `proveedores.gestionar`).
- La tabla `proveedores` **no tiene ciudad** (T-083 quitó las columnas de ubigeo). Tiene una sola `direccion`.
- La consulta RUC existe: `Client\ClientController::searchDocument` (apisperu), ruta
  `/search-document/{type}/{number}`, y la usa el formulario de Agencia. Tiene dos problemas:
  la ruta no exige permiso, y ante una excepción devuelve `$e->getMessage()` al cliente.

**Decisiones de la revisión del 25-nov (aprobadas por el usuario):**
- **D1 — Direcciones:** se agrega una sola columna, `direccion_fiscal`. La `direccion` que ya
  existe pasa a ser **la del local**, sin mover datos.
- **D2 — Ciudad:** columna nueva `ciudad` (texto, opcional, editable). En el alta con RUC se
  llena con la provincia de la consulta, si viene.
- **D3 — Aviso por RUC en un solo lugar:** la búsqueda de proveedores con el mismo documento vive
  en el modelo base (p. ej. `Proveedor::conMismoDocumento()`). La usan los dos `store()`.
- **D4 — Alta rápida = componente reutilizable**, un modal (`ProveedorAltaRapida.vue`) que llama a
  `proveedores-base`. Ahora se monta en el buscador de proveedores y en Caja → Proveedores;
  Compras (T-135) lo reusa.
- **D5 — «Datos incompletos» se calcula**, sin columna: no tiene documento, o no tiene dirección
  fiscal. El API lo devuelve como atributo y no se guarda.
- **D6 — Consulta RUC propia del proveedor:** `GET proveedores-base/consulta-ruc/{ruc}` con
  `proveedores.gestionar`. Valida 11 dígitos, devuelve solo razón social, dirección fiscal y
  ciudad, y en los errores da un mensaje genérico (el detalle va al log). Usa el mismo token de
  apisperu. `ClientController::searchDocument` **no se toca** (ver «Hallazgos»).
- **D7 — «Sin documento» y teléfono en las dos pantallas:** el backend da el filtro
  `?sin_documento=1`. Lo usan Agencia → Proveedores y Caja → Proveedores, y en las dos el
  teléfono se completa en la misma fila.
- **D8 — El proveedor no domiciliado sale a T-157.** Tiene su pregunta tributaria (formato del
  país y del documento para el Registro de Compras) y no debe frenar esta tarea.
- **D9 — Entra el control «ver todos»** en Agencia → Proveedores (hallazgo de T-133): es la
  misma pantalla y el backend ya acepta `?alcance=todos`.

## Alcance
- **Migración** en `tenant/core/`: `direccion_fiscal` (text, nullable) y `ciudad` (string 120,
  nullable) en `proveedores`. Agregarlas a `Proveedor::CAMPOS_BASICOS` y a la validación de los
  dos controladores.
- **RUC no único con aviso (D3):** al crear un proveedor con un `numero_documento` ya usado, los
  dos `store()` responden **409** con la lista de los que ya lo tienen (id, razón social, nombre
  comercial, ciudad, dirección). Con `confirmar_mismo_documento=true` se crea igual. Solo
  compara documentos no vacíos y no se aplica al editar. En pantalla: «¿Es otro establecimiento?»
  → crear, o «Ir al existente».
- **Ciudad visible (D2):** el buscador de proveedores (`ProveedorBuscador.vue`) y los listados de
  Agencia y Caja muestran «Nombre — Ciudad» cuando hay ciudad. La búsqueda por texto
  (`search`) también busca en `ciudad`.
- **Consulta RUC (D6)** y **alta rápida (D4):** se escribe el RUC y la consulta llena razón
  social, dirección fiscal y ciudad. Lo demás es opcional. Si la consulta falla o el RUC no es
  válido, se completa a mano y se puede guardar igual. Al guardar se muestra la marca «datos
  incompletos» (D5) si corresponde. El formulario completo de Agencia también llena
  `direccion_fiscal` y `ciudad` con su botón de búsqueda.
- **«Sin documento» (D7):** `?sin_documento=1` = `numero_documento` vacío o nulo, **excluye
  `es_referencial`**. Es un interruptor en los dos listados.
- **Teléfono (D7):** si no hay teléfono ni celular, el alta muestra el aviso «sin teléfono», y los
  listados muestran un ícono con edición en la misma fila (vía `PUT proveedores-base/{id}`).
  Es solo un aviso: no bloquea.
- **«Ver todos» en Agencia → Proveedores (D9):** un interruptor que manda `?alcance=todos` al
  index de Agencia. Así un proveedor general se puede abrir para ponerle tipo turístico.
- **Permisos:** el alta rápida y la consulta RUC piden `proveedores.gestionar`, que ya incluye a
  quien tiene `agencia.proveedores`. Sin ese permiso, el botón no se muestra.

## Fuera de alcance
- **Proveedor no domiciliado:** T-157.
- Modelo de sucursales por RUC (paso 2; solo si las cadenas se vuelven frecuentes).
- Elegir establecimiento o «General» al escribir el RUC en Compras: va en T-135.
- La regla «al registrar la primera compra se pide completar el RUC»: va en T-135.
- Condiciones de pago por defecto en la ficha (T-142) y comisiones (T-145).
- Vínculo con el catálogo central de aerolíneas (T-146).
- Corregir `ClientController::searchDocument` (permiso y mensaje de error): es de Clientes.

## Dónde tocar
- `api-sistema-fe/database/migrations/tenant/core/` — migración nueva (D1, D2).
- `api-sistema-fe/app/Models/Proveedor.php` — `CAMPOS_BASICOS`, `conMismoDocumento()`, el
  atributo `datos_incompletos` y el scope `sinDocumento`.
- `api-sistema-fe/app/Http/Controllers/ProveedorController.php` — `store()` (409), `index()`
  (`sin_documento`, búsqueda por ciudad), `consultaRuc()`.
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ProveedorController.php` — `store()` con
  el mismo 409, validación de las columnas nuevas (~línea 412) e `index()` con `alcance` y
  `sin_documento`.
- `api-sistema-fe/routes/api.php` — `proveedores-base/consulta-ruc/{ruc}`, junto a las rutas
  `proveedores-base` (registrarla antes de `proveedores-base/{id}`).
- Frontend: `ProveedorAltaRapida.vue` nuevo (en `components/`),
  `components/AgenciaViajes/ProveedorBuscador.vue`, `views/agencia-viajes/proveedores/index.vue` y
  `form.vue`, `views/cash/proveedores.vue`, `types/cash.ts`.

## Criterios de aceptación
- [ ] **CA1** — Crear un proveedor con un RUC ya usado devuelve 409 con los existentes (nombre,
  ciudad, dirección), por `proveedores-base` y por `proveedores` de Agencia. Con
  `confirmar_mismo_documento` se crea. Editar no avisa.
- [ ] **CA2** — `consulta-ruc` con un RUC válido trae razón social, dirección fiscal y ciudad. Un
  RUC que no tiene 11 dígitos da 422 sin llamar a apisperu. Si apisperu falla, el mensaje es
  genérico y no contiene el detalle de la excepción. El formulario se puede guardar igual.
- [ ] **CA3** — El buscador y los dos listados muestran «Nombre — Ciudad». Buscar por la ciudad
  encuentra al proveedor.
- [ ] **CA4** — `?sin_documento=1` lista los que no tienen documento y no incluye referenciales.
- [ ] **CA5** — Un usuario sin `proveedores.gestionar` no ve el alta rápida y recibe 403 en
  `POST proveedores-base` y en `consulta-ruc`.
- [ ] **CA6** — `datos_incompletos` es verdadero sin documento o sin dirección fiscal, y falso con los dos.
- [ ] **CA7** — En Agencia → Proveedores, «ver todos» muestra también los proveedores generales.

## Seguridad y correctitud
- [ ] Permisos / Policy: `proveedores.gestionar` para crear y consultar RUC; el index de Agencia
  sigue con `agencia.proveedores`.
- [ ] Entrada: el RUC de la consulta, 11 dígitos numéricos antes de armar la URL externa; los
  errores de la consulta no devuelven datos internos.
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: sí (probar en una tienda, en créditos y en una agencia).
- [ ] Aislamiento de tenant, concurrencia, dinero, fechas, tributario, `v-html`: no aplica.

## Cómo verificar
```
php artisan test --filter=Proveedor
```
Pantalla, en agencia-demo:
- alta de un proveedor con un RUC repetido: aparece el aviso y se puede crear como otro establecimiento;
- alta rápida desde Caja → Proveedores con consulta RUC;
- interruptores «sin documento» y «ver todos»;
- completar un teléfono en la misma fila.

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- `/search-document/{type}/{number}` (`Client\ClientController::searchDocument`) no exige permiso
  y devuelve `$e->getMessage()` al cliente si falla (revisión del 25-nov). Pasado a «Por analizar» del backlog.
