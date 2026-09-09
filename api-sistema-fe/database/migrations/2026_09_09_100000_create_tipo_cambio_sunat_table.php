<?php
// database/migrations/2026_09_09_100000_create_tipo_cambio_sunat_table.php
//
// Plan — Integración API Tipo de Cambio SUNAT, Fase 2. Tabla CENTRAL (no
// tenant/) — el tipo de cambio SUNAT/SBS es un dato nacional, no depende
// del tenant, mismo criterio que tax_configs/detraction_codes (ver
// TipoCambioSunat::class, CentralConnection). Una sola fila por fecha,
// escrita únicamente por el comando programado de sincronización — nunca
// por un usuario (a diferencia de tipo_cambio_agencia, tabla de tenant,
// comercial y editable, que NO se toca por esto).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipo_cambio_sunat', function (Blueprint $table) {
            $table->id();
            $table->date('fecha')->unique();
            $table->decimal('compra', 10, 4);
            $table->decimal('venta', 10, 4);
            $table->string('fuente'); // 'decolecta' | 'e-api'
            $table->timestamp('consultado_en');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipo_cambio_sunat');
    }
};
