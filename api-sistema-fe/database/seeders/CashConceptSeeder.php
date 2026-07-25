<?php

namespace Database\Seeders;

use App\Models\Cash\CashConcept;
use Illuminate\Database\Seeder;

// Módulo Caja — Fase 0 (plan-modulo-caja.md §3). Punto de partida editable
// por el dueño (crear/editar/desactivar desde su propio CRUD) — no es un
// catálogo cerrado como payment_methods.
//
// updateOrCreate por ('name','direction') — no hay columna 'code' propia para
// este catálogo (plan §3 no la define), así que el par nombre+dirección es la
// clave natural más cercana para no duplicar en una re-corrida del seeder.
class CashConceptSeeder extends Seeder
{
    public function run(): void
    {
        $conceptos = [
            ['name' => 'Comisión recibida', 'direction' => 'in'],
            ['name' => 'Cobro de deuda a terceros', 'direction' => 'in'],
            ['name' => 'Pago a proveedor', 'direction' => 'out'],
            ['name' => 'Caja chica', 'direction' => 'out'],
            ['name' => 'Retiro de seguridad', 'direction' => 'out'],
            ['name' => 'Servicios', 'direction' => 'out'],
        ];

        foreach ($conceptos as $concepto) {
            CashConcept::updateOrCreate(
                ['name' => $concepto['name'], 'direction' => $concepto['direction']],
                []
            );
        }
    }
}
