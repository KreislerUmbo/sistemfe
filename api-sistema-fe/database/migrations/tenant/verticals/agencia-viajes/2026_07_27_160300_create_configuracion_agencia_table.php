<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_27_160300_create_configuracion_agencia_table.php
//
// Sesión 2 del vertical Agencia de Viajes — campos compilados de varias
// secciones de plan-modulo-cotizaciones-reservas.md (estaban sueltos en el
// documento original, ver plan-modulo-maestros-iniciales.md §6.2: "no tiene
// CRUD propio definido"). Tabla singleton: una sola fila por tenant, nunca
// un catálogo de muchas filas — la migración inserta la fila default
// directamente en up() para que todo tenant agencia_viajes provisionado la
// tenga sin pasos manuales aparte (a diferencia de Company/SunatConfig, que
// son 2 pasos manuales a propósito, ver arquitectura-multitenant-backend.md).
//
// dias_vigencia_cotizacion y dias_limpieza_alternativas_descartadas quedan
// NULL a propósito — el plan los define explícitamente "sin default fijo",
// cada agencia los configura.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion_agencia', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('edad_max_infante')->default(2);
            $table->unsignedSmallInteger('edad_max_nino')->default(12);
            $table->string('formato_descuento_pdf')->default('solo_final'); // 'solo_final' | 'tachado' | 'separado'
            $table->boolean('mostrar_descuento_como_linea')->default(false);
            $table->unsignedSmallInteger('dias_vigencia_cotizacion')->nullable(); // sin default fijo, configurable por agencia
            $table->unsignedSmallInteger('dias_limpieza_alternativas_descartadas')->nullable(); // sin default fijo (§3.2)
            $table->unsignedSmallInteger('max_pax_reserva_con_vuelo')->default(15);
            $table->unsignedSmallInteger('max_pax_reserva_grupo')->default(50);
            $table->unsignedSmallInteger('meses_margen_vencimiento_documento')->default(6);
            $table->unsignedSmallInteger('dias_aviso_pago_proveedor')->default(2);
            $table->unsignedSmallInteger('dias_cotizacion_estancada')->default(15);
            $table->timestamps();
        });

        DB::table('configuracion_agencia')->insert([
            'edad_max_infante' => 2,
            'edad_max_nino' => 12,
            'formato_descuento_pdf' => 'solo_final',
            'mostrar_descuento_como_linea' => false,
            'dias_vigencia_cotizacion' => null,
            'dias_limpieza_alternativas_descartadas' => null,
            'max_pax_reserva_con_vuelo' => 15,
            'max_pax_reserva_grupo' => 50,
            'meses_margen_vencimiento_documento' => 6,
            'dias_aviso_pago_proveedor' => 2,
            'dias_cotizacion_estancada' => 15,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion_agencia');
    }
};
