# Recorrido: limpieza de código sin uso y voseo en Agencia

09-nov-2026, tarea **T-096**. Origen: auditoría del vertical del 09-nov-2026.

---

## 1. El problema

1. **Métodos del frontend que nadie llama.** Un método de un servicio (`services/admin/*.ts`) que
   ninguna pantalla usa confunde a quien lee: parece que hay una pantalla detrás, y no la hay.
2. **Voseo en los mensajes.** La regla del proyecto es tuteo («puedes», «revisa»), y algunos
   mensajes decían «podés», «revisá».
3. Un `use` que sobraba en `ReservaPasajeroController` desde T-092.

## 2. Archivos

| Archivo | Qué cambió |
|---|---|
| `admin-start-kit/src/services/admin/opcionMayoristaService.ts` | Sin `listarHoteles`, `listarOpcionales`, `listarTours` |
| `.../salidaOperativaService.ts` | Sin `adjuntarItem`, `desengancharItem` |
| `.../destinoAtractivoService.ts` | Sin `listarPorTipo` |
| `.../temporadaService.ts` | Sin `listarOcurrencias` |
| Vistas y componentes de `agencia-viajes`, controllers de `AgenciaViajes`, `LimpiarOperacionAgencia` | Mensajes en tuteo |
| `.../AgenciaViajes/ReservaPasajeroController.php` | Sin `use PasajeroDocumento` |

## 3. El recorrido

### 3.1 Cómo saber si un método está sin uso

```bash
grep -rn "listarHoteles" admin-start-kit/src
```

Si la única coincidencia es la definición del método, nadie lo llama. Pero borrar el método **no
alcanza para decidir sobre la ruta**: hay que mirar la ruta del backend por separado.

- `opciones-mayorista/{id}/hoteles` se registra con `Route::match(['get', 'post'], …)`. El GET no lo
  usa nadie, pero el POST sí (crear hotel). Es la misma ruta y el mismo método del controller, que
  distingue con `$request->isMethod('get')`. Por eso la ruta queda.
- `salidas-operativas/{id}/adjuntar-item` no la llama ninguna pantalla, pero sus tests prueban una
  guarda real (no enganchar un ítem a una salida «fantasma»). Por eso también queda, y la decisión
  está en «Por analizar» del backlog.

Las rutas que quedan siguen exigiendo permiso: lo garantiza `RutasAgenciaPermisosTest` (T-093).

### 3.2 Voseo → tuteo

| Voseo | Tuteo |
|---|---|
| podés, tenés, necesitás | puedes, tienes, necesitas |
| revisá, usá, elegí, contactá | revisa, usa, elige, contacta |
| revisalo, elegilo, hacelo | revísalo, elígelo, hazlo |

Ojo con el `grep`: una lista de verbos nunca cubre todo. En la revisión aparecieron «configurala»,
«cancelás» y «hacelo», que la lista de la tarea no tenía. Además, en pasado «decía»/«escribía» no
son voseo, y en los comentarios no importa.

## 4. Cómo se verificó

- `grep` de cada método retirado: sin llamadas.
- `php artisan test --filter=AgenciaViajes`: pasan (salvo el intermitente conocido
  `Fila13Fase1cFacturacionTest::descuento_del_combo…`).
- `npm run type-check`: 47 errores, los mismos de antes. `npm run build`: verde.

## 5. Lo que queda fuera

- Las rutas sin pantalla y el voseo fuera de Agencia: «Por analizar» del backlog.
- Los comandos de una sola vez: T-099.
