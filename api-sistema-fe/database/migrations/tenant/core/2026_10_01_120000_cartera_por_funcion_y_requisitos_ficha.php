<?php

// Módulo Créditos, Fase 4c — cartera por asesor/cobrador y ficha exigida para prestar.
// cartera_asignaciones: cobrador_id → usuario_id + funcion (asesor|cobrador); una sola
// asignación vigente por cliente y función. credito_configuracion: asesor_cobra (hoy la
// misma persona) y requisitos_ficha. creditos.asesor_id: quién colocó el crédito.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cartera_asignaciones', function (Blueprint $table) {
            $table->renameColumn('cobrador_id', 'usuario_id');
        });
        Schema::table('cartera_asignaciones', function (Blueprint $table) {
            // Las asignaciones existentes eran de cobro.
            $table->enum('funcion', ['asesor', 'cobrador'])->default('cobrador')->after('usuario_id')
                ->comment('asesor = coloca créditos; cobrador = los cobra (04c).');
        });
        DB::statement("COMMENT ON COLUMN cartera_asignaciones.usuario_id IS 'users.id del asesor o cobrador (sin FK).'");
        DB::statement('CREATE UNIQUE INDEX cartera_asignaciones_una_vigente ON cartera_asignaciones (tipo, referencia_id, funcion) WHERE vigente_hasta IS NULL');

        Schema::table('credito_configuracion', function (Blueprint $table) {
            $table->boolean('asesor_cobra')->default(true)->comment('true = el asesor del cliente también lo cobra (una sola asignación en pantalla).');
            $table->json('requisitos_ficha')->nullable()->comment('Datos de la ficha exigidos para activar un crédito (RequisitoFicha).');
        });
        DB::table('credito_configuracion')->update([
            'requisitos_ficha' => json_encode(['dni_anverso', 'dni_reverso', 'foto_cliente', 'direccion_cobro', 'ubicacion']),
        ]);

        Schema::table('creditos', function (Blueprint $table) {
            $table->unsignedBigInteger('asesor_id')->nullable()->index()
                ->comment('users.id del asesor que colocó el crédito; se fija al activar (sin FK).');
        });
    }

    public function down(): void
    {
        Schema::table('creditos', fn (Blueprint $table) => $table->dropColumn('asesor_id'));
        Schema::table('credito_configuracion', fn (Blueprint $table) => $table->dropColumn(['asesor_cobra', 'requisitos_ficha']));
        DB::statement('DROP INDEX IF EXISTS cartera_asignaciones_una_vigente');
        Schema::table('cartera_asignaciones', fn (Blueprint $table) => $table->dropColumn('funcion'));
        Schema::table('cartera_asignaciones', fn (Blueprint $table) => $table->renameColumn('usuario_id', 'cobrador_id'));
    }
};
