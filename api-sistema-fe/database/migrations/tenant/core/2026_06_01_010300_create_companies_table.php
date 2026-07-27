<?php
// Migración correctiva — NO EJECUTAR sin revisión.
// 'companies' existe en Postgres pero nunca tuvo Schema::create en disco.
//
// DECISIÓN (aprobada): en Postgres real 'id' no tenía secuencia propia
// (funcionaba porque solo hay 1 fila insertada a mano). Se corrige acá
// usando $table->id() estándar (bigserial + PK autoincremental).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('razon_social', 250);
            $table->string('razon_social_comercial', 250);
            $table->string('phone', 50)->nullable();
            $table->string('email', 50)->nullable();
            $table->string('n_document', 50);
            $table->timestamp('birth_date')->nullable();
            $table->string('address', 200)->nullable();
            $table->string('urbanizacion', 200);
            $table->string('cod_local', 100);
            $table->string('ubigeo_distrito', 25)->nullable();
            $table->string('ubigeo_provincia', 25)->nullable();
            $table->string('ubigeo_region', 25)->nullable();
            $table->string('distrito', 80)->nullable();
            $table->string('provincia', 80)->nullable();
            $table->string('region', 80)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
