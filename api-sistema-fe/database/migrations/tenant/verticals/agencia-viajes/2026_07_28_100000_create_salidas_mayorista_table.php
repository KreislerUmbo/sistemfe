<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_100000_create_salidas_mayorista_table.php
//
// Sesión 7b del vertical Agencia de Viajes (plan-hoja-de-ruta-ejecucion.md) —
// plan-modulo-cotizaciones-reservas.md §2.4, "Salidas de catálogo del
// mayorista": paquetes ya armados en fechas fijas, precio/cupo referencial,
// independiente de cualquier cotización puntual (catálogo, no cotizado a
// medida). Sin fila propia en la hoja de ruta original — se agrega acá
// porque opcion_mayorista.salida_mayorista_id la necesita, mismo criterio
// que temporada_ocurrencias en Sesión 5 (dependencia dura descubierta al
// construir la tabla que sí estaba en la hoja de ruta).
//
// proveedor_id: FK real a proveedores (Sesión 3) — la mayorista.
//
// cupo_ocupado: control interno, mantenido por aplicación cuando una
// opcion_mayorista de una alternativa ACEPTADA se vincula acá (Sesión 8/11)
// — no trigger de BD, solo informativo, no bloquea vender de más.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salidas_mayorista', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->constrained('proveedores');
            $table->string('nombre');
            $table->date('fecha_salida');
            $table->date('fecha_retorno');
            $table->unsignedSmallInteger('cupo_total')->nullable();
            $table->unsignedSmallInteger('cupo_ocupado')->default(0);
            $table->decimal('precio_costo', 10, 2)->nullable();
            $table->string('moneda')->nullable();
            $table->text('incluye')->nullable();
            $table->string('estado')->default('disponible'); // 'disponible' | 'agotado' | 'cancelado'
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salidas_mayorista');
    }
};
