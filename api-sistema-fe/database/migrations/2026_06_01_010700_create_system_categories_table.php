<?php
// Migración correctiva — NO EJECUTAR sin revisión.
// 'system_categories' existe en Postgres pero nunca tuvo Schema::create
// en disco. Nota: la secuencia real se llama 'categorie_systems_id_seq'
// (resto de una tabla renombrada) — cosmético, no se replica; $table->id()
// generará la secuencia estándar 'system_categories_id_seq'.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_categories', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100)->nullable();
            $table->string('slug', 100)->nullable();
            $table->string('icon', 50)->nullable();
            $table->string('imagen')->nullable();
            $table->integer('display_order')->default(0)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->smallInteger('state')->nullable();
        });
        // real: 'imagen' es varchar SIN longitud (no varchar(255)).
        DB::statement('ALTER TABLE system_categories ALTER COLUMN imagen TYPE VARCHAR');
    }

    public function down(): void
    {
        Schema::dropIfExists('system_categories');
    }
};
