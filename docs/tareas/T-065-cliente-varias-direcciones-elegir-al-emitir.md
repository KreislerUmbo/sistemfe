# T-065 — Varias direcciones por cliente y elegir la dirección al emitir (H61)

| | |
|---|---|
| **Módulo** | CLI + VEN (todos los giros) |
| **Depende de** | T-064 |
| **Reglas** | ayudas sin fricción (por defecto la fiscal, sin preguntar) |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 18, H61 (aprobada 27-oct-2026) |
| **Rama** | `feat/T-065-cliente-direcciones` |

## Contexto
Un cliente con RUC y sucursales (establecimientos anexos) puede pedir que su comprobante lleve la
dirección de la sucursal que compró. Hoy el cliente tiene una sola dirección. La tabla
`client_addresses` existe y nadie la usa.

## Alcance
- `client_addresses`: revisar las columnas existentes (`nombre`, `direccion`, `departamento`,
  `provincia`, `distrito`, `referencia`, `principal`, `state`) y ajustarlas con una migración
  (largo de `direccion` a 255, `principal` como boolean, `activo`, ubigeo si se usa en la ficha).
  La dirección fiscal sigue en `clients.address`; `client_addresses` guarda **las otras**.
- Ficha y formulario del cliente: sección **"Otras direcciones"** (agregar, editar, desactivar),
  cada una con nombre ("Sucursal Tarapoto").
- **Al emitir** (venta retail, factura de reserva, venta directa con cobro, anticipo, nota):
  selector "Dirección del comprobante" que aparece **solo si el cliente tiene otras
  direcciones**; por defecto la fiscal. Opción "+ Agregar dirección" en el mismo lugar. La
  elegida se guarda en `cliente_direccion` (T-064). La nota hereda la del comprobante que
  modifica, editable.
- Backend: `client_address_id` opcional en cada endpoint de emisión; debe pertenecer al cliente.

## Fuera de alcance
- Dirección en el XML (T-066).
- Direcciones de envío del portal e-commerce ("Mis direcciones", pendiente aparte); no romper lo
  que el portal pueda leer de `client_addresses` (hoy nada).

## Archivos probables
- migración nueva (core) para `client_addresses`
- `api-sistema-fe/app/Models/Client/ClientAddress.php` (nuevo) + controller
- controllers de emisión (`SaleController`, `ReservaFacturacionController`, `AdvanceController`, `NotaElectronicaController`)
- `admin-start-kit/src/views/clients/ficha.vue`, formulario de cliente, `views/sale/register.vue`, `components/AgenciaViajes/FacturarReserva*Modal.vue`, anticipos, notas

## Criterios de aceptación
- [ ] **CA1** — Cliente con 2 sucursales → factura con "Sucursal Tarapoto" → PDF con esa dirección; la siguiente sin elegir → fiscal. → test
- [ ] **CA2** — `client_address_id` de otro cliente → 422. → test
- [ ] **CA3** — Cliente sin otras direcciones → el selector no aparece. → prueba funcional
- [ ] **CA4** — Agregar una dirección desde la pantalla de emisión → queda en el cliente y en el comprobante. → prueba funcional
- [ ] **CA5** — NC de esa factura → propone la misma dirección. → test
- [ ] **CA6** — Prueba funcional en umbo (retail) y agencia-demo; suite completa en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: direcciones con el permiso de editar clientes; emitir sin cambios
- [ ] Aislamiento de tenant: tabla del tenant
- [ ] Validación de entrada / archivos: dirección del mismo cliente y activa; largos máximos
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: sí (core)

## Cómo verificar
```
php artisan test
cd admin-start-kit && npm run type-check
```

---

## Resultado
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
