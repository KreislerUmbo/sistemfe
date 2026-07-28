<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_100100_create_opcion_mayorista_table.php
//
// Sesión 7b del vertical Agencia de Viajes — plan-modulo-cotizaciones-reservas.md
// §2.4: registro liviano por mayorista consultado, dentro de una
// alternativa — la agencia compara varios mayoristas antes de elegir uno.
//
// alternativa_id: FK real a alternativas (Sesión 7a, esta misma rama).
// proveedor_id: FK real a proveedores, tipo "mayorista".
// salida_mayorista_id: nullable, FK real a salidas_mayorista (esta misma
// sesión, migración anterior) — solo aplica cuando la opción viene de un
// paquete de catálogo con fechas fijas, no de una cotización a medida.
//
// Cuando se marca 'elegida', la opción de hotel elegida se convierte en el
// ítem internacional de la alternativa del cliente — eso vive en
// alternativa_items.opcion_mayorista_id (retrofit de esta misma sesión,
// ver 2026_07_28_100300_add_opcion_mayorista_foreign_to_alternativa_items_table.php).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opcion_mayorista', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alternativa_id')->constrained('alternativas');
            $table->foreignId('proveedor_id')->constrained('proveedores');
            $table->foreignId('salida_mayorista_id')->nullable()->constrained('salidas_mayorista');
            $table->string('moneda'); // 'PEN' | 'USD'
            $table->text('incluye')->nullable(); // checklist/texto corto: lo que va en TODAS las opciones de hotel de este mayorista
            $table->text('notas')->nullable();
            $table->string('vuelo_aerolinea')->nullable();
            $table->text('vuelo_detalle')->nullable();
            $table->string('estado')->default('candidata'); // 'candidata' | 'elegida' | 'descartada'
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opcion_mayorista');
    }
};
