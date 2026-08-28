<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_08_28_090200_add_tratamiento_tributario_default_to_configuracion_agencia_table.php
//
// Default de agencia para prellenar el tratamiento tributario al crear un
// ítem manual/mayorista/guia/pasaje_aereo (que no tienen una
// proveedor_tarifa de la que copiarlo) — pensado para agencias en
// Amazonía, donde el caso común es exonerado, sin dejar de permitir el
// caso ocasional fuera de la región (el campo sigue siendo editable por
// ítem, este es solo el valor inicial). Default de columna neutral
// ('10'/'nacional', igual al comportamiento legado) — cada tenant lo
// ajusta desde Configuración de Agencia según su región real.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_agencia', function (Blueprint $table) {
            $table->string('tip_afe_igv_default', 2)->default('10')->after('condiciones_generales_servicio');
            $table->string('destino_tributario_default')->default('nacional')->after('tip_afe_igv_default');
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_agencia', function (Blueprint $table) {
            $table->dropColumn(['tip_afe_igv_default', 'destino_tributario_default']);
        });
    }
};
