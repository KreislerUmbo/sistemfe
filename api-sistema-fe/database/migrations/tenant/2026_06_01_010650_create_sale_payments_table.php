<?php
// Migración correctiva — NO EJECUTAR sin revisión.
// 'sale_payments' existe en Postgres pero nunca tuvo Schema::create en
// disco.
//
// DECISIÓN (aprobada): la tabla real no tenía PRIMARY KEY pese a tener
// columna 'id' con secuencia propia. Se corrige acá agregando la PK
// estándar sobre 'id' con $table->id().

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sale_id');
            $table->string('method_payment', 100)->nullable();
            $table->double('amount')->nullable();
            $table->timestamp('date_payment')->nullable();
            $table->text('comments')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
    }
};
