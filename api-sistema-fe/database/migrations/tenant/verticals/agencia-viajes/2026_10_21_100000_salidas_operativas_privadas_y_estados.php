<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_10_21_100000_salidas_operativas_privadas_y_estados.php
//
// Fila 13, fase 2a (plan-tours-venta-simple-costo-real.md §3.6, D6 y D9).
//
// 1. Salida privada: un tour 'privado' tiene su propia salida por reserva,
//    aunque otra reserva haga el mismo tour el mismo día.
//    privada_reserva_id = la reserva dueña (null = salida compartida). El
//    índice único (tour, fecha) pasa a valer solo para las compartidas; las
//    privadas son únicas por (tour, fecha, reserva).
// 2. Estados: 'activa' | 'cancelada' pasa a programada → confirmada →
//    realizada / cancelada. Las salidas existentes en 'activa' quedan
//    'programada' (D9). En Postgres el enum de Laravel es un CHECK.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salidas_operativas', function (Blueprint $table) {
            // La salida privada pertenece a su reserva: si la reserva se
            // borrara, su salida no tiene sentido sola.
            $table->foreignId('privada_reserva_id')->nullable()->after('tour_origen_id')
                ->constrained('reserva')->cascadeOnDelete();
        });

        DB::statement('DROP INDEX IF EXISTS salidas_operativas_tour_fecha_unique');
        DB::statement('CREATE UNIQUE INDEX salidas_operativas_tour_fecha_unique ON salidas_operativas (tour_origen_id, fecha) WHERE tour_origen_id IS NOT NULL AND privada_reserva_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX salidas_operativas_privada_unique ON salidas_operativas (tour_origen_id, fecha, privada_reserva_id) WHERE privada_reserva_id IS NOT NULL');

        DB::statement('ALTER TABLE salidas_operativas DROP CONSTRAINT IF EXISTS salidas_operativas_estado_check');
        DB::table('salidas_operativas')->where('estado', 'activa')->update(['estado' => 'programada']);
        DB::statement("ALTER TABLE salidas_operativas ADD CONSTRAINT salidas_operativas_estado_check CHECK (estado IN ('programada', 'confirmada', 'realizada', 'cancelada'))");
        DB::statement("ALTER TABLE salidas_operativas ALTER COLUMN estado SET DEFAULT 'programada'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE salidas_operativas DROP CONSTRAINT IF EXISTS salidas_operativas_estado_check');
        DB::table('salidas_operativas')->whereIn('estado', ['programada', 'confirmada', 'realizada'])->update(['estado' => 'activa']);
        DB::statement("ALTER TABLE salidas_operativas ADD CONSTRAINT salidas_operativas_estado_check CHECK (estado IN ('activa', 'cancelada'))");
        DB::statement("ALTER TABLE salidas_operativas ALTER COLUMN estado SET DEFAULT 'activa'");

        // Las salidas privadas no caben en el índice viejo (tour, fecha) único:
        // sus ítems se desenganchan y la salida se borra.
        $privadas = DB::table('salidas_operativas')->whereNotNull('privada_reserva_id')->pluck('id');
        DB::table('reserva_items')->whereIn('salida_operativa_id', $privadas)->update(['salida_operativa_id' => null]);
        DB::table('salidas_operativas')->whereIn('id', $privadas)->delete();

        DB::statement('DROP INDEX IF EXISTS salidas_operativas_privada_unique');
        DB::statement('DROP INDEX IF EXISTS salidas_operativas_tour_fecha_unique');
        DB::statement('CREATE UNIQUE INDEX salidas_operativas_tour_fecha_unique ON salidas_operativas (tour_origen_id, fecha) WHERE tour_origen_id IS NOT NULL');

        Schema::table('salidas_operativas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('privada_reserva_id');
        });
    }
};
