<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\AgenciaViajes\LimpiarOperacionPrueba;
use Illuminate\Console\Command;

// T-070 — Borra la operación de prueba de un tenant de Agencia y conserva la
// configuración. Sin --ejecutar solo muestra lo que borraría. Antes de
// ejecutarlo en el servidor: backup de la base y del storage del tenant.
class LimpiarOperacionAgencia extends Command
{
    protected $signature = 'agencia-viajes:limpiar-operacion
        {tenant : ID del tenant de Agencia}
        {--ejecutar : Borra de verdad (sin esto, solo simula)}';

    protected $description = 'Borra cotizaciones, reservas, ventas, pagos y caja de prueba de un tenant de Agencia. Conserva tours, tarifas, proveedores, clientes y configuración. Sin --ejecutar solo simula.';

    public function handle(LimpiarOperacionPrueba $limpieza): int
    {
        $tenant = Tenant::find($this->argument('tenant'));

        if (! $tenant) {
            $this->error("No existe ningún tenant con id '{$this->argument('tenant')}'.");

            return self::FAILURE;
        }

        return $tenant->run(function () use ($tenant, $limpieza) {
            $motivos = $limpieza->motivosParaNoLimpiar($tenant);
            if ($motivos) {
                $this->error("No se limpia '{$tenant->id}':");
                foreach ($motivos as $motivo) {
                    $this->line("  - {$motivo}");
                }

                return self::FAILURE;
            }

            $simulacion = $limpieza->simular();
            $this->mostrar($tenant, $simulacion);

            if (! $this->option('ejecutar')) {
                $this->info('Simulación: no se borró nada. Para borrar, repetir con --ejecutar.');

                return self::SUCCESS;
            }

            $this->warn('Se borra de verdad. Hacer antes el backup de la base y del storage, y que nadie esté usando el sistema.');
            if ($this->ask("Escribí el ID del tenant para confirmar ({$tenant->id})") !== $tenant->id) {
                $this->info('No coincide: no se borró nada.');

                return self::FAILURE;
            }

            $resumen = $limpieza->ejecutar();
            $this->info(sprintf(
                'Listo: %d filas borradas, %d archivos borrados (%d no estaban en el disco).',
                array_sum($resumen['filas']),
                $resumen['archivos_borrados'],
                $resumen['archivos_no_encontrados']
            ));

            return self::SUCCESS;
        });
    }

    private function mostrar(Tenant $tenant, array $simulacion): void
    {
        $this->info("Tenant '{$tenant->id}' — lo que se borra:");
        $this->table(
            ['Tabla', 'Filas'],
            collect($simulacion['filas'])->filter()->map(fn ($n, $tabla) => [$tabla, $n])->values()->all()
        );
        $this->line('Total de filas: ' . array_sum($simulacion['filas']));
        $this->line("Archivos (XML, CDR, adjuntos de caja): {$simulacion['archivos']}");
        $this->line('Series que vuelven a su número inicial: ' . (implode(', ', $simulacion['series']) ?: 'ninguna'));
        $this->line('Códigos que vuelven a 0: ' . (implode(', ', $simulacion['secuencias']) ?: 'ninguno'));
        $this->line("Clientes cuyo saldo a favor vuelve a 0: {$simulacion['clientes_con_saldo']}");
        $this->line('Se conservan: tours, tarifas, proveedores, guías, destinos, clientes, usuarios y configuración.');
    }
}
