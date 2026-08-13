<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reserva_items', function (Blueprint $table) {
            $table->foreignId('salida_operativa_id')->nullable()->after('tour_origen_id')->constrained('salidas_operativas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reserva_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('salida_operativa_id');
        });
    }
};
