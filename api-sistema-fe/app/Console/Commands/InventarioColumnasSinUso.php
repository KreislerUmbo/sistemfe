<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// T-083. SOLO LECTURA — no escribe nada. Cuenta cuántos datos tienen, por tenant, las columnas y
// tablas que el código ya no lee ni escribe (auditoría de Agencia del 09-nov), para decidir con el
// usuario si se borran, se migran o se dejan. La lista vive en ELEMENTOS: agregar ahí las tandas nuevas.
class InventarioColumnasSinUso extends Command
{
    protected $signature = 'inventario:columnas-sin-uso
        {--tenant=* : Id(s) de tenant. Por defecto: todos los no archivados}
        {--incluir-archivados : Incluye también tenants con status=archivado}';

    protected $description = 'INVENTARIO DE SOLO LECTURA (T-083): cuántos datos tienen las columnas y tablas sin uso en el código. No modifica nada.';

    // [tabla, columna|null]. Columna null = la tabla entera (cuenta filas). Con columna: filas con
    // valor no nulo (y distinto de 0/false, que es lo que dejan los defaults).
    private const ELEMENTOS = [
        ['proveedores', 'pais_id'],
        ['proveedores', 'departamento_id'],
        ['proveedores', 'provincia_id'],
        ['proveedores', 'distrito_id'],
        ['reserva', 'monto_reembolso'],
        ['reserva', 'porcentaje_reembolso_aplicado'],
        ['salidas_mayorista', 'fecha_retorno'],
        ['reserva_item_pasajero', 'checkin_realizado'],
        ['reserva_item_pasajero', 'checkin_hora'],
        // No es para borrar: es la tabla nueva del check-in (T-041). Se cuenta para comparar con las
        // columnas viejas de arriba antes de quitarlas.
        ['reserva_item_checkins', null],
        ['pago_proveedor', 'referencia_documento'],
        ['pago_proveedor', null],
        ['cronograma_pago_proveedor', null],
        ['reglas_cancelacion', null],
        ['recordatorios', null],
        ['tipos_recordatorio', null],
        ['recordatorio_snooze_config', null],
    ];

    public function handle(): int
    {
        $tenants = $this->resolverTenants();

        if ($tenants->isEmpty()) {
            $this->error('No se encontró ningún tenant (revisa --tenant).');

            return self::FAILURE;
        }

        $filas = [];
        foreach ($tenants as $tenant) {
            $conteos = $tenant->run(fn () => array_map(fn ($e) => $this->contar(...$e), self::ELEMENTOS));
            foreach (self::ELEMENTOS as $i => [$tabla, $columna]) {
                $filas[$tabla.($columna ? ".$columna" : ' (tabla)')][$tenant->id] = $conteos[$i];
            }
        }

        $ids = $tenants->pluck('id')->all();
        $this->table(
            array_merge(['Elemento'], $ids),
            collect($filas)->map(fn ($porTenant, $elemento) => array_merge([$elemento], array_map(fn ($id) => $porTenant[$id], $ids)))->values()->all()
        );
        $this->line('Columna: filas con dato (no nulo, ni 0/false). Tabla: filas totales. «—» = no existe en ese tenant (sin el vertical).');

        return self::SUCCESS;
    }

    private function contar(string $tabla, ?string $columna): string
    {
        if (! Schema::hasTable($tabla) || ($columna && ! Schema::hasColumn($tabla, $columna))) {
            return '—';
        }

        $consulta = DB::table($tabla);
        if ($columna) {
            $tipo = Schema::getColumnType($tabla, $columna);
            $consulta->whereNotNull($columna);
            if ($tipo === 'bool' || $tipo === 'boolean') {
                $consulta->where($columna, true);
            } elseif (in_array($tipo, ['numeric', 'decimal', 'int4', 'int8', 'integer', 'bigint'], true)) {
                $consulta->where($columna, '!=', 0);
            }
        }

        return (string) $consulta->count();
    }

    private function resolverTenants()
    {
        $idsExplicitos = $this->option('tenant');

        if (! empty($idsExplicitos)) {
            return Tenant::whereIn('id', $idsExplicitos)->get();
        }

        return Tenant::query()
            ->when(! $this->option('incluir-archivados'), fn ($q) => $q->where('status', '!=', 'archivado'))
            ->orderBy('id')
            ->get();
    }
}
