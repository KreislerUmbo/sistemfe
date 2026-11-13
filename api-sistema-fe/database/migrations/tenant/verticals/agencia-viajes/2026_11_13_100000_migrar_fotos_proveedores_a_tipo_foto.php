<?php
// T-020 — proveedores.fotos pasa de string[] a {path, tipo_foto}[] (mismo cambio que
// 2026_09_05_100400_migrar_fotos_opciones_hotel_a_tipo_foto para opciones_hotel).
//
// Sin cambio de schema (la columna ya es json): migración de DATOS. Las fotos que ya
// estaban cargadas quedan como 'otra' (nadie había marcado fachada). Idempotente: una
// entrada que ya viene como array se deja tal cual.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('proveedores')->whereNotNull('fotos')->orderBy('id')->each(function ($proveedor) {
            $fotos = json_decode($proveedor->fotos, true);
            if (! is_array($fotos) || $fotos === []) {
                return;
            }

            $migradas = array_map(
                fn ($foto) => is_array($foto) ? $foto : ['path' => $foto, 'tipo_foto' => 'otra'],
                $fotos
            );

            if ($migradas === $fotos) {
                return;
            }

            DB::table('proveedores')->where('id', $proveedor->id)->update(['fotos' => json_encode($migradas)]);
        });
    }

    // Vuelve a la lista de rutas. Pierde el tipo (la fachada deja de marcarse).
    public function down(): void
    {
        DB::table('proveedores')->whereNotNull('fotos')->orderBy('id')->each(function ($proveedor) {
            $fotos = json_decode($proveedor->fotos, true);
            if (! is_array($fotos) || $fotos === []) {
                return;
            }

            DB::table('proveedores')->where('id', $proveedor->id)->update([
                'fotos' => json_encode(array_map(fn ($foto) => is_array($foto) ? ($foto['path'] ?? null) : $foto, $fotos)),
            ]);
        });
    }
};
