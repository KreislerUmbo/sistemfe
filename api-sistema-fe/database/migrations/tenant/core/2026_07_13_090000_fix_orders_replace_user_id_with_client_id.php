<?php
// Migración correctiva — NO EJECUTAR sin revisión.
// create_orders_table.php (2026_06_09_001131) creó 'user_id' (FK a users),
// pero Postgres real tiene 'client_id' (FK a clients) y NO tiene
// 'user_id'. Esta corrige la tabla ya creada por esa migración vieja sin
// tocarla directamente.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');

            $table->unsignedBigInteger('client_id')->nullable();
            $table->foreign('client_id')->references('id')->on('clients');
            $table->index('client_id', 'fki_orders_client_id_foreign');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->dropIndex('fki_orders_client_id_foreign');
            $table->dropColumn('client_id');

            $table->foreignId('user_id')->nullable()->constrained();
        });
    }
};
