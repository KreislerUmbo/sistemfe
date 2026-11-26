# T-157 — Proveedor no domiciliado: país y documento del exterior

| | |
|---|---|
| **Módulo** | COM |
| **Depende de** | T-134 |
| **Reglas** | — |
| **Decisiones** | `plan-tours-venta-simple-costo-real.md` §3.7 (revisión del 24-nov: no domiciliados en el Registro de Compras); sale de T-134 (D8, revisión del 25-nov-2026) |
| **Rama** | `feat/T-157-proveedor-no-domiciliado` |

## Contexto
Un hotel o un operador del extranjero en un paquete internacional es un proveedor sin RUC. El
Registro de Compras lo pide en su parte de no domiciliados, con su país de residencia y su
documento del exterior. Se separó de T-134 porque tiene una pregunta tributaria propia y no
debía frenar lo demás del proveedor.

**Pregunta abierta (resolver antes de construir; no está en P13):**
- **Q1 — Formato del país:** ¿se guarda con el código de la **tabla 35 de SUNAT** (países), que
  es lo que usa el Registro de Compras de no domiciliados? La propuesta es sí, con un catálogo
  central de solo lectura.
- **Q2 — Documento del exterior:** ¿alcanza con reusar `tipo_documento` / `numero_documento`
  (texto libre, sin validación de RUC) o el Registro pide una tabla de tipos? Si no hay
  respuesta del contador, la propuesta es reusar las columnas, con `tipo_documento` en un valor
  nuevo (`DOC_EXT`, «documento del exterior»).

Si las respuestas cambian el modelo, se actualiza esta tarea antes de empezar.

## Alcance
- **Migración** en `tenant/core/`: `no_domiciliado` (boolean, default false) y `pais` (código,
  nullable) en `proveedores`. Agregar los dos a `CAMPOS_BASICOS` y a la validación de los dos
  controladores (base y Agencia).
- **Catálogo de países** (Q1): tabla central (`CentralConnection`) con la tabla 35 de SUNAT,
  sembrada por seeder. Endpoint de lectura para el selector. Sin FK cross-DB: validar el código en PHP.
- **Reglas del alta:** si `no_domiciliado`, se pide `pais` y se acepta el documento del exterior
  sin validar los 11 dígitos. No se ofrece la consulta RUC. El aviso de documento repetido de
  T-134 sigue aplicando.
- **«Sin documento» (T-134):** un no domiciliado con su documento del exterior **no** cuenta como
  sin documento. Sin documento, sí.
- **«Datos incompletos» (T-134, D5):** para un no domiciliado, la dirección fiscal no se exige; falta solo si no hay país o documento.
- **Pantallas:** el formulario de Agencia, el alta rápida (`ProveedorAltaRapida.vue`) y Caja →
  Proveedores muestran «No domiciliado» con el país. En los listados, una marca y el país en vez de la ciudad, si no tiene ciudad.

## Fuera de alcance
- El Registro de Compras y su parte de no domiciliados (fase 8 / PLE).
- Retenciones o impuestos a no domiciliados.
- Moneda extranjera de la compra (va con el comprobante, T-135).

## Dónde tocar
- `api-sistema-fe/database/migrations/tenant/core/` (columnas) y `database/migrations/` central (catálogo de países) con su seeder.
- `api-sistema-fe/app/Models/Proveedor.php` — campos, el scope `sinDocumento` y `datos_incompletos`.
- `api-sistema-fe/app/Http/Controllers/ProveedorController.php` y `AgenciaViajes/ProveedorController.php` — validación.
- Frontend: `ProveedorAltaRapida.vue`, `views/agencia-viajes/proveedores/form.vue` e `index.vue`, `views/cash/proveedores.vue`.

## Criterios de aceptación
- [ ] **CA1** — Un proveedor no domiciliado se crea con país y documento del exterior sin pedir
  RUC. Un país que no está en el catálogo da 422.
- [ ] **CA2** — Un no domiciliado con documento no aparece en `?sin_documento=1`. Sin documento, sí aparece.
- [ ] **CA3** — Un domiciliado con `tipo_documento=RUC` sigue validando los 11 dígitos.
- [ ] **CA4** — `datos_incompletos` de un no domiciliado no depende de la dirección fiscal.

## Seguridad y correctitud
- [ ] Permisos / Policy: los mismos de T-134 (`proveedores.gestionar`).
- [ ] Entrada: código de país validado contra el catálogo central.
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: sí (tenant). La central, con `migrate` de la central.
- [ ] Tributario: Q1 y Q2 confirmadas antes de construir.
- [ ] Aislamiento de tenant, concurrencia, dinero, fechas, `v-html`: no aplica.

## Cómo verificar
```
php artisan test --filter=Proveedor
```
Pantalla: alta de un hotel del extranjero en agencia-demo; verificar que no aparece en «sin documento».

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
