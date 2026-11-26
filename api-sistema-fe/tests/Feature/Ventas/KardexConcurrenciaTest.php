<?php

declare(strict_types=1);

namespace Tests\Feature\Ventas;

use App\Models\Product\Product;
use App\Models\Product\StockMovimiento;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Creditos\CreditosTestCase;

/**
 * T-153 CA4 — dos ventas simultáneas del mismo producto, en dos procesos con su propia conexión.
 * Un lock entre sesiones solo existe con la fila confirmada: se confirma y se limpia a mano.
 */
final class KardexConcurrenciaTest extends CreditosTestCase
{
    public function test_ca4_dos_ventas_simultaneas_dejan_saldos_consecutivos(): void
    {
        // La base abre una transacción por test: se DESCARTA para que los otros procesos vean el producto.
        DB::rollBack();
        $productoId = null;
        $procesos = [];

        try {
            $productoId = Product::create([
                'title' => 'Concurrencia T153', 'sku' => 'SKU-T153C-' . uniqid(), 'categorie_id' => 1,
                'price_general' => 10, 'price_company' => 8, 'state' => 1, 'unidad_medida' => 'NIU', 'stock' => 10,
                'is_discount' => 0, 'disponiblidad' => 2, 'include_igv' => 0, 'is_icbper' => 0, 'is_ivap' => 0,
                'tipo_bien_servicio' => 'BIEN', 'controla_stock' => true,
            ])->id;

            // Una tercera sesión retiene el producto hasta que las dos ventas estén esperando.
            config(['database.connections.pgsql_a' => config('database.connections.pgsql')]);
            DB::purge('pgsql_a');
            DB::connection('pgsql_a')->beginTransaction();
            DB::connection('pgsql_a')->select('select id from products where id = ? for update', [$productoId]);

            foreach ([[3, 900001], [2, 900002]] as [$cantidad, $ventaId]) {
                $procesos[] = $this->lanzarVenta($productoId, $cantidad, $ventaId);
            }

            $esperando = 0;
            $limite = microtime(true) + 60;
            while ($esperando < 2 && microtime(true) < $limite) {
                usleep(100_000);
                $esperando = (int) DB::selectOne(
                    "select count(*) as n from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock'"
                )->n;
            }
            DB::connection('pgsql_a')->rollBack();

            foreach ($procesos as [$proceso, $tubos]) {
                $salida = stream_get_contents($tubos[1]) . stream_get_contents($tubos[2]);
                $this->assertSame(0, proc_close($proceso), $salida);
            }
            $procesos = [];
            $this->assertSame(2, $esperando, 'Las dos ventas debían quedar esperando el mismo bloqueo.');

            $filas = StockMovimiento::where('product_id', $productoId)->orderBy('id')->get();
            $this->assertCount(2, $filas);
            $saldo = 10.0;
            foreach ($filas as $fila) {
                $saldo += $fila->cantidad;
                $this->assertEquals($saldo, $fila->saldo, 'Saldo repetido o saltado: ' . $filas->pluck('saldo')->toJson());
            }
            $this->assertEquals(5, $saldo);
            $this->assertEquals(5, Product::findOrFail($productoId)->stock);
        } finally {
            if (DB::connection('pgsql_a')->transactionLevel() > 0) {
                DB::connection('pgsql_a')->rollBack();
            }
            foreach ($procesos as [$proceso]) {
                proc_terminate($proceso);
            }
            if ($productoId !== null) {
                DB::table('stock_movimientos')->where('product_id', $productoId)->delete();
                DB::table('products')->where('id', $productoId)->delete();
            }
            DB::beginTransaction(); // equilibra el rollBack del tearDown
        }
    }

    /** @return array{0: resource, 1: array<int, resource>} */
    private function lanzarVenta(int $productoId, int $cantidad, int $ventaId): array
    {
        $proceso = proc_open(
            [PHP_BINARY, base_path('tests/Support/vender_stock_en_proceso.php'), (string) $productoId, (string) $cantidad, (string) $ventaId],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $tubos,
            base_path(),
            getenv() + ['CONEXION_PRUEBA' => json_encode(config('database.connections.pgsql'))],
        );
        $this->assertIsResource($proceso);

        return [$proceso, $tubos];
    }
}
