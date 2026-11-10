# Recorrido: buscador de aerolíneas y corrección de nombres guardados

09-nov-2026, tarea **T-016**. Sigue a T-015 (recorrido 51), que armó el catálogo central. Origen:
observación 3 de las pruebas del usuario (`docs/requerimientos/observaciones-pruebas-usuario.md`).

---

## 1. La idea

El catálogo ya existía, pero ninguna pantalla lo usaba. Esta tarea:

1. cambia los campos de texto libre por un **buscador** (`AerolineaSelect.vue`) que guarda el
   **nombre** del catálogo. Las columnas siguen siendo texto: no hay FK entre bases;
2. **corrige lo ya guardado** («Latam», «latam» → «LATAM») con un comando reversible;
3. en el vuelo de agencia de la reserva, el campo **trae cargada la aerolínea cotizada**.

## 2. Archivos

| Archivo | Qué hace |
|---|---|
| `admin-start-kit/src/components/AgenciaViajes/AerolineaSelect.vue` | El buscador (nuevo) |
| `admin-start-kit/src/services/admin/aerolineaService.ts` | `buscar()` y `agregar()` contra `GET/POST aerolineas` |
| `PasajeAereoForm.vue`, `OpcionMayoristaForm.vue`, `paquetes/form.vue`, `paquetes/detalle.vue`, `reservas/detalle.vue` | Usan el buscador |
| `api-sistema-fe/app/Services/AgenciaViajes/NormalizadorAerolineas.php` | Simula, aplica y revierte la corrección |
| `api-sistema-fe/app/Console/Commands/NormalizarAerolineas.php` | El comando `agencia-viajes:normalizar-aerolineas` |
| `database/migrations/tenant/verticals/agencia-viajes/2026_11_09_120000_create_ajustes_aerolinea_table.php` | Registro de cada corrección |
| `AerolineaController.php` (tenant) y `routes/api.php` | Orden por prefijo y permiso `agencia.paquetes` |
| `AlternativaItemController.php` → `validarPasajeAereo()` | Ya no cambia las mayúsculas del catálogo (ver §4) |

## 3. El recorrido

### 3.1 El buscador (`AerolineaSelect.vue`)

Se usa con `v-model` como un input: `<AerolineaSelect v-model="form.aerolinea" />`.

- **Al enfocar** muestra la lista (`GET aerolineas` sin filtro, máximo 20).
- **Al escribir** espera 300 ms sin teclear (`buscarConPausa`) y busca. Si llegan dos respuestas
  fuera de orden, solo vale la última (`ultimaBusqueda`).
- **Elegir** emite `update:modelValue` con el `nombre` del catálogo.
- **«Agregar «X»»** aparece si lo escrito tiene 2 letras o más y no coincide exacto con ninguna
  sugerencia. Hace `POST aerolineas`, que nunca duplica: si ya existe, devuelve la existente.
- **Enter:** elige la coincidencia exacta o la única sugerencia; si no hay ninguna, agrega.
- **Salir sin elegir** vuelve al valor anterior: lo escrito y no elegido no se guarda.
- Un valor viejo que no está en el catálogo se ve tal cual hasta que alguien lo cambie.

La lista flota con `useListaFlotante` (igual que `ProveedorBuscador`) para que una card con
`overflow` no la corte.

### 3.2 La búsqueda prioriza el prefijo

En `AerolineaController::index()`, con texto se reordena así: primero las que **empiezan** con lo
escrito y después las que solo lo contienen («lat» → LATAM antes que una «…atlantic»):

```php
$consulta->reorder()
    ->orderByRaw('case when nombre_normalizado like ? then 0 else 1 end', [self::escaparLike($termino).'%'])
    ->orderBy('nombre');
```

### 3.3 Permiso

`GET/POST aerolineas` aceptan `cotizaciones.editar|reservas.editar|agencia.paquetes`, porque
Paquetes no usa los permisos del cotizador. `RutasAgenciaPermisosTest` fija esa lista exacta.

### 3.4 Vuelo de agencia en la reserva

En `reservas/detalle.vue`, `vueloAgenciaForm()` arma el formulario de cada pasajero × pasaje:

