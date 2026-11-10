<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\AgenciaViajes\NormalizadorAerolineas;
use Illuminate\Console\Command;

// T-016 — Corrige los nombres de aerolínea ya guardados al nombre del catálogo común (T-015).
// Sin --simular corrige y deja registro en `ajustes_aerolinea`; --revertir lo deja como estaba.
class NormalizarAerolineas extends Command
{
    protected $signature = 'agencia-viajes:normalizar-aerolineas
        {tenant : ID del tenant de Agencia}
        {--simular : Solo muestra lo que cambiaría, sin escribir}
        {--revertir : Deshace las correcciones ya registradas}';

    protected $description = 'Reemplaza los nombres de aerolínea guardados por el del catálogo común. --simular no escribe; --revertir deshace.';

    public function handle(NormalizadorAerolineas $normalizador): int
    {
        $tenant = Tenant::find($this->argument('tenant'));
        if (! $tenant) {
            $this->error("No existe ningún tenant con id '{$this->argument('tenant')}'.");

            return self::FAILURE;
        }

        return $tenant->run(function () use ($normalizador, $tenant) {
            if ($this->option('revertir')) {
                $r = $normalizador->revertir();
                $this->info("Revertidos: {$r['revertidos']}. Omitidos (el campo ya se había cambiado a mano): {$r['omitidos']}.");

                return self::SUCCESS;
            }

            $simular = (bool) $this->option('simular');
            $resultado = $simular ? $normalizador->simular() : $normalizador->aplicar();

            $this->info("Tenant '{$tenant->id}' — ".($simular ? 'se cambiaría (no se escribió nada):' : 'corregido:'));
            $this->table(
                ['Tabla', 'Id', 'Columna', 'Antes', 'Después'],
                array_map(fn ($c) => [$c['tabla'], $c['id'], $c['columna'], $c['antes'], $c['despues']], $resultado['cambios'])
            );

            if ($resultado['sin_coincidencia']) {
                $this->warn('Sin coincidencia en el catálogo (no se tocan):');
                foreach ($resultado['sin_coincidencia'] as $valor => $veces) {
                    $this->line("  - «{$valor}» ({$veces})");
                }
            }

            return self::SUCCESS;
        });
    }
}
