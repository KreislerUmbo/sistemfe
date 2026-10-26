# T-021 — PDF de cotización: fotos de todos los hoteles de la alternativa

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | T-020 |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → punto 7 (respuesta 2, "Diseño", sección PDF) |
| **Rama** | `feat/T-021-pdf-fotos-hoteles` |

## Contexto
"Fotos referenciales de los hoteles" (`alternativa.blade.php`) solo imprime hoteles de una
comparación que vengan de `opciones_hotel` (`AlternativaPdfService::hotelesInfoParaPdf()`, por
`opcion_hotel_id`). Los hoteles Proveedor no salen nunca, y un hotel suelto (sin comparación)
tampoco. El usuario quiere fotos **en los dos casos**: para que el cliente vea dónde se aloja y
pida otra opción si no le gusta.

## Alcance
- `AlternativaPdfService`: armar la lista de **hoteles de la alternativa**, sin repetir:
  - ítems de una comparación (`opcionesHoteles()`) y ítems sueltos de alojamiento;
  - identidad del hotel: Proveedor (`proveedor_tarifa.proveedor_servicio.proveedor_id`) u
    `opcion_hotel_id`; un hotel que aparece en dos destinos o en dos filas sale una vez;
  - ítems de grupos **resueltos** y sueltos; para grupos abiertos, todos sus hoteles;
  - en un grupo resuelto, ¿salen también los hoteles no elegidos? Seguir lo que ya hace la tabla
    "Opciones de hoteles" (que los muestra, con la elegida resaltada) y anotarlo en "Resultado".
- Fotos por hotel, desde `opciones_hotel.fotos` o `proveedores.fotos` con el helper compartido
  de T-020: **fachada primero, después hasta 2 de `habitacion`; si faltan, completar con `otra`
  hasta 3**. Recorte con `ImagenRecorteService::recortar4x3ParaPdf()` (como hoy). Check-in/
  check-out de `proveedor.alojamientoDetalle`.
- Hotel sin ninguna foto: no imprime bloque (como hoy). Si ningún hotel tiene fotos, no sale la
  sección.
- Blade: la sección deja de colgar de cada `$grupoHotel` y pasa a una sola sección para todos los
  hoteles. Mantener `page-break-before: always` (u otra solución probada) por el bug de solape con
  DomPDF documentado en la propia plantilla.
- Respetar `configuracion_agencia_pdf` si tiene un interruptor de fotos aplicable (revisar
  `mostrar_fotos_tour` y si hace falta uno para hoteles; no agregar uno nuevo sin consultar).

## Fuera de alcance
- Correo de cotización (usa el mismo PDF: se beneficia solo).
- Fotos en el PDF de mayorista que ya funcionan: no deben cambiar (salvo deduplicar).

## Archivos probables
- `api-sistema-fe/app/Services/AgenciaViajes/AlternativaPdfService.php`
- `api-sistema-fe/resources/views/pdf/agencia-viajes/alternativa.blade.php`
- Tests del PDF de alternativa existentes (buscar `opcionesHoteles` / `hotelesInfo` en `tests/`)

## Criterios de aceptación
- [ ] **CA1** — Alternativa con un hotel Proveedor suelto con fachada + 2 habitación → sale su
  bloque con 3 fotos, fachada primero, y check-in/check-out. → test (datos del view)
- [ ] **CA2** — Comparación de 2 hoteles Proveedor → 2 bloques. → test
- [ ] **CA3** — Hotel con solo 2 fotos `otra` → sale con esas 2. → test
- [ ] **CA4** — Mismo hotel en dos destinos → un solo bloque. → test
- [ ] **CA5** — Hotel de mayorista con fotos → sale igual que antes. → test existente en verde
- [ ] **CA6** — Ningún hotel con fotos → no sale la sección. → test
- [ ] **CA7** — PDF real generado en `agencia-demo` y revisado como imagen con Ghostscript
  (memoria `reference_pdf_visual_verification_ghostscript`): sin solapes, fotos sin deformar,
  la sección no pisa la tabla de opciones.

## Seguridad y correctitud
- [ ] Permisos: no aplica (mismo endpoint de PDF)
- [ ] Aislamiento de tenant: rutas de archivo del tenant actual (`ImagenRecorteService` ya resuelve
  dentro del disco del tenant; nunca URL remota, DomPDF tiene `enable_remote=false`)
- [ ] Validación de entrada: no aplica
- [ ] Concurrencia: no aplica
- [ ] Dinero: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario: no aplica
- [ ] Salida: nombre del hotel con `{{ }}` (escapado)
- [ ] Migraciones: no aplica

## Cómo verificar
```
php artisan test --filter=AlternativaPdf
php artisan test   # suite completa, contra PostgreSQL
```

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
