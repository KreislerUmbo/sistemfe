# T-066 — Enviar la dirección del cliente en el XML del comprobante

| | |
|---|---|
| **Módulo** | VEN (todos los giros) |
| **Depende de** | T-064; **respuesta del contador** (obs. 18) |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 18, punto 4 (bloqueada hasta confirmar con el contador) |
| **Rama** | `feat/T-066-direccion-cliente-xml` |

## Contexto
El XML a SUNAT no lleva dirección del cliente (`GreenterService::getCliente()`). Greenter permite
enviarla (`Client::setAddress()`). Algunas empresas exigen la dirección de su sucursal en el
comprobante; hay que confirmar con el contador si conviene enviarla en el XML.

## Alcance
- `GreenterService::getCliente()`: si el comprobante tiene `cliente_direccion` (T-064), enviar
  `Address` con la dirección (y ubigeo si se tiene). Sin dirección, igual que hoy.
- Lo mismo para notas.
- Ajustar según la respuesta del contador (siempre, solo facturas, solo si el cliente lo pide).

## Fuera de alcance
- Cambiar el PDF (ya lo hace T-064/T-065).

## Archivos probables
- `api-sistema-fe/app/Http/Controllers/Greenter/GreenterService.php`

## Criterios de aceptación
- [ ] **CA1** — Factura con dirección → el XML trae la dirección del adquirente. → test (leer el XML generado)
- [ ] **CA2** — Sin dirección → XML igual que hoy. → test
- [ ] **CA3** — Aceptada por SUNAT Beta. → prueba en sandbox
- [ ] **CA4** — Suite completa en verde.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: no aplica
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: no aplica
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario sin fallback silencioso: validado contra SUNAT Beta
- [ ] Sin `v-html` sin sanitizar: no aplica
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: no aplica

## Cómo verificar
```
php artisan test --filter=Greenter
```

---

## Resultado
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
