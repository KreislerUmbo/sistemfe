<?php
// database/migrations/2026_08_11_090300_create_amenidades_table.php
//
// Catálogo central de amenidades de proveedor (wifi, parqueo, piscina...) —
// mismo criterio que proveedor_tipos: compartido entre tenants, exclusivo
// del vertical agencia de viajes en la práctica pero sin columna `giro`
// (a diferencia de proveedor_tipos/temporadas, una amenidad no tiene
// ningún significado distinto por vertical — "wifi" es "wifi" en cualquier
// giro futuro que llegue a usar este catálogo). Sembrado con un set inicial
// razonable en la misma migración (mismo patrón que otros catálogos legales
// del proyecto, ej. tipos_comprobante).
//
// Explícito, mismo patrón que proveedor_tipos — no depende de
// DB_CONNECTION/DB_DATABASE (ver comentario en config/database.php sobre
// por qué 'central' es una clave fija).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::create('amenidades', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('icono'); // clase Font Awesome, ej. "fas fa-wifi"
            $table->string('slug')->unique();
            $table->timestamps();
        });

        $ahora = now();
        DB::connection('central')->table('amenidades')->insert(array_map(fn (array $a) => $a + ['created_at' => $ahora, 'updated_at' => $ahora], [
            ['nombre' => 'WiFi', 'icono' => 'fas fa-wifi', 'slug' => 'wifi'],
            ['nombre' => 'Parqueo', 'icono' => 'fas fa-square-parking', 'slug' => 'parqueo'],
            ['nombre' => 'Piscina', 'icono' => 'fas fa-person-swimming', 'slug' => 'piscina'],
            ['nombre' => 'Lavandería', 'icono' => 'fas fa-shirt', 'slug' => 'lavanderia'],
            ['nombre' => 'Restaurante', 'icono' => 'fas fa-utensils', 'slug' => 'restaurante'],
            ['nombre' => 'Aire acondicionado', 'icono' => 'fas fa-wind', 'slug' => 'aire-acondicionado'],
            ['nombre' => 'Desayuno incluido', 'icono' => 'fas fa-mug-saucer', 'slug' => 'desayuno-incluido'],
            ['nombre' => 'Recepción 24h', 'icono' => 'fas fa-bell-concierge', 'slug' => 'recepcion-24h'],
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('amenidades');
    }
};
