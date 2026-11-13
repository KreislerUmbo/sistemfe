# Recorrido: servicio de tour sin precio de niño, el niño paga como adulto

12-nov-2026, tarea **T-031**. Origen: H7 de la revisión del flujo de cotizaciones
(`docs/requerimientos/observaciones-pruebas-usuario.md`, decisión del usuario del 26-oct).
Archivos:
- `api-sistema-fe/app/Models/AgenciaViajes/ProveedorTarifa.php` (la regla)
- `api-sistema-fe/app/Services/AgenciaViajes/PriceEngineService.php`
- `api-sistema-fe/app/Services/AgenciaViajes/RecalculoPorPersonaService.php`
- `api-sistema-fe/app/Services/AgenciaViajes/ComboExplosionService.php`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/AlternativaItemController.php`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/VentaDirectaController.php`
- `admin-start-kit/src/views/agencia-viajes/proveedores/detalle.vue` (solo un placeholder)

Sin migraciones.

---

## 1. La idea

En una tarifa de proveedor, el campo `precio_venta_nino` vacío se tomaba como **0**: el niño salía
gratis, pero su costo sí se contaba. Resultado: margen negativo y sin aviso. En los tours el mismo
campo vacío ya significaba «igual que el adulto».

Ahora, con una sola regla:

| Tarifa | `precio_venta_nino` vacío | `precio_venta_nino` = 0 |
|---|---|---|
| Servicio de tour (entrada, transporte, actividad: `tipo_habitacion` nulo) | **paga como adulto** | gratis |
| Hotel (`tipo_habitacion` no nulo) | 0, como antes | gratis |

- El **infante** no cambia: vacío = sin costo, siempre.
- Un **0 escrito a propósito** es gratis: la regla mira si el campo está vacío (`null`), no si vale 0.
- **Las cotizaciones ya armadas no cambian:** el precio quedó guardado en la línea. Solo cambian si
  se recalculan porque cambiaron los pasajeros (ver 2.4).

¿Por qué los hoteles no? Porque cobran por habitación y por cama adicional: un niño que duerme en
la cama de sus padres no paga como un adulto.

## 2. El recorrido

### 2.1 La regla, en un solo lugar (`ProveedorTarifa::precioVentaNinoEfectivo()`, ~línea 106)

```php
public function precioVentaNinoEfectivo(): float
{
    if ($this->precio_venta_nino !== null) {
        return (float) $this->precio_venta_nino;
    }

    return $this->tipo_habitacion !== null ? 0.0 : (float) $this->precio_venta_adulto;
}
```

Antes había seis `($tarifa->precio_venta_nino ?? 0)` repartidos por el código. Ahora todos llaman a
este método. Si mañana la regla cambia, se toca acá y nada más.

`tipo_habitacion` es lo que distingue a un hotel. `ProveedorTarifaController` lo exige si el
proveedor es de tipo hotel (slug `alojamiento-hoteles`) y lo deja vacío para los demás tipos, así
que no hay tarifas de hotel sin habitación.

### 2.2 Agregar un servicio suelto (`PriceEngineService::ventaYCostoDeTarifa()`, ~línea 219)

Cuando el vendedor agrega un servicio «por persona» al cotizador (o lo mira en la vista previa de
Venta directa), la venta de la línea es:

```
venta = adultos × precio_adulto + niños × precioVentaNinoEfectivo() + infantes × precio_infante
```

Ejemplo (CA1): adulto S/ 100, niño vacío, 2 adultos + 1 niño → **S/ 300** (antes S/ 200).

### 2.3 Tour clásico desde plantilla (`ComboExplosionService::explotarColeccionItems()`, ~línea 315)

Al cargar un tour desde una plantilla, `ComboExplosionService` «explota» el tour en sus servicios.
Cada uno es una **entrada** (un array con `precio_venta_adulto`, `precio_venta_nino`, etc.). La
entrada ahora lleva el precio de niño **ya resuelto**:

```php
'precio_venta_nino' => $tarifa ? $tarifa->precioVentaNinoEfectivo() : null,
```

