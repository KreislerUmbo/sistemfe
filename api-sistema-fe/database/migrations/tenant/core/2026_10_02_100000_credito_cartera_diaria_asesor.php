<?php

// Módulo Créditos, Fase 4d — reportes. La foto diaria de cartera guarda también el asesor del día
// (el reporte por asesor y las tendencias lo necesitan; hasta ahora solo tenía el cobrador).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credito_cartera_diaria', function (Blueprint $table) {
            $table->unsignedBigInteger('asesor_id')->nullable()->after('cliente_id')->comment('users.id del asesor del cliente a la fecha de corte (sin FK).');
            $table->index(['fecha_corte', 'asesor_id']);
        });
    }

    public function down(): void
    {
        Schema::table('credito_cartera_diaria', function (Blueprint $table) {
            $table->dropIndex(['fecha_corte', 'asesor_id']);
            $table->dropColumn('asesor_id');
        });
    }
};
