<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_09_07_120000_add_opcion_mayorista_opcional_to_alternativa_items_table.php
//
// Hueco real documentado desde 04-sep-2026 (memoria de proyecto
// project_agencia_viajes_opcionales_mayorista_lienzo_gap): un
// OpcionMayoristaOpcional (San Blas, Taboga, Colón...) solo existía como
// info de referencia en el PDF — sin esta columna no había forma de
// trazar que un AlternativaItem real "es" ese opcional elegido por el
// cliente (mismo criterio que opcion_hotel_tarifa_id, agregada en
// 2026_08_04_090000 por el mismo motivo: sin la FK, ninguna vista puede
// resolver de qué fila salió el precio).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->foreignId('opcion_mayorista_opcional_id')->nullable()
                ->after('opcion_hotel_tarifa_id')
                ->constrained('opcion_mayorista_opcionales')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opcion_mayorista_opcional_id');
        });
    }
};
