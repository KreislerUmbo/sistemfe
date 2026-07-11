<?php
// database/migrations/2026_07_10_090000_create_note_series_table.php
//
// Serie a usar para cada combinación (tipo_doc de la nota, tipo_doc_afectado
// del comprobante original). Existe como tabla en vez de una constante en
// código porque el usuario va a construir más adelante un módulo de series
// personalizables para factura/boleta/notas — así el valor ya es
// configurable en BD desde ahora, sin refactor grande cuando llegue ese
// módulo (ver SerieNotaResolver, que es el único punto que lee esta tabla).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('note_series', function (Blueprint $table) {
            $table->id();

            $table->string('tipo_doc', 2);           // '07' NC, '08' ND
            $table->string('tipo_doc_afectado', 2);  // '01' factura, '03' boleta
            $table->string('serie', 10);

            $table->timestamps();

            $table->unique(['tipo_doc', 'tipo_doc_afectado']);
        });

        // ── Seed: valores por defecto acordados con el usuario ────────────
        DB::table('note_series')->insert([
            ['tipo_doc' => '07', 'tipo_doc_afectado' => '01', 'serie' => 'FC01', 'created_at' => now(), 'updated_at' => now()],
            ['tipo_doc' => '07', 'tipo_doc_afectado' => '03', 'serie' => 'BC01', 'created_at' => now(), 'updated_at' => now()],
            ['tipo_doc' => '08', 'tipo_doc_afectado' => '01', 'serie' => 'FD01', 'created_at' => now(), 'updated_at' => now()],
            ['tipo_doc' => '08', 'tipo_doc_afectado' => '03', 'serie' => 'BD01', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('note_series');
    }
};
