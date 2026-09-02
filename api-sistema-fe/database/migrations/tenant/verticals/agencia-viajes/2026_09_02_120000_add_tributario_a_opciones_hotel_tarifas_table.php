<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_09_02_120000_add_tributario_a_opciones_hotel_tarifas_table.php
//
// Sesión M3 — hotel ad-hoc local + tributario (plan-matriz-hoteles-
// cotizador.md Ronda 6/P16). opciones_hotel_tarifas no tenía ninguna
// columna de impuesto — sin esto, un hotel internacional agregado ahí
// heredaría el default neutral de la agencia ('10'/'nacional') mal
// clasificado en silencio. Mismo patrón que proveedor_tarifas.
//
// proveedor_promovido_id en opciones_hotel (Ronda 4/P12): igual criterio
// que alternativa_items.proveedor_promovido_id — guard contra promover
// el mismo hotel ad-hoc dos veces, informativo, sin relink retroactivo.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opciones_hotel_tarifas', function (Blueprint $table) {
            $table->string('tip_afe_igv', 2)->nullable()->after('precio_venta_cama_adicional');
            $table->string('destino_tributario')->nullable()->after('tip_afe_igv');
        });

        Schema::table('opciones_hotel', function (Blueprint $table) {
            $table->foreignId('proveedor_promovido_id')->nullable()->after('proveedor_id')
                ->constrained('proveedores')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('opciones_hotel', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proveedor_promovido_id');
        });

        Schema::table('opciones_hotel_tarifas', function (Blueprint $table) {
            $table->dropColumn(['tip_afe_igv', 'destino_tributario']);
        });
    }
};
