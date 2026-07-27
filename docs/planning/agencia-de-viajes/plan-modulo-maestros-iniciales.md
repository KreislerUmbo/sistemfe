# Sub-plan — Datos Maestros Iniciales (vertical Agencia de Viajes)

> Parte de: `plan-general-vertical-agencia-viajes.md`
> Este documento NO rediseña nada — cruza lo ya definido en
> `plan-modulo-proveedores.md` y `plan-modulo-cotizaciones-reservas.md`
> para responder una pregunta que ninguno de los dos contesta solo:
> **¿en qué orden se carga todo, y qué formulario necesita cada cosa antes
> de poder cotizar?**
> Última actualización: 24-jul-2026 — v0.1 (primera versión)

---

## 0. Cómo usar este documento (si estás retomando esto en un chat nuevo)

Antes de tocar nada de implementación (migraciones, CRUDs), lee en este
orden:

1. Este documento completo — es el mapa de dependencias y el punto de
   partida real para empezar a construir
2. `plan-modulo-proveedores.md` — modelo completo de proveedores/tarifas
3. `plan-modulo-cotizaciones-reservas.md` — modelo completo de
   cotizaciones/reservas/itinerarios
4. `plan-modulo-tours-catalogo.md` — modelo de destinos/servicios/tours,
   resuelve el bloqueante que tenía este documento (ver historial)

## 1. Objetivo

Antes de que un vendedor pueda armar una cotización, tiene que existir
—cargado por alguien, en algún formulario— todo lo que la cotización va a
referenciar: tipos de proveedor, proveedores mismos, sus tarifas,
destinos, temporadas, configuración de la agencia. Cada uno de esos datos
tiene una **dependencia** de otro (no puedes crear una tarifa sin que
exista el proveedor; no puedes crear el proveedor-servicio sin que exista
el destino). Este documento ordena ese árbol y dice, para cada pieza, de
dónde sale su diseño y qué falta para poder construirla.

## 2. Por qué hacía falta este documento

`plan-modulo-cotizaciones-reservas.md` ya tenía anotado el hueco, pero
solo como una línea suelta en la sección de pendientes:

> *"Falta detallar los formularios CRUD predecesores (proveedores,
> tarifas, tipo de cambio) antes de tocar el flujo de cotización
> propiamente."*

Nunca se desarrolló. Cada módulo (Proveedores, Cotizaciones) se diseñó
resolviendo su propio modelo de datos, pero ninguno documentó el **orden
de carga entre ambos** ni detectó que uno depende de una pieza que
pertenece a un tercer módulo, todavía sin desarrollar (ver sección 5).

## 3. Árbol de dependencias completo

```
NIVEL 0 — Central, se siembra UNA SOLA VEZ para todo el sistema
(no por tenant — ver arquitectura-multitenant-backend.md, CentralConnection)
├── proveedor_tipos        (Hotel, Transporte, Mayorista... + campo `giro`)
└── temporadas              (Temporada Alta, Fiestas Patrias... + campo `giro`)

NIVEL 1 — Por tenant, al provisionar (automático, sin intervención manual)
├── proveedor_tipos_config  (copia del catálogo central, todo habilitado=true)
└── temporada_ocurrencias   (fechas del año en curso — ⚠️ ver pendiente 6.1)

NIVEL 2 — Por tenant, carga manual, SIN dependencias entre sí
├── destinos_atractivos     (árbol de 3 niveles: zona/lugar/atractivo —
│                              resuelto 24-jul-2026, ver
│                              plan-modulo-tours-catalogo.md)
├── servicios                (catálogo de tipos de servicio: traslado,
│                              entrada, hospedaje... — resuelto 24-jul-2026)
├── configuracion_agencia   (edad_max_infante, formato_descuento_pdf,
│                              mostrar_descuento_como_linea, etc.)
└── guias                   (nombre, documento, teléfono, activo — sin
                               dependencias, tabla simple)

NIVEL 3 — Depende del nivel 2
├── destino_servicio        (destino_id + servicio_id — resuelto 24-jul-2026)
└── proveedores              (elige su `tipo_id` del catálogo del nivel 1)

NIVEL 4 — Depende del nivel 3
└── proveedor_servicios      (proveedor_id + destino_servicio_id)

NIVEL 5 — Depende del nivel 4
└── proveedor_tarifas        (proveedor_servicio_id + temporada_id +
                                costo/margen/piso/moneda)

NIVEL 6 — Recién acá se puede cotizar
└── cotizaciones → alternativas → alternativa_items
```

