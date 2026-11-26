# T-157 — País del proveedor no domiciliado con el código de SUNAT

| | |
|---|---|
| **Módulo** | COM |
| **Depende de** | — (T-134 ya está en `main`) |
| **Reglas** | — |
| **Decisiones** | `plan-tours-venta-simple-costo-real.md` §3.7 (no domiciliados en el Registro de Compras); hallazgo de T-134 |
| **Rama** | `feat/T-157-pais-no-domiciliado` |

## Contexto
T-134 ya dejó al proveedor no domiciliado:
- `proveedores.es_no_domiciliado` (boolean) y `proveedores.pais` (texto libre, 80);
- migración `tenant/core/2026_11_26_100000`;
- el documento del exterior en `tipo_documento` / `numero_documento`, sin validar RUC;
- `ProveedorDocumentoService::preparar()` exige el país si es no domiciliado y lo borra si no;
- el aviso de documento repetido compara también el país.

Falta que el país sea un **código**. El Registro de Compras de no domiciliados pide el país de
residencia con el código de la tabla 35 de SUNAT, y con texto libre «Chile», «chile» y «CL»
serían tres países.

**Preguntas abiertas (resolver antes de construir):**
- **Q1 — Formato del país:** ¿alcanza con el código de la tabla 35 de SUNAT? Propuesta: sí, con
  un catálogo central de solo lectura.
- **Q2 — Documento del exterior:** ¿el Registro pide un tipo de documento de una tabla SUNAT, o
  alcanza con el texto libre de hoy? Propuesta, si no hay respuesta: dejarlo como está.

Si las respuestas cambian el modelo, se actualiza esta tarea antes de empezar.

## Alcance
- **Catálogo central de países** (`CentralConnection`) con la tabla 35 de SUNAT: código y
  nombre, sembrado por seeder. Endpoint de lectura para el selector. Sin FK cross-DB: el código
  se valida en PHP.
- **`proveedores.pais` pasa a guardar el código.** Migración en `tenant/core/` que convierte los
  valores existentes: por nombre sin tildes ni mayúsculas, o por código si ya lo es. Lo que no
  se pueda convertir queda como está y se lista en la salida de la migración, sin perderse. Una
  migración nueva: no se edita la de T-134.
- **Validación:** `ProveedorDocumentoService::preparar()` rechaza un país que no está en el
  catálogo, solo cuando el país cambia, como hace hoy con el RUC.
- **Pantallas:** selector de país en lugar del texto libre, en `ProveedorAltaRapida.vue` y en
  `views/agencia-viajes/proveedores/form.vue`. Los listados muestran el nombre del país, no el código.

## Fuera de alcance
- El Registro de Compras y su parte de no domiciliados (fase 8 / PLE).
- Retenciones o impuestos a no domiciliados.
- Moneda extranjera de la compra (va con el comprobante, T-135).

## Dónde tocar
- `api-sistema-fe/database/migrations/` central (catálogo) y su seeder;
  `database/migrations/tenant/core/` (conversión de `pais`).
- `api-sistema-fe/app/Services/ProveedorDocumentoService.php` — `preparar()`.
- Frontend: `components/Proveedores/ProveedorAltaRapida.vue`,
  `views/agencia-viajes/proveedores/form.vue` e `index.vue`, `views/cash/proveedores.vue`.

## Criterios de aceptación
- [ ] **CA1** — Un no domiciliado se crea con un código de país del catálogo; un código que no
  existe da 422.
- [ ] **CA2** — La migración convierte «Chile» y «chile» al mismo código, y deja sin tocar un
  valor que no reconoce (y lo informa).
- [ ] **CA3** — Editar otro dato de un proveedor con un país viejo sin convertir no da error.
- [ ] **CA4** — Los listados muestran el nombre del país.

## Seguridad y correctitud
- [ ] Permisos / Policy: los mismos de T-134 (`proveedores.gestionar`).
- [ ] Entrada: el código de país se valida contra el catálogo central.
- [ ] Migraciones nuevas: la central con `migrate` de la central; la de tenant con `tenants:migrate-verticales`.
- [ ] Tributario: Q1 y Q2 confirmadas antes de construir.
- [ ] Aislamiento de tenant, concurrencia, dinero, fechas, `v-html`: no aplica.

## Cómo verificar
```
php artisan test --filter=Proveedor
```
Pantalla: alta de un hotel del extranjero en agencia-demo con el selector de país.

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
