# Recorrido: salidas vencidas y «Marcar realizada»

25-nov-2026, tarea **T-131**. En el recorrido de la fase 2 (23-nov) las 7 salidas de agencia-demo eran de
noviembre, ya pasadas, y seguían «Programada». Compras va a comparar el costo real contra salidas
**terminadas**: si nada las cierra, ese filtro queda vacío.

Archivos:
- `api-sistema-fe/app/Models/AgenciaViajes/SalidaOperativa.php` — scope `vencidas()`
- `api-sistema-fe/app/Http/Controllers/AgenciaViajes/SalidaOperativaController.php` — `index()` y `cambiarEstado()`
- `admin-start-kit/src/views/agencia-viajes/salidas/index.vue` — tablero y detalle
- `api-sistema-fe/tests/Feature/AgenciaViajes/Fila13Fase2aSalidasTest.php`

**Al desplegar:** backend y frontend; sin migraciones.

---

## 1. La decisión: avisar, no cambiar solo

Había tres caminos: un botón manual, un cambio automático al día siguiente, o un aviso más el botón. Se
eligió el tercero: una salida puede haberse cancelado sin que nadie lo registre, y si el sistema la marca
«Realizada» sola, la rentabilidad mentiría. El sistema **señala**; la persona **decide**.

## 2. Qué es «vencida»

No es un estado nuevo en la base: se calcula. Una salida está vencida si sigue en curso (Programada o
Confirmada) y su fecha es **anterior a hoy en Perú**:

```php
public function scopeVencidas($query)
{
    return $query->enCurso()->where('fecha', '<', HoraPeru::hoyTexto());
}
```

`HoraPeru::hoyTexto()` y no `now()`: de 19:00 a 24:00 en Lima ya es mañana en UTC, y una salida de hoy
se vería vencida antes de tiempo. La salida de hoy **no** está vencida (`<`, no `<=`). En el frontend la
misma regla usa `hoyPeru()`.

## 3. Pantalla → API → tabla

| Acción en el tablero | Llamada | Qué cambia |
|---|---|---|
| Abrir el tablero | `GET salidas-operativas?fecha_desde=hoy&fecha_hasta=hoy+7` | nada; la respuesta trae además `vencidas` (el total, sin importar el filtro) |
| «Ver vencidas» | `GET salidas-operativas?vencidas=1` | nada; lista solo las vencidas |
| Bandera «Marcar realizada» | `PUT salidas-operativas/{id}/estado` con `estado=realizada` | `salidas_operativas.estado` = `realizada`. No toca caja ni comprobantes |
| «Volver al tablero» / «Limpiar» | `GET` con el filtro normal | nada |

## 4. Por qué el conteo viene del servidor

El tablero arranca en «hoy», así que las vencidas (todas anteriores) nunca están en la página cargada.
El frontend no puede contarlas con lo que tiene: por eso `index()` hace un `count()` aparte con el scope,
**una** consulta por pedido. «Ver vencidas» reemplaza las fechas y el estado (quedan deshabilitados) y
`filtros()` arma los mismos parámetros para el listado y para el recargado tras editar una salida.

## 5. El cambio de estado

`cambiarEstado()` tiene una tabla de pasos permitidos. Antes, de Programada solo se podía ir a
Confirmada; ahora también a Realizada, para cerrar de una vez una salida vencida que nunca se confirmó.
La regla que ya existía sigue cuidando el otro lado: **Realizada con fecha futura da 422**. Una Cancelada
no puede pasar a Realizada.

## 6. Cómo se probó

- Tests: programada vencida → realizada sin movimientos de caja; cancelada → 422; el listado cuenta y
  filtra las vencidas sin incluir las de hoy ni las realizadas.
- Un test anterior (`T044AvisoGuiaChoferTest`) contaba consultas con estado para asegurar que el aviso de
  guía repetido no consulte por fila; el `count(*)` nuevo entraba en su cuenta y se excluyó.
- Playwright en agencia-demo: aviso con 7, «Ver vencidas» con las 7, «Marcar realizada» en una (quedan 6,
  sin caja), «Volver al tablero».
