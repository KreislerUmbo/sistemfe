<?php

declare(strict_types=1);

namespace Tests\Feature\Creditos;

use App\Services\Creditos\CobroService;
use App\Services\Creditos\Dto\SolicitudCobro;
use App\Services\Creditos\Motor\Enums\DestinoExcedente;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * 03-api "concurrencia": dos cobros simultáneos sobre el mismo crédito no se aplican en
 * paralelo. Mismo patrón que CodigoGeneradorServiceTest: un lock de fila entre dos sesiones
 * solo se observa con la fila confirmada, así que aquí se confirma y se limpia a mano.
 */
class ConcurrenciaCobroTest extends CreditosTestCase
{
    public function test_un_segundo_cobro_espera_el_lock_del_credito(): void
    {
        // La base abre una transacción por test y siembra datos en ella: se DESCARTA (nunca se
        // confirma) y solo se confirman las dos filas que el lock necesita, limpiadas al final.
        DB::rollBack();
        $clienteId = null;
        $creditoId = null;

        try {
            $clienteId = DB::table('clients')->insertGetId([
                'name' => 'Lock', 'surname' => 'Test', 'full_name' => 'Lock Test', 'type_client' => 1,
                'type_document' => 'DNI', 'n_document' => (string) random_int(10_000_000, 99_999_999),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $creditoId = DB::table('creditos')->insertGetId([
                'cliente_id' => $clienteId, 'monto_capital' => '1000.00', 'tasa_interes' => '20.0000', 'unidad_tasa' => 'total',
                'interes_total' => '200.00', 'frecuencia_unidad' => 'mes', 'dias_no_laborables' => '[]', 'numero_cuotas' => 1,
                'fecha_desembolso' => '2026-01-01', 'tasa_interes_minimo' => '10.0000', 'estado' => 'activo',
                'registrado_por' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);

            // Conexión A: un cobro en curso sostiene el lock del crédito.
            config(['database.connections.pgsql_a' => config('database.connections.pgsql')]);
            DB::purge('pgsql_a');
            DB::connection('pgsql_a')->beginTransaction();
            DB::connection('pgsql_a')->select('select id from creditos where id = ? for update', [$creditoId]);

            // Conexión por defecto: el segundo cobro debe quedar esperando ese lock. El usuario se
            // crea dentro de esta transacción para no dejar filas confirmadas.
            DB::beginTransaction();
            DB::statement("SET LOCAL lock_timeout = '300ms'");
            $this->asegurarRolPorDefecto();
            $usuario = $this->usuario();
            $bloqueado = false;
            try {
                app(CobroService::class)->cobrar(
                    \App\Models\Creditos\Credito::findOrFail($creditoId),
                    new SolicitudCobro(10_000, DestinoExcedente::Devolver, $this->efectivo->id, 'lock-0001'),
                    $usuario,
                );
            } catch (QueryException $e) {
                $bloqueado = str_contains($e->getMessage(), '55P03') || str_contains(strtolower($e->getMessage()), 'lock');
            }
            DB::rollBack();
            DB::connection('pgsql_a')->rollBack();

            $this->assertTrue($bloqueado, 'El cobro debía esperar el lock del crédito (lockForUpdate) antes de leer o escribir.');
        } finally {
            if ($creditoId !== null) {
                DB::table('creditos')->where('id', $creditoId)->delete();
            }
            if ($clienteId !== null) {
                DB::table('clients')->where('id', $clienteId)->delete();
            }
            DB::beginTransaction();   // equilibra el rollBack del tearDown
        }
    }
}
