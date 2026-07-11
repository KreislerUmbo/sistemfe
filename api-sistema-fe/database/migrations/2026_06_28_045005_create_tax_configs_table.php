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
        Schema::create('tax_configs', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();           // ej: 'igv_general'
            $table->string('group');                   // 'igv' | 'retencion' | 'detraccion' | 'percepcion' | 'icbper'
            $table->string('label');                   // etiqueta legible para el admin
            $table->decimal('value', 10, 6);           // tasa numérica (ej: 0.180000)
            $table->string('description')->nullable(); // referencia legal
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_configs');
    }
};
