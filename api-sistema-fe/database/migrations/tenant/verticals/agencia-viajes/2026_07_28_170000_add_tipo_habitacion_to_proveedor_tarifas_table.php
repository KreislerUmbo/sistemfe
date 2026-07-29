<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_170000_add_tipo_habitacion_to_proveedor_tarifas_table.php
//
// Sesión 11a del vertical Agencia de Viajes — RETROFIT sobre proveedor_tarifas
// (tabla ya mergeada en Sesión 5), confirmado en la sesión de diseño del
// 28-jul-2026 (ver TODO.md "Retrofit confirmado para Sesión 11a" y
// plan-modulo-cotizaciones-reservas.md §2.2). Se vende la habitación, no el
// hotel — el motor de precios necesita tratar "Hotel" igual sin importar si
// el ítem viene de un proveedor local (proveedor_tarifas) o de un paquete/
// mayorista (opciones_hotel_tarifas, que YA usa esta columna como campo
// real desde Sesión 5). Mismo tipo de columna (string, no enum nativo de
// Postgres) que el resto de "enums" de este vertical — string(...) con el
// comentario documentando los valores válidos.
//
// diferenciador (JSON) se queda para lo que NO tiene catálogo fijo (tipo de
// vehículo en transporte, tipo de menú en restaurante) — no se elimina la
// columna, solo se le saca la responsabilidad de tipo de habitación.
//
// Backfill: antes de esta sesión no existía ningún CRUD real de
// proveedor_tarifas (Sesiones 0-10 son solo migraciones/modelos/seeders),
// así que cualquier fila con diferenciador cargado viene de pruebas hechas
// directo por tinker/seeders en tenants descartables — no hay dato real de
// producción en juego. Se busca la clave 'tipo_habitacion' dentro del JSON
// (si existiera) y se copia a la columna nueva.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proveedor_tarifas', function (Blueprint $table) {
            $table->string('tipo_habitacion')->nullable(); // 'matrimonial' | 'doble' | 'triple' | 'familiar' — solo aplica si el proveedor padre es tipo Hotel
        });

        $filas = DB::table('proveedor_tarifas')
            ->whereNotNull('diferenciador')
            ->get(['id', 'diferenciador']);

        foreach ($filas as $fila) {
            $diferenciador = json_decode($fila->diferenciador, true);

            if (is_array($diferenciador) && ! empty($diferenciador['tipo_habitacion'])) {
                DB::table('proveedor_tarifas')
                    ->where('id', $fila->id)
                    ->update(['tipo_habitacion' => $diferenciador['tipo_habitacion']]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('proveedor_tarifas', function (Blueprint $table) {
            $table->dropColumn('tipo_habitacion');
        });
    }
};
