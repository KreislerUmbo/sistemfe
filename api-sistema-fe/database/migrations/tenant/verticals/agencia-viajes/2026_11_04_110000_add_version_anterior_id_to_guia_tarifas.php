<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_11_04_110000_add_version_anterior_id_to_guia_tarifas.php
//
// Mismo cambio que T-028 hizo en proveedor_tarifas: vínculo persistido entre una
// versión de tarifa de guía y la que reemplaza. Antes version_anterior_id solo
// viajaba en la respuesta JSON de GuiaTarifaController::update() y no quedaba
// rastro en la base (ni forma de saber que una versión ya fue reemplazada).
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guia_tarifas', function (Blueprint $table) {
            $table->foreignId('version_anterior_id')->nullable()
                ->constrained('guia_tarifas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('guia_tarifas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('version_anterior_id');
        });
    }
};
