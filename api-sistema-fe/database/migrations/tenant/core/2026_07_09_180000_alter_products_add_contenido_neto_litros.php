<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Volumen neto (en litros) de la presentación del producto — usado
            // solo cuando tipo_isc = '02' (Específico) y el régimen ISC del
            // producto se aplica por litro (ej. cerveza), no por unidad vendida.
            // Nulo/0 para el resto de productos, donde monto_isc_fijo se sigue
            // aplicando directo por unidad (comportamiento sin cambios).
            $table->decimal('contenido_neto_litros', 8, 4)->nullable()->after('monto_isc_fijo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('contenido_neto_litros');
        });
    }
};
