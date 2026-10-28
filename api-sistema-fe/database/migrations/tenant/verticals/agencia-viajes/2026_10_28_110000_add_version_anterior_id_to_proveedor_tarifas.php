<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_10_28_110000_add_version_anterior_id_to_proveedor_tarifas.php
//
// T-028 (28-oct-2026): vínculo persistido entre una versión de tarifa y la
// que reemplaza. Antes version_anterior_id solo viajaba en la respuesta JSON
// de ProveedorTarifaController::update() y no quedaba rastro en la base.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proveedor_tarifas', function (Blueprint $table) {
            $table->foreignId('version_anterior_id')->nullable()
                ->constrained('proveedor_tarifas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('proveedor_tarifas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('version_anterior_id');
        });
    }
};
