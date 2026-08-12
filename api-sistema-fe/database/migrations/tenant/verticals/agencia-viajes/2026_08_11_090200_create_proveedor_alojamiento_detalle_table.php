<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_08_11_090200_create_proveedor_alojamiento_detalle_table.php
//
// Consolidación de hoteles — datos específicos de un proveedor tipo
// Alojamiento (slug 'alojamiento-hoteles'), 1:1 con proveedores. Solo se
// crea una fila acá para proveedores de ese tipo — no se fuerza su
// existencia para el resto (transporte, alimentación, etc.), por eso
// proveedor_id es unique pero la fila en sí es opcional.
// edad_max_infante_gratis/edad_max_nino_cama_adicional reemplazan acá al
// equivalente que ya existía por HOTEL en opciones_hotel (Sesión 11o) —
// mismos defaults (4/12), ahora a nivel de proveedor completo.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedor_alojamiento_detalle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->unique()->constrained('proveedores')->cascadeOnDelete();
            $table->time('hora_checkin')->nullable();
            $table->time('hora_checkout')->nullable();
            $table->unsignedTinyInteger('edad_max_infante_gratis')->default(4);
            $table->unsignedTinyInteger('edad_max_nino_cama_adicional')->default(12);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedor_alojamiento_detalle');
    }
};
