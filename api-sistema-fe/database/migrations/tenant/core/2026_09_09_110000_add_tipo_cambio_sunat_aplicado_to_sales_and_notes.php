<?php
// database/migrations/tenant/core/2026_09_09_110000_add_tipo_cambio_sunat_aplicado_to_sales_and_notes.php
//
// Plan — Integración API Tipo de Cambio SUNAT, Fase 4. Snapshot del tipo
// de cambio SUNAT/SBS (venta) vigente al momento de enviar a SUNAT, solo
// relevante cuando currency='USD'. Decisión del usuario (09-sep-2026): NO
// bloquea la emisión si no hay dato disponible — evita forzar un CRUD de
// carga manual. Queda null en ese caso; se completa el día que exista el
// reporte Registro de Ventas/PLE que lo consuma.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('tipo_cambio_sunat_aplicado', 10, 4)->nullable()->after('sunat_sent_at');
        });

        Schema::table('notes', function (Blueprint $table) {
            $table->decimal('tipo_cambio_sunat_aplicado', 10, 4)->nullable()->after('sunat_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('tipo_cambio_sunat_aplicado');
        });

        Schema::table('notes', function (Blueprint $table) {
            $table->dropColumn('tipo_cambio_sunat_aplicado');
        });
    }
};
