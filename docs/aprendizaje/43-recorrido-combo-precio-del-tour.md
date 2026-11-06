# Recorrido: el combo suma el precio y el costo de cada tour

06-nov-2026, tarea **T-090**. Hallazgo de la prueba de T-087 (combo 15 de agencia-demo,
«Paquete Clasico Tarapoto 3d/2n»).

---

## 1. El problema

Desde la fila 13, un tour tiene **su propio precio** (`precio_venta_final`) y **su propio costo**
(`TourCostoEstimadoService::costoDelTour()`). El cotizador ya cobraba así. Pero la pantalla del
combo seguía con la cuenta de antes: sumaba la **venta de cada tarifa de proveedor** del Incluye
de cada tour.

| | Tours del combo 15 | Lo que mostraba el combo |
|---|---|---|
| Venta | 150 + 150 + 75 = **S/ 375** | **S/ 205** (tarifas del Incluye) |

Y como la validación del **markup mínimo** del combo lee esos mismos totales, también validaba
con números que no son los que se cobran.

## 2. Archivos

| Archivo | Qué cambió |
|---|---|
| `api-sistema-fe/app/Services/AgenciaViajes/ComboExplosionService.php` | Método nuevo `totalesTourEnCombo()`; `totalesCombo()` lo usa; avisos nuevos |
| `admin-start-kit/src/views/agencia-viajes/paquetes/detalle.vue` | Dos alertas nuevas (vista previa y panel lateral); texto «sin Incluye cargado» |
| `admin-start-kit/src/types/agencia-viajes.ts` | `ComboPrecioCalculado` con los dos arreglos nuevos |
| `tests/Feature/AgenciaViajes/PaqueteComboTest.php` | 4 tests nuevos (CA1–CA4) |
| `ComboItemsSueltosTest.php`, `AjusteRedondeoTest.php` | Fixtures: el tour toma precio y costo de su tarifa |

Tablas: ninguna. Migraciones: ninguna.

## 3. El recorrido

### 3.1 Pantalla → API

Paquetes → abrir un combo → pestaña Datos. La vista previa (costo total, venta bruta, venta
neta, markup) **no calcula nada en el navegador**: lee `combo.precio_calculado`, que llega de
`PaquetePlantillaController::show()` → `ComboExplosionService::totalesCombo($paquete)`.

### 3.2 `totalesCombo()`: de dónde sale cada número

```
por cada tour ACTIVO del combo  → totalesTourEnCombo($tour)
    venta = precio_venta_final              (null → 0 y aviso «sin precio»)
    costo = costoDelTour($tour)['costo']    (null → 0 y aviso «sin costo»)
ítems sueltos del combo         → siguen sumando su tarifa (igual que antes)
todo eso                        → PriceEngineService::calcularCombo()  (descuento, markup, redondeo)
```

**Por qué un método nuevo y no cambiar `totalesTour()`:** `totalesTour()` (suma de tarifas del
Incluye) también lo usan `ConversionToursService` y `DiagnosticoToursService`. Cambiarlo los
habría movido a ellos también, sin que nadie lo pidiera.

### 3.3 El costo: manda el Incluye

`costoDelTour()` decide así:

1. Si **todos** los servicios del Incluye tienen `costo_estimado_persona` → la suma del Incluye.
2. Si no, el costo escrito a mano en el tour (`paquete_plantillas.costo_estimado_persona`).
3. Si tampoco hay → `null` (el combo lo cuenta 0 y avisa).

Por eso en el combo 15 el costo da **158** y no 148: Alto Mayo tiene un costo a mano de 70, pero
su Incluye completo suma 93, y el Incluye manda. Lamas no tiene costo en sus servicios ni a mano
→ suma 0 y sale en el aviso. Markup: (375 − 158) ÷ 158 = 137,34 %.

### 3.4 Los avisos

| Arreglo | Cuándo | Qué pasa con el total |
|---|---|---|
| `componentes_sin_precio` | Tour sin `precio_venta_final` | No suma venta |
| `componentes_sin_costo` | `costoDelTour()` da `null` | Suma venta con costo 0: el markup sale inflado |
| `componentes_sin_incluye` (ya existía) | Incluye vacío | Ya no afecta el total; avisa que no aparecerá en cotizaciones |

### 3.5 Markup mínimo al guardar

`PaquetePlantillaController::update()` (~357) y `PaquetePlantillaItemController` (~143) no se
tocaron: leen `totalesCombo()`, así que ahora validan con 375/148 y no con las tarifas.
Lo prueba `test_markup_minimo_se_valida_con_precio_y_costo_de_los_tours` (mínimo 200 % → 422).

## 4. Lo que queda fuera

- **Temporada:** la vista previa usa el precio de lista; el cotizador sí aplica la temporada de la fecha.
- **Moneda:** la vista previa muestra «S/» y suma precios sin mirar la moneda de cada tour.
- **Modo anterior del cotizador** (`modo_tours` apagado): sigue cobrando las tarifas del Incluye,
  así que ahí la vista previa del combo no coincide con lo cobrado.
