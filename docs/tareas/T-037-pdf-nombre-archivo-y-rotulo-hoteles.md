# T-037 — PDF de cotización: nombre de archivo con código y cliente; rótulo correcto de la tabla de hoteles (R8, H20)

| | |
|---|---|
| **Módulo** | AGE |
| **Depende de** | ninguna (toca el mismo PDF que T-021 y T-023: coordinar si se construyen a la vez) |
| **Reglas** | — |
| **Decisiones** | `docs/requerimientos/observaciones-pruebas-usuario.md` → obs. 9, R8 (aprobada 26-oct-2026); H20 |
| **Rama** | `fix/T-037-pdf-nombre-y-rotulo` |

## Contexto
**R8.** El PDF se descarga como "cotizacion-Alternativa a.pdf": sin código ni cliente; con varias
cotizaciones en la carpeta de descargas no se sabe cuál es cuál. **H20.** La tabla "Opciones de
hoteles" dice "tarifa por persona", pero en Local/Nacional muestra el total por habitación y noches
(PEN 1,020 = 340 × 3). Confirmado en el PDF real.

## Alcance
- Nombre del archivo (descarga y adjunto del correo, un solo lugar en `AlternativaPdfService`):
  `{codigo} - {cliente corto} - {alternativa}.pdf`, por ejemplo
  `CDKM-1026-0000015 - Rivera - Alternativa A.pdf`, sin caracteres no válidos para archivos. El
  frontend (`descargarPdfAlternativa()`) usa el nombre que manda el servidor
  (`Content-Disposition`) en vez de armarlo.
- Rótulo de la tabla de hoteles según de dónde vienen los precios:
  - mayorista (paquete por persona): "precio del paquete por persona";
  - Local/Nacional (habitación): "precio por habitación, {N} noches".
  Si una tabla mezcla los dos (no debería), rotular por fila.

## Fuera de alcance
- Fotos (T-021), totales por opción (T-023).

## Archivos probables
- `api-sistema-fe/app/Services/AgenciaViajes/AlternativaPdfService.php`
- `api-sistema-fe/resources/views/pdf/agencia-viajes/alternativa.blade.php`
- `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue`

## Criterios de aceptación
- [ ] **CA1** — El PDF descargado y el adjunto del correo se llaman con código, cliente y alternativa. → test + prueba funcional
- [ ] **CA2** — Hotel Local/Nacional: el rótulo dice "por habitación, 3 noches". → test (texto del PDF)
- [ ] **CA3** — Mayorista: "por persona". → test
- [ ] **CA4** — PDF real revisado como imagen con Ghostscript.

## Seguridad y correctitud
- [ ] Permisos: no aplica
- [ ] Aislamiento de tenant: no aplica
- [ ] Validación de entrada: nombre de archivo saneado (sin `/ \ : * ? " < > |`)
- [ ] Concurrencia: no aplica
- [ ] Dinero: no aplica
- [ ] Fechas con `HoraPeru`: no aplica
- [ ] Tributario: no aplica
- [ ] Salida: textos con `{{ }}`
- [ ] Migraciones: no aplica

## Cómo verificar
```
php artisan test --filter=Pdf
```

---

## Resultado
- **Commits:** rama `fix/T-037-pdf-nombre-y-rotulo`, un commit (ver `git log`).
- **Tests:** 10 nuevos en `SesionM5OpcionesHotelesPdfTest` (nombre con código/cliente/alternativa,
  saneado de caracteres, rótulo Local/Nacional con noches y singular, mayorista, mezcla por fila).
  Filtro `Pdf|EnvioCorreoCotizacion|SesionM5`: 100 verdes. `vue-tsc --noEmit` sin errores.
  Suite completa: 2121 verdes, 1 fallo (intermitente conocido `Fila13Fase1cFacturacionTest::descuento del combo`). CA4: PDF real generado y revisado con Ghostscript:
  "OPCIONES DE HOTELES — precio por habitación, 3 noches", fila PEN 1,020.00 (340 × 3).
- **Desviaciones de la tarea y por qué:**
  - "Cliente corto" no estaba definido: persona = primer apellido (`surname`), sin apellido la última
    palabra de `full_name`; empresa = `name_comerc` o razón social. Cada parte se acota a 40 caracteres.
  - Se agregó `Content-Disposition` a `exposed_headers` en `config/cors.php`: sin eso el navegador
    no deja leer el nombre del servidor cuando frontend y API están en orígenes distintos (producción).
  - CA2/CA3: además de `opcionesHoteles()['rotulo']`, 3 tests renderizan el HTML real de la
    plantilla con los datos que arma `construir()` (capturados con `View::composer`) y verifican el
    rótulo del título y el rótulo por fila en la mezcla. No se extrae texto del PDF (DomPDF lo comprime).
  - Corte a 40 caracteres: se hace antes del `trim`, para no dejar un espacio antes de " - " (test propio).
  - `descargarArchivo()` también lo usa "condiciones generales": ahora respeta el nombre del servidor
    si lo manda (sin cambio visible si no).

## Hallazgos fuera de alcance
- En la prueba, "Cliente" del PDF y el nombre del archivo dependen de `surname`; clientes antiguos sin
  `surname` usan la última palabra de `full_name`, que puede ser un nombre de pila.
