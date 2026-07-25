<?php
// Migración correctiva — NO EJECUTAR sin revisión.
// 'clients' existe en Postgres pero nunca tuvo Schema::create en disco.
// Columnas replicadas 1:1 desde information_schema (auditoría 2026-07-12).
// user_id referencia 'users', que ya existe desde la migración base — sin
// problema de orden.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200)->nullable();
            $table->string('full_name', 250)->nullable();
            $table->string('phone', 25)->nullable();
            $table->string('email', 200)->nullable();
            $table->smallInteger('type_client')->nullable();
            $table->string('type_document', 50);
            $table->string('n_document', 50);
            $table->string('gender', 1)->nullable();
            $table->timestamp('birth_date')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users');
            $table->string('address', 250)->nullable();
            $table->string('ubigeo_distrito', 25)->nullable();
            $table->string('ubigeo_provincia', 25)->nullable();
            $table->string('ubigeo_region', 25)->nullable();
            $table->string('distrito', 80)->nullable();
            $table->string('provincia', 80)->nullable();
            $table->string('region', 80)->nullable();
            $table->smallInteger('state')->default(1)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->string('surname', 250)->nullable();
            $table->string('name_comerc', 250)->nullable();
            $table->string('password', 250)->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('cod_tipo_doc_sunat', 5)->nullable();
            $table->boolean('es_amazonia')->nullable();
            $table->string('regimen_tributario', 20)->nullable();
            $table->boolean('es_agente_retencion')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
