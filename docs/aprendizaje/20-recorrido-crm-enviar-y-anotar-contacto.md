# Recorrido: «Enviar al cliente» y el historial de contactos (CRM C1)

02-nov-2026, tarea **T-050** (unida a main en `80e18fd`). Primera fase del CRM (obs. 13 y 16 de las
pruebas del usuario). Las fases siguientes (T-051 próximas acciones, T-052 motivos, T-060 la ficha)
se construyen encima de lo que se explica acá.

---

## 1. El problema

1. **Una alternativa solo pasaba a «enviada» si salía por correo desde el sistema.** En una agencia
   casi todo se manda por WhatsApp o se entrega en persona: esas alternativas quedaban en borrador
   para siempre, sin fecha de envío ni vencimiento.
2. **No quedaba rastro del contacto con el cliente.** Ni de los envíos por WhatsApp ni de las
   llamadas o visitas. La única lista era «Envíos por correo», y solo de esa alternativa.

## 2. Archivos

| Archivo | Qué es |
|---|---|
| `database/migrations/tenant/core/2026_11_02_100000_create_crm_actividades_table.php` | **nuevo**: tabla `crm_actividades` + copia de los correos ya enviados |
| `app/Models/Client/CrmActividad.php` | **nuevo**: el modelo, sus constantes (tipos, canales) y `scopeDeCotizacion()` |
| `app/Models/AgenciaViajes/Alternativa.php` | `registrarEnvio()`: el único lugar que escribe una actividad de envío |
| `app/Http/Controllers/AgenciaViajes/CotizacionActividadController.php` | **nuevo**: `marcarEnviada()` (WhatsApp / otro medio) e `index()` (historial) |
| `app/Http/Controllers/AgenciaViajes/CotizacionEnvioController.php` | el correo exitoso llama a `registrarEnvio()`; se quitó `historial()` |
| `app/Http/Controllers/Client/CrmActividadController.php` | **nuevo**: `store()`, «Anotar contacto» |
| `routes/api.php` | 3 rutas nuevas (ver 3.x) |
| `admin-start-kit/src/components/AgenciaViajes/EnviarAlternativa.vue` | **nuevo**: el botón «Enviar al cliente» y sus tres opciones |
| `admin-start-kit/src/components/AgenciaViajes/EnviarCorreoCotizacion.vue` | quedó solo como modal del correo |
| `admin-start-kit/src/components/AgenciaViajes/HistorialActividadesCotizacion.vue` | **nuevo**: historial colapsable del editor |
| `admin-start-kit/src/components/Clientes/AnotarContacto.vue` | **nuevo**: modal «Anotar contacto» (compartido) |
| `admin-start-kit/src/services/admin/crmActividadService.ts` | **nuevo**: las llamadas a la API |
| `admin-start-kit/src/views/agencia-viajes/cotizador/editar.vue` | monta `EnviarAlternativa` e `HistorialActividadesCotizacion` |

## 3. El recorrido

### 3.1 La tabla: un contacto = una fila, por cliente

```
crm_actividades
  cliente_id       FK clients          ← el historial vive POR CLIENTE
  referencia_tipo  'cotizacion' | 'reserva' | null
  referencia_id    id de esa cotización o reserva   (SIN FK)
  alternativa_id   qué alternativa se envió          (SIN FK)
  tipo             envio | llamada | whatsapp | visita | nota | estado
  canal            correo | whatsapp | otro          (solo en tipo envio)
  nota, usuario_id, created_at
```

**¿Por qué en `core` y sin FK a la cotización?** El CRM hoy es solo de Agencia (obs. 16), pero un
cliente existe en todos los giros: la tabla va en `core` para que otro giro la use mañana sin
moverla. Las cotizaciones y reservas son tablas del **vertical** Agencia: en un tenant retail no
existen, así que una FK haría fallar la migración ahí. Por eso `referencia_tipo` + `referencia_id`
(una referencia «polimórfica» hecha a mano) y la validación en PHP.

