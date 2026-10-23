# Recorrido: quién puede tocar una reserva (permiso de fila)

Auditoría de reserva + facturación, 23-oct-2026. Corrige el punto 1 de la revisión: un vendedor
podía facturar o cambiar la reserva de **otro vendedor** si conocía su número.

---

## 1. Dos preguntas distintas

Cada vez que alguien hace algo con una reserva, el sistema debe responder **dos** preguntas:

| Pregunta | Ejemplo | Quién la responde |
|---|---|---|
| ¿Puede hacer **este tipo** de acción? | "¿puede facturar reservas?" | la **ruta** (`permission:reservas.editar` en `routes/api.php`) |
| ¿Puede hacerla **sobre esta reserva**? | "¿la reserva 15 es suya?" | la **Policy** (`app/Policies/ReservaPolicy.php`) |

Antes solo se hacía la primera en casi todas las acciones. La segunda solo estaba en "ver" y
"cancelar". La lista de reservas sí filtraba (cada vendedor ve las suyas), pero eso es solo la
**lista**: quien escribía a mano `POST /api/reservas/15/facturar` saltaba ese filtro.

> Con los roles que trae el sistema el riesgo era menor (los roles con `reservas.editar` también
> tienen `reservas.ver_todas`), pero los roles se arman a mano desde la pantalla Roles, así que el
> agujero existía igual. Y el vendedor estándar (`reservas.crear`) sí podía, por ejemplo, vincular
> un anticipo a la reserva de otro.

## 2. El recorrido

```
 PANTALLA        reservas/detalle.vue → botón "Facturar"
     ▼
 SERVICIO FE     reservaFacturacionService.facturar()  →  POST /api/reservas/15/facturar
     ▼
 RUTA            routes/api.php  ->middleware('permission:reservas.editar')   ← pregunta 1
     ▼
 CONTROLADOR     ReservaFacturacionController::store()
                   $reserva = Reserva::...->findOrFail($id);
                   $this->authorize('update', $reserva);                       ← pregunta 2 (NUEVO)
     ▼
 POLICY          ReservaPolicy::update() → view()
                   ¿tiene reservas.ver_todas?            → sí: pasa
                   ¿es el vendedor_id de la cotización?  → sí: pasa
                   si no → AuthorizationException
     ▼
 RESPUESTA       bootstrap/app.php convierte la excepción en
                 403 "No tienes acceso a este registro: pertenece a otro usuario…"
```

## 3. Qué hace cada pieza

- **`$this->authorize('update', $reserva)`**: viene de `AuthorizesRequests` (en
  `app/Http/Controllers/Controller.php`). Laravel busca la Policy de la clase `Reserva`, llama a
  su método `update()` y, si devuelve `false`, lanza la excepción. Va **justo después** de cargar
  la reserva y **antes** de cualquier otra regla, para no revelar nada de una reserva ajena (ni
  siquiera "esta reserva está cancelada").
- **`'view'` o `'update'`**: se usa `'view'` en lo que solo lee (`prepararFactura`, ver los
  pasajeros de un servicio) y `'update'` en lo que cambia algo. Hoy las dos dan la misma
  respuesta, pero si un día cambian (p. ej. "el contador puede ver pero no editar"), cada acción ya
  pregunta lo correcto.
- **`ReservaPolicy::view()`**: busca el `vendedor_id` de la cotización padre. La reserva no tiene
  vendedor propio: lo hereda de su cotización.
- **Aceptar una alternativa** (`ReservaController::aceptar`) todavía no tiene reserva, así que
  pregunta a la Policy de la **cotización**: `$this->authorize('update', $alternativa->cotizacion)`.
- **El mensaje en español** (`bootstrap/app.php`): Laravel convierte la excepción en
  `AccessDeniedHttpException` con el texto "This action is unauthorized.". El callback reemplaza
  solo ese texto por defecto.

## 4. Dónde se agregó (21 acciones)

| Controlador | Acciones |
|---|---|
| `ReservaController` | aceptar, sincronizarItems, reprogramar, reasignarMayorista, reasignarHotel, overrideTratamientoTributario, actualizarFacturacionExterna |
| `ReservaFacturacionController` | prepararFactura (`view`), store |
| `ReservaAnticipoController` | store, destroy |
| `ReservaItemController` | update, destroy |
| `ReservaPasajeroController` | store, update, destroy |
| `ReservaItemPasajeroController` | index (`view`), store, checkin, actualizarVuelo, destroy |

No se tocó `buscarCatalogo` (busca en el catálogo de pasajeros de toda la agencia, no en una
reserva). Reprogramar una **salida** llama por dentro a `cancelar` y `reprogramar` de cada reserva,
así que también queda protegido: quien no ve todas no puede mover reservas ajenas.

## 5. Tests

- `tests/Feature/AgenciaViajes/ReservaPolicyAccionesTest.php` (nuevo):
  - otro vendedor intenta preparar factura, facturar, vincular anticipo, agregar o editar pasajero,
    reprogramar y cambiar facturación externa → bloqueado, y nada cambia;
  - otro vendedor intenta aceptar la alternativa → bloqueado;
  - el dueño sin `ver_todas` sigue trabajando su reserva;
  - quien tiene `ver_todas` trabaja la de otro.
- `tests/Feature/AgenciaViajes/Concerns/ConAccesoATodasLasReservas.php` (nuevo): los tests que
  prueban otras reglas (fechas, facturación, salidas) ahora actúan como alguien con
  `reservas.ver_todas`. Antes corrían sin usuario o con un usuario cualquiera, y la Policy nueva
  los bloqueaba: era la prueba de que la protección funciona.

## 6. Ejercicios

1. Abre `ReservaPolicy.php`. ¿Qué devuelve `view()` si la cotización no tiene `vendedor_id`
   (cotizaciones antiguas)? ¿Quién puede trabajar esas reservas?
2. En `ReservaPasajeroController::update()`, mueve mentalmente la línea `authorize` debajo del
   chequeo `estado !== 'activa'`. ¿Qué podría averiguar un vendedor sobre una reserva ajena?
3. En `ReservaPolicyAccionesTest`, agrega una línea a `test_vendedor_no_puede_facturar…` que
   intente `ReservaItemPasajeroController::checkin()`. ¿Qué parámetros necesita?
4. Supón que el negocio pide "el Contador puede ver pero no editar". ¿Qué método de
   `ReservaPolicy` cambiarías y qué acciones dejarían de funcionar para él?
