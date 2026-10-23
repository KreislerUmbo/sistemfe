# Recorrido: los modales de facturar como componentes

Auditoría de reserva + facturación, punto 5 (mantenimiento), 23-oct-2026. Fila F3 de
`docs/planning/agencia-de-viajes/plan-hoja-de-ruta-ejecucion.md`.

No cambia nada de lo que ve el usuario. Cambia **dónde vive el código**, para que sea más fácil
de leer, de probar y de cambiar sin romper otra cosa.

---

## 1. Backend: "qué está facturado", en un solo lugar

El cálculo "qué servicios y qué pasajeros de esta reserva ya están en una factura" estaba
escrito a mano en **17 lugares**. Ahora vive en el modelo `app/Models/AgenciaViajes/ReservaVenta.php`:

| Función | Para qué |
|---|---|
| `ReservaVenta::itemIdsFacturados($reservaId)` | servicios facturados; consulta la base (sirve dentro de un `lockForUpdate()`) |
| `ReservaVenta::pasajeroIdsFacturados($reservaId)` | pasajeros facturados; consulta la base |
| `ReservaVenta::itemIdsDe($reserva->ventas)` | lo mismo con ventas ya cargadas, sin consultar |
| `ReservaVenta::pasajeroIdsDe($reserva->ventas)` | igual, para pasajeros |

Se dejaron aparte, a propósito, dos cálculos **parecidos pero distintos**:
- el estado de facturación (`calcularEstadoFacturacion`), que solo cuenta las ventas **aceptadas por SUNAT**;
- el reparto del tour (`partesDeTour`), que mira **quién pagó cada tour**.

> Regla para el futuro: si una regla de "facturado" cambia, se cambia en `ReservaVenta`, no en
> cada controlador.

## 2. Frontend: de una pantalla de 3074 líneas a piezas

```
 reservas/detalle.vue (2276 líneas)
   ├── botón "Facturar"             → mostrarModalFacturarSimple = true
   │      <FacturarReservaSimpleModal  :reserva :cabecera :moneda ... :recargar="cargarReserva" />
   ├── botón "Facturación especial" → mostrarModalFacturarEspecial = true
   │      <FacturarReservaEspecialModal :reserva :moneda ... :recargar="cargarReserva" />
   └── @confirmar-tributario="…"    ← el modal avisa; la pantalla abre su modal de confirmación

 components/AgenciaViajes/
   ├── FacturarReservaSimpleModal.vue    toda la reserva pendiente, al cliente de la cotización
   ├── FacturarReservaEspecialModal.vue  por grupo de pasajeros o solo servicios, a un cliente elegido
   └── OpcionesFacturacionCampos.vue     moneda + sucursal + serie (antes copiado en los dos modales)

 composables/agencia-viajes/useOpcionesFacturacion.ts   el estado y las reglas de moneda y sucursal
 helpers/reservaPasajero.ts                             nombrePasajero(), pasajeroIdsParaFacturar()
 helpers/tratamientoTributarioAgencia.ts                etiquetaTipAfeIgv() (Gravado/Exonerado/Inafecto)
```

## 3. Conceptos de Vue que aparecen aquí

- **Props** (`defineProps`): lo que la pantalla le **da** al componente: la reserva, la moneda,
  los pasajeros pendientes. El componente no los cambia: solo los lee.
- **Emits** (`defineEmits`): lo que el componente le **avisa** a la pantalla: `cerrar`,
  `confirmar-tributario`. La pantalla decide qué hacer.
- **Una función como prop** (`:recargar="cargarReserva"`): después de emitir una factura, el
  modal especial necesita la reserva actualizada para saber si quedan pasajeros (si quedan, sigue
  abierto para el siguiente grupo). Por eso recibe la función que recarga y la espera con `await`.
- **`v-if` en el componente**: el modal existe solo mientras está abierto. Al cerrarlo
  desaparece con todo su estado, y al abrirlo nace limpio. Antes había que "resetear" a mano
  cada variable.
- **Composable** (`useOpcionesFacturacion`): una función que agrupa estado (`ref`) y reglas para
  reutilizarlas. Cada modal crea **su propio** juego. Antes los dos modales compartían las mismas
  variables, y una función adivinaba cuál estaba abierto para refrescar la vista previa.
- **`reactive()`**: el composable devuelve un objeto reactivo, por eso se escribe
  `opciones.moneda` (sin `.value`), tanto en la plantilla como en el script.
- **Espera de 500 ms en el tipo de cambio**: el campo cambia con cada tecla. Sin esperar, cada
  número escrito pedía una vista previa nueva.

## 4. Cómo se comprobó

- Backend: 693 tests de Agencia en verde, sin cambios en ellos.
- Frontend: `npm run type-check` con los mismos 46 errores de antes (ninguno en estos archivos).
- En el navegador (agencia-demo, reserva 27, solo lectura, sin emitir nada): se abren los dos
  modales; la vista previa se refresca al cambiar la moneda y al marcar un pasajero; la moneda
  vuelve a la de la cotización al abrir el otro modal; "Confirmar tratamiento tributario" abre el
  modal de la pantalla; 0 errores en la consola; 0 llamadas a facturar.

## 5. Ejercicios

1. En `FacturarReservaEspecialModal.vue`, busca `await props.recargar()`. ¿Qué pasaría si se
   quitara el `await`?
2. ¿Por qué `useOpcionesFacturacion` recibe `toRef(props, 'moneda')` y no `props.moneda`?
   (Pista: si la reserva se recarga, ¿se enteraría el composable?)
3. Agrega mentalmente un tercer modal de facturar. ¿Qué piezas reutilizarías sin copiar nada?
4. En la pantalla, el cliente rápido ahora solo lo usa "Cobrar anticipo". ¿Dónde está el cliente
   rápido de Facturación especial?
