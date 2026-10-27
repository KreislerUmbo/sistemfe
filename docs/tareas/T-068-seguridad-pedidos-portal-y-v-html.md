# T-068 — Seguridad: precios del pedido del portal calculados en el servidor y `v-html` saneado

| | |
|---|---|
| **Módulo** | POR + TRA |
| **Depende de** | ninguna |
| **Reglas** | `proceso.md` §4 (entrada, salida) |
| **Decisiones** | auditoría de seguridad 08-oct-2026 (memoria `project_auditoria_seguridad_2026-10-08`), pendientes verificados contra el código el 27-oct-2026 |
| **Rama** | `fix/T-068-seguridad-portal-v-html` |

## Contexto
Dos puntos de la auditoría del 08-oct siguen abiertos (confirmado en el código el 27-oct):
- `OrderController::store()` (`POST /orders`, checkout del portal, también como invitado) guarda
  `subtotal`, `total` y el `precio` de cada ítem **tal como los manda el navegador**: cualquiera
  puede crear un pedido con el precio que quiera.
- `v-html` sin sanear en `agencia-viajes/paquetes/detalle.vue`, `agencia-viajes/proveedores/detalle.vue`,
  `portal/Producto.vue` y `recursos/manual.vue`: un texto con `<script>`/atributos de evento guardado
  por un usuario se ejecuta en el navegador de otro.

## Alcance
- `OrderController::store()`:
  - precio unitario de cada ítem leído del producto (`products`, el precio de venta que usa el
    portal; confirmar el campo), validando que el producto exista, esté activo y se venda en el
    portal; cantidad entera > 0;
  - `subtotal`, `descuento` (cupón: validarlo en el servidor si existe la lógica; si no existe,
    ignorar el descuento enviado y anotarlo) y `total` calculados en el servidor;
  - si lo que mandó el navegador difiere, se guarda lo del servidor (sin error) y se devuelve en la
    respuesta, para que el portal muestre el total real;
  - validación con FormRequest (campos obligatorios, largos, `items` mínimo 1).
- `v-html`: un helper único `sanearHtml()` (DOMPurify, versión fija) aplicado en los 4 lugares; o
  reemplazar `v-html` por texto si el contenido no necesita HTML. Los editores ricos (Quill) siguen
  guardando HTML: se sanea al mostrar.
- Buscar otros `v-html` (`grep -rn "v-html" src`) y otros endpoints públicos que guarden montos
  enviados por el cliente; anotarlos en "Resultado".

## Fuera de alcance
- Dependencias con advisories, certificados beta en el historial de git, HSTS/CSP en nginx y CORS
  `*`: quedan en "Por analizar" (son de infraestructura o requieren reescribir historia).

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/OrderController.php` + FormRequest nuevo
- `admin-start-kit/src/helpers/sanearHtml.ts` (nuevo) + `package.json` (dompurify)
- las 4 vistas con `v-html`

## Criterios de aceptación
- [ ] **CA1** — Pedido con `precio: 1` para un producto de S/ 50 → el pedido guarda S/ 50 y el total correcto. → test
- [ ] **CA2** — Producto inexistente, inactivo o cantidad 0 → 422. → test
- [ ] **CA3** — Descripción con `<img src=x onerror=alert(1)>` → se muestra sin ejecutar nada. → test de Vitest del helper + prueba funcional
- [ ] **CA4** — El checkout del portal sigue funcionando de punta a punta. → prueba funcional
- [ ] **CA5** — Suite completa en verde; `type-check` y `build` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no hay rutas nuevas (la de pedidos sigue pública a propósito)
- [ ] Aislamiento de tenant: productos del tenant de la petición
- [ ] Validación de entrada / archivos: FormRequest nuevo
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: total calculado en el servidor, un solo punto de redondeo
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: es el objetivo
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan test --filter=Order
cd admin-start-kit && npm run type-check && npm run test:unit
```

---

## Resultado
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
