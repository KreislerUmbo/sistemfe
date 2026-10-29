<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// T-013 (Caja, paso B): la caja lleva saldo por moneda.
// - cash_sessions: las columnas actuales (opening_amount, expected_cash, counted_cash, difference)
//   quedan como SOLES; se agregan las de dólares. Las sesiones ya cerradas no se tocan.
// - cash_movements: tipo de cambio y enlace entre las dos patas de un "cambio de moneda".
// - cash_session_denominations: moneda del conteo de billetes (lo existente es soles).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->decimal('opening_amount_usd', 10, 2)->default(0)->after('opening_amount');
            $table->decimal('expected_cash_usd', 10, 2)->nullable()->after('difference');
            $table->decimal('counted_cash_usd', 10, 2)->nullable()->after('expected_cash_usd');
            $table->decimal('difference_usd', 10, 2)->nullable()->after('counted_cash_usd');
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->decimal('exchange_rate', 10, 4)->nullable()->after('currency')
                ->comment('Cambio de moneda: soles por dólar');
            $table->unsignedBigInteger('linked_movement_id')->nullable()->after('exchange_rate')
                ->comment('Cambio de moneda: el movimiento de la otra moneda');
            $table->foreign('linked_movement_id')->references('id')->on('cash_movements')->onDelete('restrict');
        });

        Schema::table('cash_session_denominations', function (Blueprint $table) {
            $table->string('currency', 3)->default('PEN')->after('cash_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('cash_session_denominations', function (Blueprint $table) {
            $table->dropColumn('currency');
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropForeign(['linked_movement_id']);
            $table->dropColumn(['exchange_rate', 'linked_movement_id']);
        });

        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->dropColumn(['opening_amount_usd', 'expected_cash_usd', 'counted_cash_usd', 'difference_usd']);
        });
    }
};
