<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_10_28_100000_create_ajustes_grupo_opcion_table.php
//
// T-024 (28-oct-2026): registro de lo que cambia el comando
// agencia-viajes:separar-grupos-duplicados — cada fila de alternativa_items
// que recibió un grupo_opcion_id nuevo, con el anterior, para poder
// revisarlo o devolverlo a mano.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ajustes_grupo_opcion', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('alternativa_item_id');
            $table->uuid('grupo_anterior');
            $table->uuid('grupo_nuevo');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ajustes_grupo_opcion');
    }
};
