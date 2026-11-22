# T-119 — Editar venta: los pagos al contado se guardan con «Guardar cambios», no al instante

| | |
|---|---|
| **Módulo** | VEN |
| **Depende de** | T-118 (rama sin unir: misma pantalla) |
| **Reglas** | Dinero y caja (CLAUDE.md): ningún movimiento de dinero es silencioso |
| **Decisiones** | Usuario, 21-nov-2026: deja sin efecto la del 20-nov (pagos al instante en la edición) |
| **Rama** | `fix/T-119-cobro-edicion-al-guardar` |

## Contexto
Venta 41 de umbo (21-nov): pasada de crédito a contado con un Yape de S/ 120 y luego ese pago borrado con
la papelera. El borrado (`DELETE sale_payments/{id}`) no toca la caja: el ingreso de S/ 120 quedó vivo sin
pago detrás; y dejó la venta en «pago parcial» con 0 pagado (estado invertido en `destroy()`).
`POST sale_payments` tampoco registra el ingreso en caja. Solo `PATCH sales/{id}` sincroniza la caja
(`prepararSincronizacionCaja()` compara movimientos vivos con los pagos del payload). Esos dos endpoints solo
los usaba la edición, por la decisión del 20-nov (T-110) de registrar los pagos al instante.

## Alcance
1. `CobroEdicion.vue`: todo pago se agrega o quita en memoria y lo guarda «Guardar cambios» (también al
   contado). Sin ventana de confirmación al quitar (el cambio no se aplica hasta guardar; el aviso de
   «Quitaste un pago…» de T-116 lo dice).
2. Al contado sin pagos, el formulario trae Efectivo (o el primer método) y el saldo; el pago no existe hasta
   «Agregar pago». A crédito el formulario queda vacío (pago inicial opcional).
3. `SalePaymentController::destroy()`: estado de pago sin nada pagado → 1 (pendiente), no 2.

## Fuera de alcance
- Usar `CobroContado` (vuelto, dos métodos) en la edición: el formulario actual admite pagos parciales de
  cualquier método y `CobroContado` solo los admite en efectivo; cambiarlo afecta a registro.
- Retirar los endpoints `sale_payments` (store/destroy): el panel ya no los usa, pero el portal e-commerce
  vive fuera del repo y no se pudo confirmar que no los use. Quedan anotados (no mueven la caja).
- Corregir los datos de la venta 41 (lo hace el usuario desde la pantalla).

## Dónde tocar
- `admin-start-kit/src/components/Sales/CobroEdicion.vue` → `addPayment()`, `removePayment()`, `sugerirCobro()`
- `admin-start-kit/src/views/sale/edit.vue` → sin `:sale-id`
- `api-sistema-fe/app/Http/Controllers/Sale/SalePaymentController.php` → `destroy()`

## Criterios de aceptación
- [x] **CA1** — Editar una venta al contado: quitar y agregar pagos no hace ninguna petición a `sale_payments`;
  el PATCH lleva los pagos → navegador
- [x] **CA2** — Al contado sin pagos el formulario trae Efectivo y el saldo → navegador
- [x] **CA3** — PATCH sin el pago de una venta al contado corrige el ingreso en caja → test
- [x] **CA4** — `destroy()` del único pago deja la venta en estado 1 → test

## Seguridad y correctitud
- Permisos, tenancy, concurrencia, migraciones, `v-html`: no aplica. Dinero: la caja se mueve solo en el PATCH
  (una transacción, `aplicarSincronizacionCaja`, correcciones con `correction`). Fechas: no aplica.

## Cómo verificar
```
php artisan test --filter=CronogramaEdicionVentaTest
node e2e/verif-t119-cobro-edicion.mjs   (RECORRIDO_USUARIO / RECORRIDO_CLAVE de agencia-demo)
```

---

## Resultado
- **Commits:** ver la rama `fix/T-119-cobro-edicion-al-guardar`.
- **Tests:** nuevos en `CronogramaEdicionVentaTest` (6 en total): `borrar_el_unico_pago…_la_deja_pendiente` (CA4),
  `quitar_el_pago_al_editar…_corrige_la_caja` (CA3). Backend Sale/Cobro/Venta/Credit/Caja/Cash/Advance: 822 en
  verde. Vitest 353, `vite build` en verde; `vue-tsc` sin errores nuevos (el de `edit.vue`, T-101).
- **Navegador** (`e2e/verif-t119-cobro-edicion.mjs`, venta 35 de agencia-demo, PATCH interceptado): abre con
  «Efectivo PEN 280.00»; quitarlo muestra «Quitaste un pago…» y el formulario pasa a EFECTIVO 280; agregarlo
  queda «Sin guardar»; el PATCH lleva `[{EFECTIVO, 280}]`, `debt` 0, `state_payment` 3; **ninguna** petición a
  `sale_payments`. `e2e/verif-t118-una-cuota.mjs` sigue igual.
- **Desviaciones de la tarea y por qué:** el «arreglo chico» propuesto (que `destroy()` corrija la caja) no se
  hizo: el panel ya no llama a `destroy()`, y hacerlo pedía mover la sincronización de caja de `SaleController`
  a un servicio. Solo se corrigió el estado.

## Hallazgos fuera de alcance
- `POST`/`DELETE sale_payments` no mueven la caja. Sin uso en el panel desde T-119: confirmar que el portal
  e-commerce no los usa y retirarlos (o que lancen 410).
