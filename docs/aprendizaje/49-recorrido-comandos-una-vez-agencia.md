# Recorrido: retirar comandos de una sola vez, después de verificarlos en producción

09-nov-2026, tarea **T-099**. Salió de T-096: la auditoría proponía borrar 5 comandos, pero no
constaba que hubieran corrido en producción.

---

## 1. El problema

Un **comando de una sola vez** corrige datos que ya existían cuando se arregló un bug. Por ejemplo,
T-024 arregló que una alternativa duplicada compartiera la comparación de hoteles con la original,
y `separar-grupos-duplicados` separaba las que ya estaban mal.

Si se borra el comando **antes** de correrlo en producción, los datos viejos quedan mal para
siempre. Si se deja para siempre, el código acumula herramientas que nadie sabe si sirven.

## 2. Archivos

| Archivo | Qué pasó |
|---|---|
| `app/Console/Commands/SepararGruposDuplicados.php` + `Services/AgenciaViajes/SepararGruposDuplicadosService.php` | Borrados |
| `app/Console/Commands/PlantillasATarifaVigente.php` + `Services/AgenciaViajes/PlantillasATarifaVigenteService.php` | Borrados |
| `app/Console/Commands/BackfillFase1bAgenciaViajes.php` | Borrado |
| `app/Console/Commands/DiagnosticarFechasReserva.php` | Borrado (solo lectura; su migración ya pasó) |
| `tests/.../T028PlantillasATarifaVigenteTest.php`, `BackfillFase1bAgenciaViajesCommandTest.php` | Borrados |
| `tests/.../T024GrupoHotelesIndependienteTest.php` | Sin `test_ca4` (el del servicio); los demás prueban el arreglo y quedan |
| `app/Console/Commands/DiagnosticarTours.php` | **Se queda** (lo pide el despliegue de T-088) |

## 3. El recorrido

### 3.1 Cómo saber si un comando corrió

Los dos comandos que escriben datos dejan **registro** de cada cambio en una tabla:
`ajustes_grupo_opcion` y `ajustes_plantilla_tarifa`. Contar sus filas por tenant dice si corrieron:

```bash
php artisan tinker --execute='\App\Models\Tenant::find("dakamu")->run(function () { … \DB::table($t)->count() … });'
```

`Tenant::find(...)->run(fn)` ejecuta la función **dentro** de la base de ese tenant. Sin eso, la
consulta iría a la base central. En dakamu las dos tablas dieron 0: nunca corrieron.

### 3.2 Simular antes de escribir

Los dos tienen `--simular`: recorren lo mismo pero no escriben nada. Los dos dieron **0 cambios**:
en dakamu no había datos mal. Correrlos de verdad no habría hecho nada.

El backfill de permisos no tiene `--simular`, así que se miró a mano lo que haría:
1. el seeder `AgenciaViajesRolesSeeder` solo **agrega** los permisos que le falten a sus 4 roles;
2. después da los permisos granulares a los roles **ajenos** al catálogo que tengan el permiso plano.

Una consulta comparó el catálogo del seeder con los roles reales, y no faltaba nada.

### 3.3 Qué reemplaza al backfill

Cuando `AgenciaViajesRolesSeeder` sume un permiso nuevo, un tenant que ya existe lo recibe con:

```bash
php artisan roles:sync-permisos-nuevos 'Database\Seeders\AgenciaViajesRolesSeeder' --tenant=dakamu
```

Funciona porque el seeder implementa `RolesCatalogProvider`. Es general: sirve para cualquier
catálogo de roles, no solo el de la fase 1b.

## 4. Cómo se verificó

- En el servidor: conteos de `ajustes_*`, las dos simulaciones y la comparación de roles.
- `php artisan list`: de Agencia quedan `diagnosticar-tours` y `limpiar-operacion`.
- `grep` sin referencias a las clases borradas; `php artisan test --filter=AgenciaViajes` pasa (salvo
  el intermitente conocido, que solo pasa).

## 5. Lo que queda fuera

- Las tablas `ajustes_*` se conservan como registro (su limpieza es T-083).
- Archivos de tareas ya hechas que siguen en `docs/tareas/` y el rol Propietario de dakamu: «Por
  analizar» del backlog.