**Lectura del árbol:** todo lo que está en un nivel necesita que TODO lo
que está en los niveles anteriores ya exista. No hay forma de saltarse un
nivel — es la razón por la que un tenant nuevo no puede cotizar el primer
día, aunque ya tenga el código instalado.

## 4. Detalle por entidad — qué formulario necesita, quién lo carga, de dónde sale el diseño

| Entidad | Nivel | Quién lo carga | Frecuencia de carga | Documento fuente |
|---|---|---|---|---|
| `proveedor_tipos` | 0 | Nadie en el tenant — es catálogo central, se siembra desde el equipo de desarrollo/superadmin | Una vez, se actualiza rara vez (nuevo tipo para todo el rubro) | `plan-modulo-proveedores.md` §2.6 |
| `temporadas` | 0 | Igual que arriba — central | Una vez, rara vez | `plan-modulo-proveedores.md` §2.6 |
| `proveedor_tipos_config` | 1 | Automático al provisionar; admin de la agencia puede apagar tipos después | Automático + ajustes ocasionales | `plan-modulo-proveedores.md` §2.6 |
| `temporada_ocurrencias` | 1 | ⚠️ Sin definir si es automático o manual — ver 6.1 | Anual (una vez por año calendario) | `plan-modulo-proveedores.md` §2.6 |
| `destinos_atractivos` | 2 | Admin/supervisor de la agencia | Ocasional (cuando se agrega un destino nuevo) | `plan-modulo-tours-catalogo.md` |
| `servicios` | 2 | Admin/supervisor | Ocasional | `plan-modulo-tours-catalogo.md` |
| `configuracion_agencia` | 2 | Admin de la agencia, una pantalla de configuración general | Una vez al inicio, ajustes ocasionales | `plan-modulo-cotizaciones-reservas.md` (mencionada en varias secciones, sin CRUD propio definido) |
| `guias` | 2 | Admin/supervisor | Ocasional | `plan-modulo-cotizaciones-reservas.md` §5.3 |
| `destino_servicio` | 3 | Admin/supervisor | Ocasional | `plan-modulo-tours-catalogo.md` |
| `proveedores` | 3 | Admin/supervisor (mismo permiso que carga tarifas, §2.6 de Proveedores) | Frecuente al inicio, luego ocasional | `plan-modulo-proveedores.md` |
| `proveedor_servicios` | 4 | Admin/supervisor, al dar de alta un proveedor elige a qué destinos/servicios aplica | Junto con el alta del proveedor | `plan-modulo-proveedores.md` §2.6 |
| `proveedor_tarifas` | 5 | Admin/supervisor, al recibir el PDF anual del proveedor | Anual + cada vez que cambia una tarifa | `plan-modulo-proveedores.md` §2.6 |
| `paquetes_plantilla` | 5 | Admin/supervisor (arma el tour completo con sus items_incluidos) | Ocasional, cuando se arma un tour nuevo para vender | `plan-modulo-tours-catalogo.md` / `plan-modulo-cotizaciones-reservas.md` §3.7 |
| `cotizaciones` | 6 | Vendedor | Diaria | `plan-modulo-cotizaciones-reservas.md` |

## 5. ✅ Bloqueante resuelto: módulo 2 (Catálogo de destinos/tours)

