<?php

// T-124 — `sales.porcentaje_retencion`: la tasa con la que se retuvo la venta (ej. 0.0300 = 3%).
// Detracción y percepción ya guardaban la suya; sin esta no se sabía con qué tasa se había
// retenido una venta ya emitida. Las ventas anteriores quedan en 0 (tasa desconocida).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('porcentaje_retencion', 6, 4)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('porcentaje_retencion');
        });
    }
};
