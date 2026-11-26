<?php

// T-135 (D1) — módulos activables por tenant: la parte mínima de plan-modulo-planes-acceso.md §2,
// sin planes. `compras` nace apagado en todos los tenants (no se siembra ningún override).
//
// El módulo se inserta AQUÍ y no en un seeder: MenuItemsSeeder lo busca por código.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('modulos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 60)->unique();
            $table->string('nombre', 100);
            $table->string('descripcion')->nullable();
            // null = disponible para todos los giros.
            $table->string('giro')->nullable();
            $table->timestamps();
        });

        Schema::connection('central')->create('tenant_modulo_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('modulo_id')->constrained('modulos')->cascadeOnDelete();
            $table->boolean('habilitado');
            $table->string('notas')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'modulo_id']);
        });

        DB::connection('central')->table('modulos')->insert([
            'codigo' => 'compras',
            'nombre' => 'Compras',
            'descripcion' => 'Registro de comprobantes de compra y periodos de anotación.',
            'giro' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('tenant_modulo_overrides');
        Schema::connection('central')->dropIfExists('modulos');
    }
};
