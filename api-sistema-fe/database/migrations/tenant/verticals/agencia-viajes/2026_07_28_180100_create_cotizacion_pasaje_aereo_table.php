<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_180100_create_cotizacion_pasaje_aereo_table.php
//
// Sesión 11b del vertical Agencia de Viajes — plan-modulo-cotizaciones-reservas.md
// §2.5: pasaje aéreo vendido SUELTO (no como parte de un paquete
// internacional con mayorista, eso es opcion_mayorista/§2.4). 1-a-1 con
// alternativa_items (unique en alternativa_item_id) — es cotización
// puntual, no catálogo, mismo criterio que opcion_mayorista.
//
// aerolinea: texto libre, NO FK — decidido 28-jul-2026, la agencia no es
// IATA, sin relación comercial directa que reportar (mismo criterio que
// vuelo_aerolinea en opcion_mayorista/paquetes_plantilla).
//
// cargos: JSON flexible [{codigo, nombre, monto, tipo}], mismo patrón que
// proveedor_tarifas.diferenciador — espeja lo que la propia aerolínea
// entrega (TUUA nacional/internacional/transferencia, etc.), no una lista
// fija que se desactualiza.
//
// tip_afe_igv: aplica SOLO sobre fee_agencia_monto (lo único que la
// agencia vende como servicio propio) — tarifa_base + cargos son
// pass-through de costo de terceros, ver comentario en el modelo.
//
// fecha_cotizado: snapshot de cuándo se consultó — a diferencia de
// proveedor_tarifas, acá NO hay vigente_desde/vigente_hasta de largo
// plazo (un pasaje aéreo caduca en horas, no en meses).
//
// costo_total/precio_venta_total: calculados por PriceEngineService pero
// PERSISTIDOS, no recalculados al vuelo en cada request.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cotizacion_pasaje_aereo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alternativa_item_id')->unique()->constrained('alternativa_items');
            $table->string('aerolinea');
            $table->text('itinerario')->nullable();
            $table->string('moneda'); // 'PEN' | 'USD'
            $table->decimal('tarifa_base_adulto', 10, 2);
            $table->decimal('tarifa_base_nino', 10, 2)->nullable();
            $table->decimal('tarifa_base_infante', 10, 2)->nullable();
            $table->json('cargos')->nullable(); // [{codigo, nombre, monto, tipo: impuesto|tasa_aeropuerto|fee_agencia}]
            $table->boolean('tua_incluida_en_tarifa')->default(false);
            $table->decimal('fee_agencia_monto', 10, 2)->default(0);
            $table->string('tip_afe_igv', 2)->nullable(); // aplica SOLO sobre fee_agencia_monto, ver comentario del modelo
            $table->timestamp('fecha_cotizado');
            $table->decimal('costo_total', 10, 2);
            $table->decimal('precio_venta_total', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cotizacion_pasaje_aereo');
    }
};
