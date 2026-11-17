<?php

// T-060 (CRM C5, obs. 16, respuesta 4): preferencias del viajero, una fila por cliente. Tabla del
// vertical Agencia (retail no la tiene). tipos_viaje guarda ids de crm_tipos_viaje (json, sin FK:
// un tipo desactivado se sigue mostrando). El presupuesto es aproximado, por persona y en su moneda.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cliente_perfil_viajero', function (Blueprint $table) {
            $table->comment('Preferencias del viajero de un cliente (CRM, Agencia).');
            $table->id();
            $table->foreignId('cliente_id')->unique()->constrained('clients');
            $table->jsonb('tipos_viaje')->default(DB::raw("'[]'::jsonb"))->comment('ids de crm_tipos_viaje');
            $table->decimal('presupuesto_por_persona', 12, 2)->nullable();
            $table->string('moneda', 3)->nullable()->comment("'PEN' | 'USD'");
            $table->string('alimentacion', 150)->nullable();
            $table->string('movilidad', 150)->nullable();
            $table->string('hotel_preferido', 150)->nullable();
            $table->text('notas')->nullable();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::statement('ALTER TABLE cliente_perfil_viajero ADD CONSTRAINT cliente_perfil_viajero_presupuesto_check CHECK (presupuesto_por_persona IS NULL OR presupuesto_por_persona >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cliente_perfil_viajero');
    }
};
