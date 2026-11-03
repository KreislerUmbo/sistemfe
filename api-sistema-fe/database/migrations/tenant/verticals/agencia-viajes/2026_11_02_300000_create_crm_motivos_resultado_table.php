<?php

// T-052 (CRM C3, obs. 13 H51 y obs. 16): por qué se ganó o se perdió una cotización. Catálogo del
// vertical Agencia que la agencia amplía desde Configuración (nunca se borra una fila: se
// desactiva). Las filas base las inserta esta misma migración, así cubre los tenants que ya
// existen y los nuevos (mismo patrón que 2026_10_21_200000_create_salida_servicios_y_vehiculos_tables).
// «Otro» se reconoce por nombre (CrmMotivoResultado::NOMBRE_OTRO): no se renombra ni se desactiva.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BASE = [
        'perdida' => ['Precio', 'Eligió otra agencia', 'Cambió de fechas o de planes', 'No respondió', 'Otro'],
        'ganada' => ['Precio', 'Rapidez de respuesta', 'Recomendación', 'Itinerario', 'Ya era cliente', 'Otro'],
    ];

    public function up(): void
    {
        Schema::create('crm_motivos_resultado', function (Blueprint $table) {
            $table->comment('Motivos por los que se gana o se pierde una cotización (CRM).');
            $table->id();
            $table->string('tipo', 10)->comment("'ganada' | 'perdida'");
            $table->string('nombre', 80);
            $table->boolean('activo')->default(true);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();

            $table->unique(['tipo', 'nombre']);
        });
        DB::statement("ALTER TABLE crm_motivos_resultado ADD CONSTRAINT crm_motivos_resultado_tipo_check CHECK (tipo IN ('ganada', 'perdida'))");

        $filas = [];
        foreach (self::BASE as $tipo => $nombres) {
            foreach ($nombres as $i => $nombre) {
                $filas[] = ['tipo' => $tipo, 'nombre' => $nombre, 'activo' => true, 'orden' => $i + 1, 'created_at' => now(), 'updated_at' => now()];
            }
        }
        DB::table('crm_motivos_resultado')->insert($filas);
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_motivos_resultado');
    }
};
