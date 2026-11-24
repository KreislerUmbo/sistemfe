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
- Listado **«proveedores sin documento»** para limpiar (excluye los `es_referencial`).
- **Teléfono**: en el recorrido de la fase 2 los 3 proveedores de una orden no tenían teléfono (WhatsApp inútil). Mostrar el aviso «sin teléfono» al crear y permitir completarlo desde el listado.
- Permisos de quién crea proveedores y desde dónde.

## Fuera de alcance
- Modelo de sucursales por RUC (paso 2; solo si las cadenas se vuelven frecuentes).
- La regla «al registrar la primera compra se pide completar el RUC»: va en T-135.
- Comisiones y condiciones de pago por defecto en la ficha (T-143, T-146).

## Dónde tocar
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/ProveedorController.php` → `store()`, `index()` (filtros), endpoint de aviso por RUC.
- `api-sistema-fe/app/Models/AgenciaViajes/Proveedor.php` (columnas) y su migración nueva.
- Consulta de RUC: buscar el servicio existente que usa Clientes antes de crear otro (`grep -ri "consulta.*ruc\|ruc" app/Services`).
- `admin-start-kit/src/views/agencia-viajes/proveedores/` (formulario) y `ProveedorBuscador.vue`.

## Criterios de aceptación
- [ ] **CA1** — Crear un proveedor con un RUC ya usado devuelve el aviso con los existentes; con confirmación se crea.
- [ ] **CA2** — Escribir un RUC válido en el alta rápida trae razón social y dirección fiscal; un RUC inválido no rompe el formulario.
- [ ] **CA3** — El buscador muestra la ciudad al lado del nombre.
- [ ] **CA4** — El listado «sin documento» no incluye referenciales.
- [ ] **CA5** — Un usuario sin el permiso de crear proveedores no ve el alta rápida ni puede llamarla por API.

## Seguridad y correctitud
- [ ] Permisos / Policy: permiso de crear proveedor desde Compras.
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
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
