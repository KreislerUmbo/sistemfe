<?php

namespace App\Console\Commands;

use App\Models\Cash\Branch;
use App\Models\Cash\CashRegister;
use App\Models\Tenant;
use Illuminate\Console\Command;

// Módulo Caja — Fase 2 (Paso 0). Comando puntual, NO parte del flujo de
// tenants:provision — branches/cash_registers todavía no tienen su propio
// CRUD, así que esto solo destraba las pruebas manuales de apertura/cierre
// en 'sandbox'. Hardcodeado a ese tenant a propósito (el nombre del comando
// ya lo deja explícito) — no un seeder genérico reusable por cualquier
// tenant nuevo.
//
// firstOrCreate por nombre: correrlo dos veces no duplica la sede/caja de
// prueba, aunque esto no sea un seeder de producción.
class SeedSandboxCashDemo extends Command
{
    protected $signature = 'cash:seed-sandbox-demo';

    protected $description = 'Crea una branch + cash_register de prueba en el tenant sandbox, solo para probar apertura/cierre de caja (Fase 2). No usar en tenants reales.';

    public function handle(): int
    {
        $tenant = Tenant::find('sandbox');

        if (!$tenant) {
            $this->error("No existe el tenant 'sandbox'.");

            return self::FAILURE;
        }

        $tenant->run(function () {
            $branch = Branch::firstOrCreate(
                ['name' => 'Sede Principal'],
                ['is_active' => true]
            );

            $register = CashRegister::firstOrCreate(
                ['name' => 'Caja 1', 'branch_id' => $branch->id],
                [
                    'type' => 'fixed',
                    'is_active' => true,
                    'default_opening_amount' => 100.00,
                ]
            );

            $this->info("branch #{$branch->id} '{$branch->name}' — cash_register #{$register->id} '{$register->name}' listos en sandbox.");
        });

        return self::SUCCESS;
    }
}
