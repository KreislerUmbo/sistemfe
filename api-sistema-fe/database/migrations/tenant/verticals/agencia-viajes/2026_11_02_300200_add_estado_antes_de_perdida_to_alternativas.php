<?php

// T-052 (CRM C3): «Cerrar como perdida» descarta las alternativas en borrador o enviada y guarda acá
// el estado que tenían; «Reabrir» las devuelve a ese estado y lo deja en null. Las que ya estaban
// descartadas antes de la pérdida quedan en null y siguen descartadas al reabrir.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alternativas', function (Blueprint $table) {
            $table->string('estado_antes_de_perdida', 20)->nullable()->comment("'borrador' | 'enviada' antes de cerrar la cotización como perdida");
        });
    }

    public function down(): void
    {
        Schema::table('alternativas', function (Blueprint $table) {
            $table->dropColumn('estado_antes_de_perdida');
        });
    }
};
