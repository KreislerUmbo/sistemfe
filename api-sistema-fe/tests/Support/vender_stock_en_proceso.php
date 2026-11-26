<?php

/**
 * Proceso aparte para KardexConcurrenciaTest: una venta de un producto, con su propia conexión a
 * Postgres. Uso: php vender_stock_en_proceso.php <producto> <cantidad> <venta>
 * La conexión llega por la variable de entorno CONEXION_PRUEBA (JSON).
 */

use App\Models\Sale\Sale;
use App\Services\Stock\StockService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $productoId, $cantidad, $ventaId] = $argv;

config([
    'database.default' => 'pgsql',
    'database.connections.pgsql' => json_decode((string) getenv('CONEXION_PRUEBA'), true),
]);
DB::purge('pgsql');

DB::transaction(function () use ($productoId, $cantidad, $ventaId) {
    app(StockService::class)->vender(
        (new Sale())->forceFill(['id' => (int) $ventaId]),
        [['product_id' => (int) $productoId, 'quantity' => (float) $cantidad]],
    );
});

echo 'ok';
