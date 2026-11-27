<?php

declare(strict_types=1);

namespace Tests\Feature\Ventas;

use App\Models\Product\Product;
use App\Models\Product\StockMovimiento;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Creditos\CreditosTestCase;

/**
 * T-154 CA6 — dos compras simultáneas del mismo producto, en dos procesos con su propia conexión.
 * Mismo montaje que KardexConcurrenciaTest: se confirma de verdad y se limpia a mano.
 */
final class CompraConcurrenciaTest extends CreditosTestCase
{
    public function test_ca6_dos_compras_simultaneas_dejan_stock_y_promedio_correctos(): void
    {
        DB::rollBack(); // para que los otros procesos vean el producto
        $productoId = null;
        $procesos = [];

        try {
            $producto = Product::create([
                'title' => 'Concurrencia T154', 'sku' => 'SKU-T154C-' . uniqid(), 'categorie_id' => 1,
                'price_general' => 10, 'price_company' => 8, 'state' => 1, 'unidad_medida' => 'NIU', 'stock' => 10,
                'is_discount' => 0, 'disponiblidad' => 2, 'include_igv' => 0, 'is_icbper' => 0, 'is_ivap' => 0,
                'tipo_bien_servicio' => 'BIEN', 'controla_stock' => true,
            ]);
            $producto->forceFill(['costo_promedio' => '5'])->save();
            $productoId = $producto->id;

            // Una tercera sesión retiene el producto hasta que las dos compras estén esperando.
            config(['database.connections.pgsql_a' => config('database.connections.pgsql')]);
            DB::purge('pgsql_a');
            DB::connection('pgsql_a')->beginTransaction();
            DB::connection('pgsql_a')->select('select id from products where id = ? for update', [$productoId]);

            foreach ([['10', '6', 900011], ['20', '8', 900012]] as [$cantidad, $costo, $compraId]) {
                $procesos[] = $this->lanzarCompra($productoId, $cantidad, $costo, $compraId);
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
            $this->assertSame(2, $esperando, 'Las dos compras debían quedar esperando el mismo bloqueo.');

            $producto = Product::findOrFail($productoId);
            $this->assertEquals(40, $producto->stock);
            // (10·5 + 10·6 + 20·8) / 40 = 6.75, entre en el orden que entren.
            $this->assertSame('6.750000', $producto->costo_promedio);
            $this->assertSame(2, StockMovimiento::where('product_id', $productoId)->count());
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
    private function lanzarCompra(int $productoId, string $cantidad, string $costo, int $compraId): array
    {
        $proceso = proc_open(
            [PHP_BINARY, base_path('tests/Support/comprar_en_proceso.php'), (string) $productoId, $cantidad, $costo, (string) $compraId],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $tubos,
            base_path(),
            getenv() + ['CONEXION_PRUEBA' => json_encode(config('database.connections.pgsql'))],
        );
        $this->assertIsResource($proceso);

        return [$proceso, $tubos];
    }
}
