<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// T-015 — Catálogo central de aerolíneas, común a todas las agencias. Crece cuando una agencia
// agrega una que falta; el Super-Admin corrige, une y desactiva desde el panel central.
//
// La lista inicial se inserta AQUÍ y no en un seeder: deploy.sh corre los seeders al final y
// sigue aunque fallen pasos anteriores, así que un seeder podría no llegar a ejecutarse.
//
// `nombre_normalizado` (minúsculas, sin tildes ni espacios sobrantes) lleva el índice único:
// así "latam", "LATAM" y " Latam " son la misma aerolínea, "Star Peru" choca con "Star Perú", y
// si dos agencias la agregan a la vez la segunda choca con el índice en vez de duplicar.
class CreateAerolineasTable extends Migration
{
    protected $connection = 'central';

    private const INICIALES = [
        ['LATAM', 'LA'],
        ['Sky Airline', 'H2'],
        ['JetSMART', 'JA'],
        ['Star Perú', '2I'],
        ['ATSA', null],
        ['Avianca', 'AV'],
        ['Copa Airlines', 'CM'],
        ['American Airlines', 'AA'],
        ['Iberia', 'IB'],
        ['Aerolíneas Argentinas', 'AR'],
    ];

    public function up(): void
    {
        Schema::connection('central')->create('aerolineas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100);
            $table->string('nombre_normalizado', 100)->unique();
            $table->string('codigo_iata', 3)->nullable();
            $table->boolean('activa')->default(true);
            // Id del tenant que la agregó (sin FK: informativo, y el tenant puede borrarse).
            $table->string('creada_por_tenant')->nullable();
            // Al unir A en B, A queda inactiva apuntando a B.
            $table->foreignId('unida_a_id')->nullable()->constrained('aerolineas')->nullOnDelete();
            $table->timestamps();
        });

        $ahora = now();
        foreach (self::INICIALES as [$nombre, $iata]) {
            DB::connection('central')->table('aerolineas')->insert([
                'nombre' => $nombre,
                'nombre_normalizado' => self::normalizar($nombre),
                'codigo_iata' => $iata,
                'activa' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }
    }

    // Copia de Aerolinea::normalizar() a propósito: una migración no depende de un modelo que
    // puede cambiar después. Si cambia allá, la lista inicial ya migrada se recalcula aparte.
    private static function normalizar(string $nombre): string
    {
        $limpio = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $nombre)));
        $ascii = Str::ascii($limpio);

        return $ascii !== '' ? $ascii : $limpio;
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('aerolineas');
    }
}