**¿Por qué la migración copia datos?** El historial nuevo reemplaza a la lista «Envíos por correo».
Sin la copia, los correos enviados antes de T-050 desaparecían de la pantalla. El `INSERT … SELECT`
corre solo si existe `cotizacion_envios` (tenants con Agencia) y copia solo los `enviado` de tipo
`cotizacion`; los fallidos no son contactos y se siguen leyendo de su tabla (ver 3.4).

### 3.2 «Enviar al cliente» por WhatsApp u otro medio

```
Cotizador → panel de la alternativa → «Enviar al cliente» (EnviarAlternativa.vue)
   ├─ Por correo ──────► modal de EnviarCorreoCotizacion.vue  (ver 3.3)
   ├─ Por WhatsApp
   │    1. window.open('')  ← EN el clic: después de un await el navegador la bloquea
   │    2. descargarPdf()   ← la función de editar.vue; false = no sigue
   │    3. POST alternativas/{id}/marcar-enviada {canal:'whatsapp'}
   │    4. arma el texto con la fecha de vigencia QUE DEVUELVE EL BACKEND
   │    5. la pestaña va a wa.me/<teléfono>?text=…   (sin teléfono: wa.me sin número)
   └─ Ya la envié por otro medio → nota opcional → POST …/marcar-enviada {canal:'otro', nota}

POST alternativas/{id}/marcar-enviada → CotizacionActividadController::marcarEnviada()
   ├─ autorizarCotizacion('update')       ← CotizacionPolicy: su vendedor o ver_todas
   ├─ valida canal (whatsapp|otro) y nota (máx. 1000)
   ├─ aceptada / descartada / reemplazada → 422
   └─ transacción:
        si borrador → estado 'enviada' + camposAlMarcarEnviada()   (fecha_envio, fecha_vencimiento)
        siempre     → registrarEnvio(canal, nota)                   (una actividad más)
```

**Reenviar no cambia la fecha.** `camposAlMarcarEnviada()` nunca pisa un valor ya guardado, y
además solo se llama en borrador. Así el vencimiento se cuenta desde el **primer** envío.

**¿Por qué el texto se arma después del POST?** Porque la vigencia (`fecha_vencimiento`) la calcula
el backend al marcar enviada. Armado antes, el primer envío iría sin «Vigente hasta…».

**`wa.me` no adjunta archivos.** Por eso se descarga el PDF y el aviso dice «adjúntalo en el chat».

**Si el navegador bloquea la pestaña** (`ventana === null`): un segundo `window.open` después de
los `await` también se bloquearía. Se muestra un aviso con «Abrir WhatsApp»: ese clic es del
vendedor y el navegador lo deja pasar.

### 3.3 El correo también deja su actividad

`CotizacionEnvioController::enviar()` no cambió su lógica: guarda la fila en `cotizacion_envios`
(enviado o fallido) y, si salió, marca enviada en borrador. Lo nuevo es una línea al final del
camino exitoso:

```php
$alternativa->registrarEnvio(CrmActividad::CANAL_CORREO, 'Para: ' . implode(', ', $para));
```

Los tres medios escriben por **el mismo método**, `Alternativa::registrarEnvio()`. Si mañana un
envío tiene que guardar algo más, se cambia en un solo lugar.

### 3.4 El historial de la cotización

```
editar.vue → HistorialActividadesCotizacion.vue
   └─► GET cotizaciones/{id}/actividades → CotizacionActividadController::index()
          ├─ actividades: CrmActividad::deCotizacion($id)        (con usuario)
          ├─ + correos FALLIDOS de cotizacion_envios              (fallido: true, error)
          └─ todo junto, ordenado por fecha descendente
```

Los ids vienen con prefijo (`actividad-12`, `envio-5`) porque salen de dos tablas y la lista de Vue
necesita una `key` única. Las notas se muestran con `{{ }}` (texto), nunca con `v-html`: las
escribe un usuario.

