<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_08_04_090100_add_moneda_proveedor_tarifa_to_opciones_hotel_tables.php
//
// Sesión 11k, Fix 9 — conecta opciones_hotel.proveedor_id (existente desde
// Sesión 5, nunca expuesto en ningún formulario) con una tarifa real de
// proveedor_tarifas, para poder mostrar/usar un precio "en vivo" en vez de
// solo texto tipeado a mano. moneda vive a nivel de opciones_hotel (un
// hotel cotiza todas sus habitaciones en la misma moneda) — no existía
// ningún campo de moneda para paquete_plantilla hasta ahora (a diferencia
// de opcion_mayorista.moneda, que ya existe).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opciones_hotel', function (Blueprint $table) {
            $table->string('moneda', 3)->default('PEN')->after('proveedor_id');
        });

        Schema::table('opciones_hotel_tarifas', function (Blueprint $table) {
            $table->foreignId('proveedor_tarifa_id')->nullable()
                ->after('opcion_hotel_id')
                ->constrained('proveedor_tarifas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('opciones_hotel_tarifas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proveedor_tarifa_id');
        });

        Schema::table('opciones_hotel', function (Blueprint $table) {
            $table->dropColumn('moneda');
        });
    }
};
