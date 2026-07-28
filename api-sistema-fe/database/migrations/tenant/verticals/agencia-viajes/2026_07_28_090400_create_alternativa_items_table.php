<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_090400_create_alternativa_items_table.php
//
// Sesión 7a del vertical Agencia de Viajes — plan-modulo-cotizaciones-reservas.md
// §3.1/§3.3: ítem atómico dentro de una alternativa. proveedor_tarifa_id es
// nullable — un ítem internacional/manual no siempre viene de una tarifa
// registrada (puede venir de una opcion_mayorista, Sesión 7b).
//
// opcion_mayorista_id: nullable, SIN FK todavía — opcion_mayorista es la
// Sesión 7b (próximo prompt, misma rama). Se agrega la FK real en una
// migración retrofit propia cuando esa tabla exista, mismo patrón que el
// retrofit de paquete_plantilla_id sobre opciones_hotel en Sesión 6
// (2026_07_27_200300_add_paquete_plantilla_foreign_to_opciones_hotel_table.php).
//
// pax_incluidos: null/vacío = todos los pasajeros de la cotización por
// defecto — se resuelve en aplicación, no en BD.
//
// precio_minimo_permitido NO es columna — se calcula en aplicación (mayor
// entre proveedor_tarifas.descuento_maximo_pct y
// proveedor_tarifas.margen_minimo_pct) cuando exista el CRUD real (Sesión 11).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alternativa_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alternativa_id')->constrained('alternativas');
            $table->foreignId('proveedor_tarifa_id')->nullable()->constrained('proveedor_tarifas');
            $table->unsignedBigInteger('opcion_mayorista_id')->nullable(); // sin FK todavía, ver comentario arriba — Sesión 7b
            $table->string('modo_precio'); // 'por_persona' | 'tarifa_fija'
            $table->json('pax_incluidos')->nullable(); // array de cotizacion_pasajeros.id; null/vacío = todos
            $table->string('moneda_costo');
            $table->decimal('costo_snapshot', 10, 2);
            $table->decimal('precio_venta_snapshot', 10, 2);
            $table->decimal('descuento_pct', 5, 2)->nullable();
            $table->decimal('precio_convertido', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alternativa_items');
    }
};
