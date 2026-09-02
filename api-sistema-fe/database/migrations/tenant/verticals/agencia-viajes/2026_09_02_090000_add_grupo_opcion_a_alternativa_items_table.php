<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_09_02_090000_add_grupo_opcion_a_alternativa_items_table.php
//
// Sesión M1 — núcleo de la matriz de opciones de hotel
// (plan-matriz-hoteles-cotizador.md, diseño CERRADO 6 rondas;
// plan-ejecucion-matriz-hoteles-cotizador.md; brief
// PEGAR-EN-CLAUDE-CODE-matriz-hoteles-m1-nucleo.md).
//
// grupo_opcion_id: UUID compartido entre N filas de alternativa_items que
// son opciones intercambiables entre sí (ej. "hospedaje 4 noches en
// Cusco" con 3 hoteles candidatos) — generado por el CALLER al insertar
// las filas juntas, sin FK a ninguna tabla (no hay tabla "grupo" propia).
// En M1 ningún endpoint lo genera todavía (eso es M4, el picker con
// selección múltiple) — los tests de esta sesión lo arman a mano con
// Str::uuid().
//
// opcion_elegida: arranca siempre en false. Se marca true en EXACTAMENTE
// una fila de cada grupo para "resolverlo" (Ronda 2/P6) — sin constraint
// de Postgres que lo garantice todavía (el UUID no tiene tabla padre
// sobre la que indexar "una elegida por grupo" de forma limpia; si en el
// futuro se detecta un caso real de 2 filas opcion_elegida=true en el
// mismo grupo, evaluar agregarlo, no ahora — mismo criterio de cuándo sí
// conviene un constraint real que ya usó 12a con el índice de
// opcion_mayorista).
//
// Ítems sin grupo (el 100% de los existentes hoy): grupo_opcion_id=null,
// opcion_elegida=false — se comportan exactamente igual que antes en
// todo el sistema (test de regresión explícito en Sesion M1).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->uuid('grupo_opcion_id')->nullable()->after('opcion_mayorista_id');
            $table->boolean('opcion_elegida')->default(false)->after('grupo_opcion_id');
            $table->index('grupo_opcion_id');
        });
    }

    public function down(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->dropIndex(['grupo_opcion_id']);
            $table->dropColumn(['grupo_opcion_id', 'opcion_elegida']);
        });
    }
};
