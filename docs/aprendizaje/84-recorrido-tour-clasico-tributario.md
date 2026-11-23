# Recorrido: el tour clásico desde plantilla nace con su tratamiento tributario

23-nov-2026, tarea **T-029** (hallazgos H18 y H19 de la revisión del flujo de cotizaciones).
Archivos:
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/AlternativaItemController.php` — `desdePlantilla()`
  (ajuste de redondeo) y `crearItemClasicoDesdeEntrada()`
- `api-sistema-fe/app/Models/AgenciaViajes/ConfiguracionAgencia.php` — `completarTratamientoTributario()`
- `api-sistema-fe/app/Console/Commands/DiagnosticarItemsSinIgv.php` — comando nuevo, solo lectura

**Al desplegar:** solo backend. Sin migraciones. Opcional, después del deploy:
`php artisan agencia-viajes:diagnosticar-items-sin-igv` (no escribe nada).

---

## 1. Por qué pasaba

Cotizador → alternativa → **Cargar tour** (modo clásico, el que usan todos los tenants). El tour se
"explota" en ítems: uno por servicio de proveedor, uno por el guía y, si la plantilla tiene
`ajuste_redondeo`, uno más con el ajuste.

**H18.** Esos ítems se creaban **sin** `tip_afe_igv` ni `destino_tributario`. Al facturar, la reserva
busca el tratamiento así (`ReservaFacturacionController::resolverTipAfeIgvItem()`):

```
ítem.tip_afe_igv  →  tarifa del proveedor  →  '10' fijo
```

Los servicios con tarifa se salvaban (la tarifa sí lo tiene). El **guía** y el **ajuste** no tienen
tarifa de proveedor: caían al `'10'` (gravado, nacional). En una agencia de la Amazonía (default
`20`/`amazonia`, D18: exonerado = Amazonía) el guía salía gravado al lado de servicios exonerados.
Un guía agregado **suelto** (`AltaGuia`) no tenía el problema: toma el default de la agencia.

**H19.** El servicio compartido calculaba el costo con `adulto + niño + infante`. El alta normal
(`AltaProveedor`) y el recálculo al cambiar pasajeros (`RecalculoPorPersonaService`, decisión E2)
cuentan `adulto + niño`. Resultado: el margen cambiaba solo con editar los pasajeros.

## 2. Cómo se arregló

Todo pasa por `ConfiguracionAgencia::completarTratamientoTributario($tip, $destino)`: lo que venga
lleno se respeta y lo que falte se completa con el default de la agencia.

| Ítem del tour | Tratamiento con el que nace |
|---|---|
| Servicio con tarifa de proveedor | el de la tarifa (si la tarifa no lo trae: default de la agencia) |
| Guía | default de la agencia (`completar…(null, null)`), igual que `AltaGuia` |
| Ajuste de redondeo | el de los otros ítems del tour **si todos coinciden**; si están mezclados o no hay ninguno, el default |

El ajuste no se reparte entre tratamientos: es un solo ítem de venta con costo 0.

H19: `$costoPorPersona = precio_costo × (adulto + niño)`. La **venta** sigue contando al infante con su
propia tarifa (normalmente 0).

**Lo que no cambió:** el `?? '10'` de la facturación sigue ahí. Quitarlo afecta a lo ya facturado y es
otra decisión. Los ítems viejos tampoco se tocan: para eso está el comando de diagnóstico.

## 3. Qué ve el usuario

Nada nuevo en pantalla. Al cargar un tour en una agencia de la Amazonía, el guía y el ajuste aparecen
**exonerados** como el resto, y la factura sale con una sola afectación. En la ficha del ítem, el costo
de un compartido con infantes baja (2 adultos + 1 infante × S/ 20 = S/ 40, no S/ 60).

## 4. Cómo se probó

`tests/Feature/AgenciaViajes/TributarioTourClasicoDesdePlantillaTest.php` (CA1 a CA6): guía con el
default, servicios con el de su tarifa, ajuste con tratamientos iguales y mezclados, facturación de la
reserva y costo sin infantes. Se ajustaron a propósito dos tests viejos que esperaban 4×20 = 80 y ahora
esperan 3×20 = 60 (`PrecioPorPasajeroTest`, `Fila13Fase1aTourCatalogoTest`).
Diagnóstico en `agencia-demo`: 0 ítems de alternativa y 0 de reserva sin IGV.

## 5. Ejercicios

1. Abre `completarTratamientoTributario()`. ¿Qué devuelve si la tarifa trae `tip_afe_igv='20'` pero
   `destino_tributario` nulo, en una agencia con default `10`/`nacional`?
2. Un tour con dos servicios exonerados y un guía, en una agencia gravada por defecto. ¿Qué tratamiento
   toma el ajuste de redondeo y por qué?
3. Busca en `RecalculoPorPersonaService` la línea que cuenta los pasajeros del costo y compárala con la
   de `crearItemClasicoDesdeEntrada()`.
