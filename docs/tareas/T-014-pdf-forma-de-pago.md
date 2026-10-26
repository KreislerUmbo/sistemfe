# T-014 — Forma de pago y n.º de operación en el PDF del comprobante

| | |
|---|---|
| **Módulo** | VEN |
| **Depende de** | T-010 |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → punto 2 |
| **Rama** | `feat/T-014-pdf-forma-de-pago` |

## Contexto
La representación impresa ya muestra la condición (desde F5). Falta cómo pagó el cliente.

## Alcance
- En `pdf/a4.blade.php` y `pdf/ticket80mm.blade.php`, debajo de la condición:
  **"Forma de pago: Yape S/ 300.00 (op. 123456) · Efectivo S/ 200.00"**, leído de
  `sale_payments` (nombre de la forma de pago desde `payment_methods`, no el código).
- Si el pago fue en otra moneda: "Efectivo US$ 1,000.00 (T.C. 3.75)".
- Traspasos (T-012, si ya existe): "Traspaso de F001-23 S/ 500.00".
- Vuelto, si hay: "Vuelto S/ 50.00".
- Sin pagos registrados: no sale la línea.
- Todos los giros (retail, agencia, créditos si usa el mismo PDF).

## Fuera de alcance
- PDF de notas, recibos de Cuentas por Cobrar y de Créditos.

## Archivos probables
- `api-sistema-fe/resources/views/pdf/a4.blade.php`, `ticket80mm.blade.php`
- `api-sistema-fe/app/Http/Controllers/Sale/SaleController.php` (`pdf()`: cargar pagos con su método)

## Criterios de aceptación
- [ ] **CA1** — Venta con Yape (op.) + efectivo: la línea sale en A4 y ticket. → test que renderiza la vista y busca el texto
- [ ] **CA2** — Venta sin pagos: no sale "Forma de pago". → test
- [ ] **CA3** — Pago en otra moneda muestra moneda y T.C. → test
- [ ] **CA4** — Verificación visual de los dos formatos con Ghostscript (ver memoria
  `reference_pdf_visual_verification_ghostscript`): sin cortes ni desbordes en 80 mm.

## Seguridad y correctitud
- [ ] Permisos: no aplica
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada: no aplica
- [ ] Concurrencia: no aplica
- [ ] Dinero: formato con 2 decimales, sin recalcular
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario: no aplica (no cambia datos del comprobante)
- [ ] Salida: `nro_operacion` con `{{ }}` (escapado), nunca `{!! !!}`
- [ ] Migraciones: no aplica

## Cómo verificar
```
php artisan test --filter=Pdf
```

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