Después de cada envío o contacto, `EnviarAlternativa` emite `registrada` y `editar.vue` llama a
`historialRef.recargar()`, que vuelve a pedir la lista y la deja abierta.

### 3.5 «Anotar contacto»

```
Historial → «Anotar contacto» (AnotarContacto.vue) → tipo + nota
   └─► POST clients/{id}/actividades → CrmActividadController::store()
          ├─ tipo: llamada | whatsapp | visita | nota      (envio y estado los escribe el sistema)
          ├─ nota obligatoria si tipo = nota
          ├─ con referencia (desde la cotización):
          │     cotizaciones.editar + CotizacionPolicy::update + la cotización es de ESE cliente (si no, 422)
          └─ sin referencia: edit_client
```

**¿Por qué no está en la ficha del cliente?** Estuvo, y se sacó en la revisión: lo anotado sin
referencia no se veía en ninguna pantalla hasta la línea de tiempo (T-060). El endpoint sin
referencia queda listo y probado; el botón vuelve a la ficha con T-060.

## 4. Tests

Todos en `tests/Feature/AgenciaViajes/CrmActividadCotizacionTest.php`:

| Test | Criterio |
|---|---|
| `test_otro_medio_en_borrador_la_marca_enviada_con_vencimiento_y_deja_actividad` | CA1 |
| `test_whatsapp_sin_dias_de_vigencia_deja_el_vencimiento_vacio` | CA1 |
| `test_reenviar_deja_otra_actividad_sin_cambiar_la_fecha_de_envio` | CA2 |
| `test_aceptada_descartada_o_reemplazada_no_se_envian` | CA3 |
| `test_valida_el_canal_y_el_largo_de_la_nota` | validación |
| `test_vendedor_sin_ver_todas_no_marca_una_cotizacion_ajena` | Policy |
| `test_correo_exitoso_deja_actividad_envio_correo` | CA4 |
| `test_correo_fallido_no_deja_actividad_pero_aparece_en_el_historial` | CA4 |
| `test_anotar_una_llamada_desde_la_cotizacion_guarda_cliente_y_referencia` | CA5 |
| `test_anotar_con_una_cotizacion_de_otro_cliente_da_422` | seguridad |
| `test_anotar_desde_la_ficha_pide_editar_clientes` | permisos |
| `test_anotar_valida_tipo_y_exige_texto_en_una_nota` | validación |

WhatsApp (CA6) se probó en pantalla con Playwright en agencia-demo. La copia de correos de la
migración se probó a mano con filas de prueba dentro de una transacción revertida.

## 5. Para desplegar

Migración nueva en `core`: `php artisan tenants:migrate-verticales` y revisar su salida
(`deploy.sh` sigue aunque falle). En los tenants con Agencia copia los correos ya enviados.

## 6. Ejercicios

1. En `marcarEnviada()`, ¿qué pasaría si `registrarEnvio()` quedara **fuera** de la transacción y
   fallara? Piensa en qué estado queda la alternativa y qué ve el vendedor en el historial.
2. Busca las tres llamadas a `registrarEnvio()`. Si mañana hay que guardar el teléfono al que se
   mandó el WhatsApp, ¿cuántos archivos tocas? Compáralo con haber escrito `CrmActividad::create()`
   en cada controller.
3. Abre `EnviarAlternativa.vue` y mueve `window.open('', '_blank')` después de `await
   props.descargarPdf()`. Pruébalo en el navegador: ¿qué pasa? Vuelve a dejarlo como estaba.
4. ¿Por qué `crm_actividades.alternativa_id` no tiene FK y `cliente_id` sí? Mira en qué carpeta de
   migraciones está cada tabla.
5. En agencia-demo, anota una llamada desde una cotización y busca la fila en `crm_actividades`.
   ¿Qué valores tienen `referencia_tipo`, `referencia_id`, `alternativa_id` y `canal`?