**Actualizado 24-jul-2026.** El bloqueante detectado en la primera
versión de este documento quedó resuelto en la sesión dedicada al módulo
2 — ver `plan-modulo-tours-catalogo.md` (documento nuevo, creado esa
sesión). Se validó con documentos reales de tours de la agencia (Full Day
Alto Mayo, Tours Lamas Nativo), no con suposiciones:

- **Destino**: árbol de 3 niveles autoreferenciado (zona → lugar →
  atractivo), no una tabla plana.
- **Servicio**: catálogo propio `servicios` (Traslado ida y vuelta,
  Traslado aeropuerto-hotel, Entrada/Boleto, etc.), no el mismo concepto
  que "tipo de proveedor".
- **`destinos_atractivos` y `destino_servicio`**: dos tablas relacionadas,
  confirmado — `destino_servicio` es la tabla puente que cruza cualquier
  nivel del árbol de destinos con el catálogo de servicios.
- Bonus no anticipado: se confirmó que "tour" (`tour_itinerario_items.
  tour_id`) y `paquetes_plantilla` son la misma entidad, y se le agregaron
  campos de cabecera que faltaban (duración, horarios, lugar de recojo,
  no incluye, recomendaciones).

Con esto, `proveedor_servicios` (nivel 4 del árbol) queda listo para
construirse de verdad, no solo en papel.

## 6. Otros pendientes puntuales detectados al armar este documento

### 6.1 `temporada_ocurrencias` — ¿carga automática o manual cada año?
No se definió si el sistema genera automáticamente la ocurrencia del año
siguiente para temporadas fijas (ej. Navidad, mismo rango todos los años)
o si el admin debe crearla a mano cada vez. Para temporadas móviles
(Semana Santa) tiene que ser manual sí o sí, porque la fecha cambia según
el calendario litúrgico. Se puede resolver distinto para cada tipo.

### 6.2 `configuracion_agencia` no tiene CRUD propio definido
Se menciona repetidas veces en `cotizaciones-reservas.md` (edad de
infante, formato de PDF, días de vigencia de cotización, plazo de
limpieza de alternativas descartadas...) pero nunca se consolidó como una
sola pantalla de configuración. Vale la pena juntarlo en un solo lugar del
próximo sub-plan de frontend (Fase 3) en vez de que cada campo aparezca
suelto.

### 6.3 Rol para cargar `destinos_atractivos`/`destino_servicio` — RESUELTO
Mismo admin/supervisor que carga proveedores y tarifas (`plan-modulo-tours-catalogo.md`).

## 7. Recomendación de siguiente paso

Con el módulo 2 resuelto, ya no hay bloqueante entre niveles del árbol.
El siguiente paso natural es empezar la construcción real (migraciones +
CRUDs) siguiendo el orden de esta tabla, nivel por nivel — o cerrar antes
los pendientes puntuales de la sección 6 (`temporada_ocurrencias`
automática vs. manual, `configuracion_agencia` como pantalla única) si se
prefiere no dejarlos sueltos para cuando ya se esté construyendo.

## 8. Historial de actualizaciones

| Fecha | Cambio |
|---|---|
| 24-jul-2026 | Primera versión: se documenta el árbol completo de dependencias entre datos maestros, cruzando `plan-modulo-proveedores.md` y `plan-modulo-cotizaciones-reservas.md`. Se detecta que `proveedor_servicios` depende de `destino_servicio`, perteneciente al módulo 2 (catálogo de tours), que no tiene sub-plan propio — bloqueante real no marcado antes en ningún documento. |
| 24-jul-2026 | Bloqueante resuelto: se crea `plan-modulo-tours-catalogo.md`, validado con tours reales de la agencia. Árbol de dependencias actualizado — `destinos_atractivos`, `servicios` y `destino_servicio` ya no están marcados como pendientes. Se agrega `paquetes_plantilla` a la tabla de entidades (nivel 5, confirmado que es la misma entidad que "tour"). |
