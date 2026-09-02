# Fix — C1: el PDF comercial revela la razón social del mayorista al cliente

Pegar este brief completo en una sesión nueva de Claude Code. Rama nueva
(`fix/c1-leak-mayorista-pdf`), un commit, un chat. Es un fix chico y
autocontenido, sin dependencias de otros planes en curso (M1-M5,
12a-12h) — se puede hacer en cualquier momento.

## Contexto (ya investigado, no repetir la búsqueda)

`AlternativaController::resolverNombreItemPdf()` (líneas 601-622 de
`api-sistema-fe/app/Http/Controllers/AgenciaViajes/AlternativaController.php`,
invocado desde `pdf()` en la línea 496) resuelve el nombre a imprimir en
el PDF comercial de la cotización así, para ítems `origen_tipo=mayorista`:

```php
return $item->opcionMayorista?->proveedor?->razon_social ?? 'Paquete mayorista';
```

Esto imprime la razón social legal (dato fiscal SUNAT) del
proveedor-mayorista directo en `resources/views/pdf/agencia-viajes/alternativa.blade.php`
— el documento que efectivamente recibe el cliente para decidir/aceptar
la propuesta. Confirmado que este es el único punto de fuga real: no
hay leak equivalente en `Sale`/`SaleDetail` (usan productos placeholder
genéricos) ni en vouchers (ese módulo no existe construido todavía).

`ReservaController::resolverNombreItem()` (líneas 769/778) es un
resolver hermano pero **no se toca** — se usa solo en el reporte
operativo interno de staff, y ahí el fallback a
`nombre_comercial`/`razon_social` es correcto (el equipo interno
necesita saber con qué mayorista está operando).

Diseño completo en `auditoria-arquitectonica-agencia-viajes.md` §9.3.

## Qué construir

### 1. Migración — columna nueva en `opcion_mayorista`

**Corrección 01-sep-2026:** la versión original de este brief traía
`->after('nombre')` — `opcion_mayorista` **no tiene columna `nombre`**
(confirmado contra `2026_07_28_100100_create_opcion_mayorista_table.php`;
sus columnas son `alternativa_id`, `proveedor_id`,
`salida_mayorista_id`, `moneda`, `incluye`, `notas`,
`vuelo_aerolinea`, `vuelo_detalle`, `estado`). Esa migración habría
fallado tal cual estaba escrita.

```php
Schema::table('opcion_mayorista', function (Blueprint $table) {
    $table->string('descripcion_publica')->nullable()->after('incluye');
});
```

### 2. Corregir `resolverNombreItemPdf()`

Reemplazar el fallback completo — **sin ningún camino hacia el
`Proveedor`, bajo ninguna condición**:

```php
return $item->opcionMayorista?->descripcion_publica ?? 'Paquete mayorista';
```

Si el vendedor no cargó `descripcion_publica`, el cliente ve el
genérico "Paquete mayorista" — nunca un dato del proveedor real. No
agregar un fallback intermedio a `nombre_comercial` "por las dudas": el
punto de este fix es que ese documento no tenga ningún camino de
regreso a datos del proveedor.

### 3. UI — campo para cargar `descripcion_publica`

En el formulario donde se carga/edita una `OpcionMayorista` (modal
"Agregar servicio" → Internacional), agregar un campo de texto
"Descripción para el cliente" — opcional, con placeholder sugiriendo
algo como "Paquete Panamá 6D/5N". No bloquea el guardado si queda
vacío (cae al genérico).

### 4. Backfill de datos existentes

No hace falta backfill perfecto — es un campo nuevo de adopción
gradual. Si es trivial, completar `descripcion_publica` de las
`OpcionMayorista` existentes con el nombre del `AlternativaItem`
asociado cuando exista un valor razonable; si no es trivial o hay
ambigüedad, dejarlo en null y que caiga al genérico "Paquete mayorista"
— es preferible eso a inventar una descripción incorrecta.

## Explícitamente fuera de alcance

- No tocar `ReservaController::resolverNombreItem()` (uso interno,
  correcto tal cual).
- No centralizar los dos resolvers en uno solo en este fix — eso queda
  para cuando M2 (matriz de hoteles) lo aborde, y en ese momento el
  resolver centralizado va a necesitar un parámetro explícito de
  audiencia (`cliente` vs. `interno`) para no reintroducir este mismo
  leak. Este fix es solo el parche puntual del PDF comercial.
- No construir el módulo de vouchers (no existe, no es parte de este
  fix).

## Checklist de verificación

- [ ] Migración corre limpio, sin pérdida de filas.
- [ ] Test de regresión: armar un PDF con un ítem `origen_tipo=mayorista`
      sin `descripcion_publica` cargada → el string de `razon_social` y
      de `nombre_comercial` del proveedor real **no aparece en ningún
      lugar** del PDF renderizado (buscar el string completo en el HTML
      renderizado antes de convertir a PDF, no solo revisar el campo
      `nombre` del array de ítems — el leak real estaba en el valor
      final, no en la estructura).
- [ ] Test de regresión: con `descripcion_publica` cargada, el PDF
      muestra exactamente ese texto.
- [ ] `ReservaController::resolverNombreItem()` sigue funcionando igual
      que antes (test de no-regresión — no se tocó, pero confirmar que
      nada compartido se rompió).
- [ ] Actualizar `plan-hoja-de-ruta-ejecucion.md` con una fila para este
      fix (o anotarlo en el changelog si no amerita fila propia — es un
      fix puntual, no una sesión del plan de multidestino ni de
      matriz de hoteles).
