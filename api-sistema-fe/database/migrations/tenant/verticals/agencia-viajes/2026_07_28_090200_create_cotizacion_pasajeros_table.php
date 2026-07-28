<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_090200_create_cotizacion_pasajeros_table.php
//
// Sesión 7a del vertical Agencia de Viajes — plan-modulo-cotizaciones-reservas.md
// §3.1: en esta etapa el pasajero es básicamente un conteo por tipo, para
// poder calcular precio — nombre/documento completos no son necesarios
// todavía (eso vive en pasajeros_catalogo, Sesión 9).
//
// edad: OBLIGATORIA (NOT NULL) — es la fuente de verdad para el precio en
// alternativa_items (cada proveedor_tarifa puede tener su propio corte de
// edad_min_nino/edad_max_nino/edad_max_infante, Sesión 5), no tipo_pax.
// tipo_pax se sugiere automáticamente desde la edad usando
// configuracion_agencia.edad_max_infante/edad_max_nino, pero queda como
// columna real editable, no recalculada en cada lectura.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cotizacion_pasajeros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cotizacion_id')->constrained('cotizaciones');
            $table->string('tipo_pax'); // 'adulto' | 'nino' | 'infante'
            $table->unsignedTinyInteger('edad');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cotizacion_pasajeros');
    }
};
