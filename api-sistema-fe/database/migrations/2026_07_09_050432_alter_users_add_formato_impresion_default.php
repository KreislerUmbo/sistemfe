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
        Schema::table('users', function (Blueprint $table) {
            // Formato de impresión preferido del usuario para la representación
            // impresa de comprobantes (A4 normal o ticket térmico 80mm).
            // Se puede sobreescribir puntualmente al imprimir una venta específica.
            $table->enum('formato_impresion_default', ['a4', 'ticket80mm'])
                  ->default('a4')
                  ->after('state');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('formato_impresion_default');
        });
    }
};
