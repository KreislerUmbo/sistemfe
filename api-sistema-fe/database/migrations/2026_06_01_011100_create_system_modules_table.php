<?php
// Migración correctiva — NO EJECUTAR sin revisión.
// 'system_modules' existe en Postgres pero nunca tuvo Schema::create en
// disco.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_modules', function (Blueprint $table) {
            $table->increments('id'); // real: int4/serial, no bigserial
            $table->unsignedInteger('system_id');
            $table->foreign('system_id')->references('id')->on('systems')->onDelete('cascade');
            $table->string('name', 60);
            $table->string('icon', 10)->nullable();
            $table->integer('display_order')->default(0)->nullable();
            $table->smallInteger('estado')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_modules');
    }
};
