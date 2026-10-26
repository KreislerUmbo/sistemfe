# T-023 — PDF de cotización con la comparación de hoteles abierta + descuento bien calculado

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna (combina bien con T-019 y T-021, pero no las necesita) |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → "Revisión del flujo de cotizaciones", H4 (propuesta aprobada 26-oct-2026) y H10 |
| **Rama** | `feat/T-023-pdf-comparacion-abierta` |

## Contexto
**H4.** El flujo real de la agencia, aprobado por el usuario: arma la comparativa, descarga o
envía el PDF **sin marcar nada**, y cuando el cliente responde marca la que eligió y acepta. Hoy
el PDF se bloquea con una comparación abierta (`validarGruposResueltos()`, guardrail del 19-sep:
el total salía con el hotel más barato sin decirlo). La solución aprobada es decirlo claramente,
no bloquear.

**H10 (bug visible al cliente).** `AlternativaPdfService` calcula `$totalOriginal` sumando
`precio_venta_snapshot * cantidad` de **todos** los ítems: incluye todas las opciones de una
comparación (no solo la elegida o la más barata), **no convierte la moneda** del ítem a la de la
alternativa, y multiplica por `cantidad` también los ítems `por_persona` (cuyo snapshot ya es el
total de la línea). `$descuentoMonto = $totalOriginal - $total` y `hayDescuento = > 0.01`, así
que el PDF puede decir "Incluye descuento aplicado: -S/ 1,200" (o "descuento de temporada") sin
que exista ningún descuento. Confirmado leyendo el código; reproducir con un test antes de corregir.

## Alcance
- **Quitar el bloqueo** del PDF con grupos abiertos (`validarGruposResueltos()` deja de lanzar;
  se puede eliminar). El envío por correo (`CotizacionEnvioController::enviar()`) deja de fallar
  por eso; actualizar su comentario.
- **Bloque "Precio" del PDF:**
  - **Sin grupos abiertos:** igual que hoy.
  - **Un grupo abierto:** un total por cada opción: "Con {Hotel} ({tipo de habitación}):
    {moneda} {total}". Total de cada opción = total de la alternativa calculado como si esa fila
    fuera la elegida (reusar `AlternativaItem::calcularTotalEfectivo()` sobre una copia en
    memoria con esa fila marcada, sin guardar nada). Si la alternativa tiene descuento global,
    aplicarlo igual que se aplicaría al elegirla (revisar `aplicarDescuentoGlobal()`: la fila
    elegida recibe el %); anotar en "Resultado" cómo se resolvió.
  - **Dos o más grupos abiertos:** "Desde {moneda} {mínimo} — con las opciones más económicas;
    el total final depende de los hoteles que elijas".
- **Tabla "Opciones de hoteles":** mientras el grupo está abierto, ninguna fila resaltada (hoy ya
  es así si ninguna es `opcion_elegida`; confirmar).
- **H10:** el monto "antes del descuento" se calcula con la misma regla que el total
  (`calcularTotalEfectivo()` sobre precios de lista **convertidos** a la moneda de la alternativa:
  ítems sin grupo + elegida o mínimo de cada grupo; `cantidad` solo en `tarifa_fija`, como
  `getTotalConvertidoAttribute()`). Un solo cálculo, sin fórmula paralela.
- No cambia: aceptar sigue exigiendo una elegida por grupo (`ReservaController::aceptar()`); con el
  grupo resuelto, el PDF sale como hoy (elegida resaltada, total con esa).

## Fuera de alcance
- Revisión antes de enviar (T-022).
- Fotos de hoteles (T-021).
- Empezar la comparación con 1 habitación (T-019).

## Archivos probables
- `api-sistema-fe/app/Services/AgenciaViajes/AlternativaPdfService.php`
- `api-sistema-fe/resources/views/pdf/agencia-viajes/alternativa.blade.php`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/CotizacionEnvioController.php` (comentario)
- `api-sistema-fe/tests/Feature/AgenciaViajes/SesionM5OpcionesHotelesPdfTest.php` y el test que hoy
  comprueba el bloqueo (buscar "falta elegir una opción"): pasa a comprobar lo nuevo

## Criterios de aceptación
- [ ] **CA1 (H10)** — Alternativa con un grupo resuelto de 2 hoteles y sin descuento → el PDF **no**
  dice "Incluye descuento". → test (falla antes del fix; anotarlo)
- [ ] **CA2 (H10)** — Alternativa en USD con un ítem en PEN y sin descuento → no dice "Incluye
  descuento". → test (falla antes del fix)
- [ ] **CA3 (H10)** — Con descuento global real de 10% → el monto de descuento mostrado es el real. → test
- [ ] **CA4** — Un grupo abierto con Hotel A (S/ 1,000) y Hotel B (S/ 1,200) más otros servicios por
  S/ 500 → el PDF se genera y muestra "Con Hotel A: S/ 1,500" y "Con Hotel B: S/ 1,700"; ninguna
  fila resaltada. → test
- [ ] **CA5** — Dos grupos abiertos → "Desde …" con el mínimo correcto y el texto aclaratorio. → test
- [ ] **CA6** — Grupo resuelto → igual que hoy (elegida resaltada, un solo total). → tests existentes en verde
- [ ] **CA7** — Envío por correo con grupo abierto → se envía con el PDF adjunto. → test
- [ ] **CA8** — Aceptar con grupo abierto → sigue 422. → test existente en verde
- [ ] **CA9** — PDF real en `agencia-demo`, revisado como imagen con Ghostscript (memoria
  `reference_pdf_visual_verification_ghostscript`): el bloque de totales por opción se lee bien y no
  desborda.

## Seguridad y correctitud
- [ ] Permisos: no aplica (mismos endpoints)
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada: no aplica
- [ ] Concurrencia: no aplica (solo lectura; el cálculo por opción no guarda nada)
- [ ] Dinero: un solo cálculo (`calcularTotalEfectivo()`), redondeo a 2 decimales en un punto
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario: no aplica
- [ ] Salida: nombres de hotel con `{{ }}`
- [ ] Migraciones: no aplica

## Cómo verificar
```
php artisan test --filter=Pdf
php artisan test   # suite completa, contra PostgreSQL
```

---

## Resultado
- **Commits:**
- **Tests:**
- **Desviaciones de la tarea y por qué:**

## Hallazgos fuera de alcance
-
