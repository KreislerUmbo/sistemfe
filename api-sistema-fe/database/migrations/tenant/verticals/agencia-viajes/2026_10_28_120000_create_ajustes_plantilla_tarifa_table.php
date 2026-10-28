<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_10_28_120000_create_ajustes_plantilla_tarifa_table.php
//
// T-028 (28-oct-2026): registro de lo que cambia el comando
// agencia-viajes:plantillas-a-tarifa-vigente — cada ítem de tour que pasó de
// una versión vieja de tarifa a la vigente, con ambos ids, para poder
// revisarlo o devolverlo a mano.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ajustes_plantilla_tarifa', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('paquete_plantilla_item_id');
            $table->unsignedBigInteger('tarifa_anterior_id');
            $table->unsignedBigInteger('tarifa_nueva_id');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ajustes_plantilla_tarifa');
    }
};
