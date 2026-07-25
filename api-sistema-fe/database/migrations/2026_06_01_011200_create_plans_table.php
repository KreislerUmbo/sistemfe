<?php
// Migración correctiva — NO EJECUTAR sin revisión.
// 'plans' existe en Postgres pero nunca tuvo Schema::create en disco.
//
// DECISIÓN (aprobada): las columnas reales 'billing_period ' y
// 'description ' tienen un espacio al final del nombre en Postgres —
// confirmado typo, no diseño intencional. Se corrige acá creando las
// columnas con el nombre correcto (sin espacio) directamente.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('system_id');
            $table->foreign('system_id')->references('id')->on('systems')->onDelete('cascade');
            $table->enum('billing_period', ['monthly', 'annual']);
            $table->decimal('price', 8, 2);
            $table->integer('discount_percent')->default(0)->nullable();
            $table->boolean('is_active')->default(true)->nullable();
            $table->string('name', 50)->nullable();
            $table->text('description')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['system_id', 'billing_period'], 'plans_system_id_plan_type_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
