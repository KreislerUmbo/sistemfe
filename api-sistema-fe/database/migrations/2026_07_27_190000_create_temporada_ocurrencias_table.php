<?php
// database/migrations/2026_07_27_190000_create_temporada_ocurrencias_table.php
//
// Sesión 5 del vertical Agencia de Viajes (plan-hoja-de-ruta-ejecucion.md) —
// plan-modulo-proveedores.md §2.6 ("Temporadas dentro de la vigencia").
// Gap real: esta tabla no tenía fila propia en la hoja de ruta pese a ser
// dependencia dura de proveedor_tarifas (temporada_id) — se suma acá.
//
// Central (db_tenant_central), no tenant: es la ocurrencia anual concreta
// del catálogo `temporadas` (central, Sesión 1) — "Fiestas Patrias 2026" es
// el mismo concepto para cualquier tenant agencia_viajes, mismo criterio que
// el catálogo padre. FK real a `temporadas` (misma base central).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Explícito, mismo patrón que las demás migraciones centrales del
    // vertical (Sesión 0/1) — no depende de DB_CONNECTION/DB_DATABASE.
    protected $connection = 'central';

    public function up(): void
    {
        Schema::create('temporada_ocurrencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temporada_id')->constrained('temporadas');
            $table->unsignedSmallInteger('anio');
            $table->date('fecha_desde');
            $table->date('fecha_hasta');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temporada_ocurrencias');
    }
};
