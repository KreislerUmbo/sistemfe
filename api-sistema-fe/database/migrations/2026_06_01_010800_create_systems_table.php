<?php
// Migración correctiva — NO EJECUTAR sin revisión.
// 'systems' existe en Postgres pero nunca tuvo Schema::create en disco.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('systems', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('category_id');
            $table->foreign('category_id')->references('id')->on('system_categories');
            $table->string('name', 100);
            $table->string('slug', 100)->nullable();
            $table->text('description_short')->nullable();
            $table->text('description_long')->nullable();
            $table->string('icon_emoji', 10)->nullable();
            $table->string('badge', 30)->nullable();
            $table->string('imagen_principal', 100)->nullable();
            $table->double('rating_prom')->nullable();
            $table->boolean('es_destacado')->default(false)->nullable();
            $table->integer('cantidad_clientes')->nullable();
            $table->boolean('is_active')->default(true)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('category_id', 'idx_systems_category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('systems');
    }
};