Quien usa la entrada multiplica sin aplicar ninguna regla:
- `AlternativaItemController::crearItemClasicoDesdeEntrada()` (~línea 487), al crear la línea en el
  cotizador;
- `VentaDirectaController::totalTourClasico()` (~línea 722), al calcular el total de Venta directa.

Por eso a esos dos se les sacó el `?? 0`: la entrada ya trae el valor correcto.

### 2.4 Recalcular al cambiar pasajeros (`RecalculoPorPersonaService::recalcular()`, ~línea 50)

Si el vendedor cambia los pasajeros de una cotización, las líneas «por persona» de las alternativas
en borrador o enviadas se vuelven a precificar con la tarifa. Ahí entra la regla nueva: un servicio
de tour sin precio de niño pasa de 0 a precio de adulto para ese niño. Es lo único que mueve un
precio ya guardado, y solo cuando el vendedor cambia los pasajeros.

Ojo: este servicio **repite** la fórmula de `ventaYCostoDeTarifa()` en vez de llamarla. Hoy dan lo
mismo; quedó anotado en el backlog para unificarlas.

### 2.5 Venta directa (`VentaDirectaController::desglose()`, ~línea 464)

La vista previa de Venta directa muestra el desglose «2 adultos × 100, 1 niño × …». Usa el mismo
método, para que lo que se ve en la vista previa sea lo que se cobra al crear la venta.

### 2.6 La pantalla de tarifas (`proveedores/detalle.vue`, ~línea 309)

El campo «Precio venta niño» muestra el placeholder **«Vacío = adulto»**, como en tours, solo si el
proveedor no es hotel (`esHotel`). El formulario sigue autocalculando el precio de niño con
costo × factor mientras el vendedor no lo toque (`tocadoNino`): eso no cambió. El placeholder solo
aparece si el vendedor lo borra a propósito.

## 3. Cómo probarlo

```
php artisan test tests/Feature/AgenciaViajes/NinoSinPrecioPagaComoAdultoTest.php
```

| Test | Qué prueba |
|---|---|
| `servicio_de_tour_sin_precio_de_nino_cobra_al_nino_como_adulto` | CA1: 2 adultos + 1 niño, adulto 100, niño vacío → 300 |
| `con_precio_de_nino_propio_no_cambia` | CA2: niño 70 → 270 |
| `nino_con_precio_cero_explicito_sigue_gratis` | un 0 escrito no es «vacío» |
| `infante_sin_precio_sigue_en_cero` | CA3 |
| `tarifa_de_hotel_sin_precio_de_nino_sigue_en_cero` | CA3b: hotel doble, niño vacío → 0 |
| `tour_clasico_desde_plantilla_cobra_al_nino_como_adulto` | CA4 |
| `cambiar_pasajeros_recalcula_con_la_regla_nueva` | CA5 |
| `venta_directa_vista_previa_coincide_con_el_total_guardado` | vista previa = venta creada |

En pantalla: en un proveedor que no sea hotel, crear una tarifa por persona y borrar el precio de
niño (aparece «Vacío = adulto»). En el cotizador, con 2 adultos y 1 niño, agregar ese servicio: la
línea vale 3 × el precio de adulto.

## 4. Si algo falla

- **El niño sigue saliendo en 0 en un servicio de tour:** ¿la tarifa tiene `tipo_habitacion`? Si sí,
  se trata como hotel. ¿El precio de niño es 0 y no vacío? Entonces es gratis a propósito. ¿Es una
  cotización vieja? Su precio quedó guardado; solo cambia si se recalcula por cambio de pasajeros.
- **Venta directa muestra un total distinto al guardado:** buscar en `app/` un
  `precio_venta_nino ?? 0` que haya vuelto a aparecer; todo tiene que pasar por
  `precioVentaNinoEfectivo()`.
- **Pasaje aéreo con niño en 0:** no es de esta tarea. `tarifa_base_nino` es la tarifa de aerolínea
  que tipea el vendedor y vacío sigue siendo 0, a propósito.
