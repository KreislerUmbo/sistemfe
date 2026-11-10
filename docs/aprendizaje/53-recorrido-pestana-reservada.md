# Recorrido: la alternativa reservada se distingue en la pestaña

09-nov-2026, tarea **T-080**. Origen: observación del usuario del 02-nov con CDKM-1126-0000003.
Solo frontend: `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue`.

---

## 1. La idea

En una cotización ya reservada, el ✓ de la pestaña casi no se veía porque tomaba el color de la
pestaña: blanco sobre azul si estaba activa y gris sobre gris claro si no. Además, al entrar se
abría siempre la primera alternativa, aunque la reservada fuera otra.

Ahora:

1. la pestaña lleva un **círculo en la esquina**: verde con ✓ si está reservada (`aceptada`) y
   gris con un ícono de historial si fue reemplazada por una modificación. Las descartadas no cambian;
2. la reservada tiene además un **borde verde de 2px**;
3. al abrir la cotización, la pestaña inicial es la **reservada**; si no hay, la **reemplazada**;
   si no hay ninguna, la primera.

## 2. El recorrido

### 2.1 El círculo de la esquina (pestañas, ~línea 198)

Es el mismo patrón que el contador de notas de crédito de Ventas (`sale/index.vue`):

```html
<span class="alt-pill position-relative badge ...">
    <span v-if="alt.estado === 'aceptada'"
          class="badge rounded-pill bg-success position-absolute top-0 start-100 translate-middle alt-pill-marca">
        <i class="fas fa-check"></i>
    </span>
```

- `position-relative` en la pestaña hace que el círculo se ubique respecto de ella.
- `top-0 start-100 translate-middle` lo centra justo sobre la esquina superior derecha.
- Como el círculo tiene su propio fondo (`bg-success`/`bg-secondary`), ya no toma el color de la
  pestaña: esa era la causa del problema.
- El texto «Reservada · código», con el enlace a la reserva, se mantiene. Se quitó el ✓ que estaba
  dentro del texto porque el círculo lo reemplaza.
- El tacho de borrar no aparece en alternativas aceptadas o reemplazadas, así que no comparte esquina
  con el círculo.

### 2.2 El borde (`<style scoped>`, junto a `.alt-pill-delete`)

```css
.alt-pill-reservada { border: 2px solid var(--bs-success, #198754) !important; }
.alt-pill-marca { width: 1.4rem; height: 1.4rem; padding: 0; ... }
```

Lleva `!important` porque la pestaña inactiva ya tiene la clase `border` de Bootstrap. En este tema,
`success` se parece al `primary`, así que sobre la pestaña activa el borde casi no se nota. Lo que
distingue a la reservada es el círculo.

### 2.3 La pestaña inicial (`cargarCotizacion()`, ~línea 1948)

```ts
if (!alternativaActivaId.value && cotizacion.value.alternativas?.length) {
    const alts = cotizacion.value.alternativas;
    const inicial = alts.find(a => a.estado === 'aceptada')
        ?? alts.find(a => a.estado === 'reemplazada')
        ?? alts[0];
    alternativaActivaId.value = inicial.id;
}
```

`cargarCotizacion()` se llama muchas veces: al entrar y después de editar pasajeros, agregar ítems o
cambiar la moneda. La guarda `!alternativaActivaId.value` hace que la elección solo corra **la primera
vez**: en los recargos siguientes la pestaña elegida por el usuario se mantiene.

El único lugar que vuelve `alternativaActivaId` a `null` es **eliminar una alternativa**. Por eso,
después de eliminar, se abre la reservada (antes se abría la primera).

## 3. Cómo se probó

Sin tests automáticos, porque el cambio es visual. Se probó con Playwright en agencia-demo:

- CDKM-1126-0000010 (la reservada es la segunda, «Avianca»): círculo verde y borde, con la pestaña
  activa e inactiva, en tema claro y oscuro; abre en la reservada.
- CDKM-1126-0000003: la reemplazada lleva el círculo gris y abre en ella.
- Recargos y cotización sin reservas: comprobados leyendo el código (§2.3).

## 4. Al desplegar

Nada especial: solo cambia el frontend, sin migraciones.
