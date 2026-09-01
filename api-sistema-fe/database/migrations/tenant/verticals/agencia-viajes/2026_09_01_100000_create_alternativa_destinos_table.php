<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_09_01_100000_create_alternativa_destinos_table.php
//
// Sesión 12b (multi-destino, auditoria-arquitectonica-agencia-viajes.md §7)
// — registro liviano por destino de un viaje, existe ANTES que cualquier
// AlternativaItem/OpcionMayorista (12c/12d los cuelgan de acá, sesiones
// futuras). Deliberadamente sin moneda/tipo de cambio — eso se queda en
// Alternativa, la moneda de presentación es una sola por cotización
// completa (§13 de la auditoría, confirmado contra benchmarking de
// industria: Ezus/Tourwriter/Tourplan no mezclan monedas por segmento).
//
// destino_atractivo_id nullable + destino_texto de respaldo: el backfill
// (migración siguiente) resuelve el texto libre histórico de
// cotizaciones.destino contra el catálogo cuando puede, pero no todo
// texto libre matchea un destino_atractivo real — mismo patrón ya usado
// en OpcionHotel (nombre_hotel libre + proveedor_id nullable) para casos
// ad-hoc sin catálogo. Ver brief PEGAR-EN-CLAUDE-CODE-12b-crear-alternativa-destinos.md
// §0 para el gap real encontrado (variante de mayúsculas ya en producción:
// "Alto Mayo" vs "Alto mayo").

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alternativa_destinos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alternativa_id')->constrained('alternativas');
            $table->foreignId('destino_atractivo_id')->nullable()->constrained('destinos_atractivos')->nullOnDelete();
            $table->string('destino_texto')->nullable();
            $table->integer('orden')->default(1);
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alternativa_destinos');
    }
};
