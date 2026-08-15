<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detraction_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3)->unique();        // '001', '002', ..., '099'
            $table->string('anexo', 1);                 // '1', '2', '3'
            $table->string('description');              // descripción SUNAT
            $table->decimal('percentage', 6, 4);        // tasa (ej: 0.1200 = 12%)
            $table->decimal('min_amount', 10, 2)->default(700.00); // monto mínimo
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Columna en productos para asignar código de detracción
        // Agregar a la migración de products o con alter:
             
    }
    public function down(): void
    {

    }
};
