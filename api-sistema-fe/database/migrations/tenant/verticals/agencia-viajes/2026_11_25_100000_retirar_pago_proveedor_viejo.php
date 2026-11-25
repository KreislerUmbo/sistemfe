<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_11_25_100000_retirar_pago_proveedor_viejo.php
//
// T-141: `pago_proveedor` y `cronograma_pago_proveedor` (sesiones 8b y 9b) nunca tuvieron
// controlador, servicio ni pantalla. Compras las reemplaza por un solo modelo de pagos
// (`pagos_proveedor` + aplicaciones, T-136, plan §3.7), y el modelo nuevo usa el mismo nombre.
// Conteo de producción del 23-nov-2026 (`inventario:columnas-sin-uso`): 0 filas en dakamu.
// Salvaguarda: si en algún tenant hay filas, la migración se detiene sin borrar nada.
// down() vuelve a crear las dos tablas vacías con su esquema original.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLAS = ['pago_proveedor', 'cronograma_pago_proveedor'];

    public function up(): void
    {
        $conDatos = array_values(array_filter(
            self::TABLAS,
            fn (string $tabla) => Schema::hasTable($tabla) && DB::table($tabla)->exists()
        ));

        if ($conDatos !== []) {
            throw new RuntimeException('T-141: hay filas en tablas que se iban a borrar: '.implode(', ', $conDatos).'. Revisarlas antes de migrar.');
        }

        foreach (self::TABLAS as $tabla) {
            Schema::dropIfExists($tabla);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('cronograma_pago_proveedor')) {
            Schema::create('cronograma_pago_proveedor', function (Blueprint $table) {
                $table->id();
                $table->foreignId('proveedor_id')->nullable()->constrained('proveedores');
                $table->foreignId('opcion_mayorista_id')->nullable()->constrained('opcion_mayorista');
                $table->unsignedSmallInteger('numero_cuota');
                $table->decimal('monto_programado', 10, 2);
                $table->date('fecha_vencimiento');
                $table->string('estado')->default('pendiente');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('pago_proveedor')) {
            Schema::create('pago_proveedor', function (Blueprint $table) {
                $table->id();
                $table->foreignId('proveedor_id')->nullable()->constrained('proveedores');
                $table->foreignId('opcion_mayorista_id')->nullable()->constrained('opcion_mayorista');
                $table->decimal('monto', 10, 2);
                $table->string('moneda');
                $table->date('fecha');
                $table->string('tipo');
                $table->string('referencia_documento')->nullable();
                $table->timestamps();
            });
        }
    }
};