```ts
vuelo_aerolinea_confirmada: existente?.vuelo_aerolinea_confirmada || aerolineaCotizada(it),
```

Si no hay aerolínea confirmada, el campo trae la del pasaje cotizado **como valor**, no solo como
placeholder. Se guarda lo que quede en el campo al pulsar «Guardar vuelo».

> Los viejos `reserva_pasajeros.vuelo_aerolinea_ida/_vuelta` ya no existen: la migración
> `2026_11_04_100000` los pasó a «vuelo propio», que es texto libre del pasajero y no se toca.

### 3.5 El comando de corrección

```
php artisan agencia-viajes:normalizar-aerolineas <tenant> --simular   # solo muestra
php artisan agencia-viajes:normalizar-aerolineas <tenant>             # corrige y registra
php artisan agencia-viajes:normalizar-aerolineas <tenant> --revertir  # deshace
```

`NormalizadorAerolineas` recorre `COLUMNAS` (tabla → columna):
`cotizacion_pasaje_aereo.aerolinea`, `opcion_mayorista.vuelo_aerolinea`,
`paquetes_plantilla.vuelo_aerolinea` y `reserva_item_vuelo_pasajero.vuelo_aerolinea_confirmada`.

Para cada valor:
1. `Aerolinea::normalizar($valor)` (la **misma** función del catálogo, no un `lower()` en SQL);
2. busca ese normalizado en el catálogo central y, si está **unida**, sigue `unida_a_id` hasta la
   vigente (`nombreVigente()`, máximo 10 saltos);
3. si el nombre del catálogo es distinto del guardado, lo anota como cambio. Si no existe en el
   catálogo, lo **lista y no lo toca**.

`aplicar()` hace todo en una transacción y deja cada cambio en `ajustes_aerolinea` (tabla, id,
columna, antes, después). `revertir()` vuelve al valor de `antes` **solo si** el campo sigue
igual a `despues`: si alguien lo editó después, no lo pisa y lo cuenta como `omitidos`.

### 3.6 Panel central

Unir dos aerolíneas no corrige lo guardado en los tenants. La tarjeta de «Unir» muestra el comando
que hay que correr por cada agencia (primero con `--simular`). Se eligió el aviso en vez de
dispararlo en segundo plano.

## 4. Lo que encontró la revisión

En la prueba en pantalla se elegía «LATAM» y se guardaba «Latam». El navegador mandaba bien el
dato, pero `validarPasajeAereo()` lo pasaba por `TextoFormatoService::capitalizarNombrePropio()`
(regla del 29-ago para nombres propios), que también cambia las siglas. Ahora usa
`AerolineaController::formatearNombre()`, la regla del catálogo: solo da formato si el texto viene
**todo en minúsculas**. «latam peru» sigue quedando «Latam Peru», y «LATAM» y «JetSMART» se
respetan.

Lección: si un dato «se guarda distinto» de lo que envía la pantalla, mirar el parámetro real del
`INSERT` en el log de Postgres (`log_statement=mod`). Eso separa de una vez un problema del
frontend de uno del backend.

## 5. Tests

- `NormalizarAerolineasTest`: simular no escribe, aplicar corrige y registra, revertir deja todo
  igual y no pisa una edición posterior, unidas → destino, lo que no coincide se lista y no se
  toca, y las columnas declaradas existen en las migraciones.
- `AerolineaCatalogoTest`: orden por prefijo y permiso de Paquetes.
- `CapitalizacionNombresTest::test_pasaje_aereo_respeta_mayusculas_del_catalogo`.
- `RutasAgenciaPermisosTest`: permiso exacto de `api/aerolineas*`.

## 6. Al desplegar

1. `deploy.sh` corre `tenants:migrate-verticales`, que crea `ajustes_aerolinea` en cada tenant de
   Agencia. Revisar su salida, porque `deploy.sh` sigue aunque falle.
2. Por cada tenant de Agencia (hoy `dakamu`):
   `php artisan agencia-viajes:normalizar-aerolineas dakamu --simular`, revisar la lista y después
   correrlo sin `--simular`. Los valores «sin coincidencia» que sean aerolíneas reales se agregan al
   catálogo desde el panel central y se vuelve a correr.
