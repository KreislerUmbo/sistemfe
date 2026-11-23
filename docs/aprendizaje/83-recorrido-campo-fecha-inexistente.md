# Recorrido: el campo de fecha rechaza una fecha que no existe

23-nov-2026, tarea **T-117**. Salió de la venta 41 de umbo (recorrido 80): «31/11/2026» quedó como 01/12/2026.
Archivos:
- `admin-start-kit/src/helpers/fecha.ts` — `parsearFechaEscrita()` (con tests en `fecha.test.ts`)
- `admin-start-kit/src/components/CampoFecha.vue` — `leerFecha()` (el `parseDate` de flatpickr) y el aviso

**Al desplegar:** solo frontend (`npm run build`). Afecta a todos los campos de fecha.

---

## 1. Por qué pasaba

`CampoFecha` usa flatpickr. Lo escrito a mano (formato `d/m/Y`) flatpickr lo convierte con
`new Date(2026, 10, 31)`, y JavaScript no falla: «31 de noviembre» es el día después del 30, o sea **1 de
diciembre**. Nadie se enteraba.

## 2. Cómo se arregló

flatpickr deja reemplazar su lector de fechas (`parseDate`). Lo llama con dos formatos:

| Formato | Qué es | Quién lo lee |
|---|---|---|
| `d/m/Y` | lo que el usuario escribe (al salir del campo o con Enter) | `leerFecha()` → `parsearFechaEscrita()` |
| `Y-m-d` | el `v-model`, `min`, `max` | flatpickr, como siempre (`flatpickr.parseDate`) |

`parsearFechaEscrita()` no usa `new Date()` para validar: revisa día, mes y años bisiestos a mano y
devuelve `YYYY-MM-DD` o `null`.

Si lo escrito no existe, `leerFecha()` pone el aviso y devuelve **la fecha que ya tenía el campo** (o
`undefined`, que flatpickr toma como vacío). flatpickr vuelve a poner esa fecha y dispara `onChange`; la marca
`ultimaRechazada` evita que ese `onChange` borre el aviso. El aviso se va al elegir o escribir una fecha válida.

## 3. Qué ve el usuario

```
[31/12/2026 📅]          ← la fecha anterior, en rojo
31/11/2026 no existe.
```

Texto que no es fecha («mañana»): «no es una fecha: escríbela como dd/mm/aaaa». Se siguen aceptando «-» y «.»
como separador (30-11-2026), como antes.

## 4. Cómo se probó

`fecha.test.ts` (31/11, 30/02, 29/02 no bisiesto, 29/02/2000 y 2028, separadores, texto) y
`e2e/verif-t117-campo-fecha.mjs`: cuota de una venta a crédito (el PATCH lleva la fecha anterior), calendario,
borrar, filtro de Reportes comerciales y el modal «Nueva consulta» (calendario estático).

## 5. Ejercicios

1. Escribe «29/02/2027» y luego «29/02/2028». ¿Qué muestra cada uno y por qué?
2. ¿Por qué `leerFecha()` no toca el formato `Y-m-d`? ¿Qué pasaría con `min` si lo hiciera?
3. Busca en `CampoFecha.vue` por qué el aviso se pone dentro de `leerFecha()` y no en `onChange`.
