<?php

/**
 * Proceso aparte para CompraConcurrenciaTest: la entrada de una compra de un producto, con su
 * propia conexión a Postgres. Uso: php comprar_en_proceso.php <producto> <cantidad> <costo> <compra>
 * La conexión llega por la variable de entorno CONEXION_PRUEBA (JSON).
 */

use App\Models\Compras\Compra;
use App\Services\Stock\StockService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $productoId, $cantidad, $costo, $compraId] = $argv;

config([
    'database.default' => 'pgsql',
    'database.connections.pgsql' => json_decode((string) getenv('CONEXION_PRUEBA'), true),
]);
DB::purge('pgsql');

DB::transaction(function () use ($productoId, $cantidad, $costo, $compraId) {
    app(StockService::class)->entrarPorCompra(
        (new Compra())->forceFill(['id' => (int) $compraId]),
        [['product_id' => (int) $productoId, 'cantidad' => $cantidad, 'factor' => '1', 'costo_unitario_base' => $costo]],
    );
});

echo 'ok';
