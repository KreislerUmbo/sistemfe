# T-060 — CRM C5: la ficha del cliente con preferencias del viajero y línea de tiempo

| | |
|---|---|
| **Módulo** | CLI + AGE |
| **Depende de** | T-050, T-051 |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 16, fase C5 y respuestas 3 y 4 (27-oct-2026: preferencias propuestas, se ajustan con el uso; "atendido por última vez por" en vez de responsable fijo) |
| **Rama** | `feat/T-060-crm-ficha-cliente` |

## Contexto
Para atender bien a un cliente que vuelve hay que saber qué le gusta y qué pasó antes. Hoy eso
está repartido entre cotizaciones, reservas y ventas, y las preferencias no se guardan.

## Alcance
- Tabla `cliente_perfil_viajero` (vertical agencia, una fila por cliente): `tipos_viaje` (json,
  etiquetas de un catálogo editable: Naturaleza, Aventura, Cultural, Gastronomía, Familia,
  Luna de miel, Relax…), `presupuesto_por_persona` + `moneda`, `alimentacion`, `movilidad`,
  `hotel_preferido` (texto), `notas`.
- Ficha del cliente (`views/clients/ficha.vue`), solo en giro agencia:
  - Sección **"Preferencias del viajero"** editable.
  - **"Atendido por última vez por"**: el usuario de la actividad o cotización más reciente
    (calculado).
  - **Línea de tiempo**: consultas y cotizaciones (con estado y resultado), reservas, comprobantes
    y pagos, actividades y acciones (hechas y pendientes), en orden de fecha, con enlaces. Paginada
    (20 por vez). Filtro por tipo.
  - Resumen arriba: cotizaciones (ganadas / perdidas), viajes realizados, total comprado, último
    viaje.
- Al crear una cotización para un cliente con perfil, el editor muestra sus preferencias en un
  recuadro plegable (solo lectura).

## Fuera de alcance
- Usar las preferencias para sugerir tours (más adelante).
- Retail (la ficha mantiene sus secciones actuales).

## Archivos probables
- migraciones nuevas (vertical agencia) + seeder del catálogo de tipos de viaje
- `api-sistema-fe/app/Http/Controllers/Client/` (endpoint de línea de tiempo) y controller del perfil
- `admin-start-kit/src/views/clients/ficha.vue`, `views/agencia-viajes/cotizador/editar.vue`

## Criterios de aceptación
- [ ] **CA1** — Guardar preferencias → se ven en la ficha y en el editor de una cotización nueva. → test + prueba funcional
- [ ] **CA2** — Línea de tiempo de un cliente con 2 cotizaciones, 1 reserva facturada y 3 actividades → todo en orden de fecha. → test
- [ ] **CA3** — "Atendido por última vez por" = usuario de lo más reciente. → test
- [ ] **CA4** — Línea de tiempo paginada sin consultas por elemento. → test (contar consultas)
- [ ] **CA5** — Cliente de un tenant retail → la ficha no muestra estas secciones. → prueba funcional
- [ ] **CA6** — Suite completa en verde; `type-check` sin errores nuevos.

## Seguridad y correctitud
- [ ] Permisos / Policy en rutas nuevas: ver clientes; editar preferencias con editar clientes; la línea de tiempo respeta qué cotizaciones/reservas puede ver el usuario
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada / archivos: textos con largo máximo; presupuesto ≥ 0
- [ ] Concurrencia (`lockForUpdate`): no aplica
- [ ] Dinero en centavos: presupuesto decimal(12,2)
- [ ] Fechas con `HoraPeru`: instantes con `formatFechaHora()`
- [ ] Tributario sin fallback silencioso: no aplica
- [ ] Sin `v-html` sin sanitizar: notas como texto
- [ ] Migraciones nuevas + `tenants:migrate-verticales`: sí

## Cómo verificar
```
php artisan test --filter="Cliente|Crm"
cd admin-start-kit && npm run type-check
```

---

## Resultado
- **Commits:**
- **Tests:** (nuevos / suite completa)
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
- 
