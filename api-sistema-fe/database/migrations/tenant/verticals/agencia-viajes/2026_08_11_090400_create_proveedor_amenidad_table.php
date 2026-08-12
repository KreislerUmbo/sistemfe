<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_08_11_090400_create_proveedor_amenidad_table.php
//
// Pivote proveedor × amenidad (tenant). amenidad_id SIN FK real cross-DB —
// amenidades es central, mismo criterio ya usado con proveedor_tipos_id
// referenciado desde tablas de tenant (ej. proveedores.tipo_id).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedor_amenidad', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->constrained('proveedores')->cascadeOnDelete();
            $table->unsignedBigInteger('amenidad_id'); // amenidades.id (central) — sin FK real cross-DB
            $table->timestamps();

            $table->unique(['proveedor_id', 'amenidad_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedor_amenidad');
    }
};
