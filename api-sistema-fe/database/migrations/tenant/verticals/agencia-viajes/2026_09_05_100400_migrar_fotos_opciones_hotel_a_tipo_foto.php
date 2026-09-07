<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_09_05_100400_migrar_fotos_opciones_hotel_a_tipo_foto.php
//
// Mejora del PDF de cotización (plan-mejora-pdf-cotizacion-cliente.md §4.5)
// — el diseño original asumía que las fotos de hotel había que construirlas
// desde cero. Paso 0 del brief de ejecución encontró que YA se construyeron
// (commit 8cf5685, mismo día) como array PLANO de rutas
// (opciones_hotel.fotos, sin distinguir fachada/habitación) — el PDF
// necesita esa distinción (fachada primero, máx. 1; habitación después,
// máx. 2). Blast radius chico (un solo consumidor de frontend,
// HabitacionMatrixPicker.vue), así que acá SÍ se cambia la forma del array
// de string[] a {path, tipo_foto}[] — a diferencia de paquetes_plantilla.fotos
// (ver migración hermana de portada/destacadas), donde el mismo cambio
// hubiera roto 5 pantallas distintas.
//
// Sin cambio de schema (la columna ya es `json`): esto es una migración de
// DATOS, no de estructura — convierte cada entrada string suelta que
// encuentre a su forma nueva, con tipo_foto='habitacion' por defecto
// (ningún hotel real tiene más de 0-1 fotos cargadas todavía, confirmado
// por la fecha de la migración que las creó). Idempotente: una entrada que
// ya viene como array (forma nueva) se deja tal cual.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('opciones_hotel')->whereNotNull('fotos')->orderBy('id')->each(function ($hotel) {
            $fotos = json_decode($hotel->fotos, true) ?? [];

            $migradas = array_map(function ($foto) {
                if (is_array($foto)) {
                    return $foto;
                }

                return ['path' => $foto, 'tipo_foto' => 'habitacion'];
            }, $fotos);

            DB::table('opciones_hotel')->where('id', $hotel->id)->update([
                'fotos' => json_encode($migradas),
            ]);
        });
    }

    public function down(): void
    {
        DB::table('opciones_hotel')->whereNotNull('fotos')->orderBy('id')->each(function ($hotel) {
            $fotos = json_decode($hotel->fotos, true) ?? [];

            $revertidas = array_map(fn ($foto) => is_array($foto) ? ($foto['path'] ?? $foto) : $foto, $fotos);

            DB::table('opciones_hotel')->where('id', $hotel->id)->update([
                'fotos' => json_encode($revertidas),
            ]);
        });
    }
};
