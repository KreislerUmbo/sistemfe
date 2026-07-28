<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_090300_create_alternativas_table.php
//
// Sesión 7a del vertical Agencia de Viajes — plan-modulo-cotizaciones-reservas.md
// §3.1/§3.2/§3.4/§3.5: cada alternativa es una combinación completa de
// paquete (no un servicio individual). Máximo 5 alternativas por cotización
// es regla de negocio (validación de aplicación en el CRUD, Sesión 11), NO
// constraint de BD.
//
// tipo_cambio_aplicado/tipo_cambio_origen: snapshot copiado de
// tipo_cambio_agencia.valor/origen al momento de cotizar (Sesión 7a, misma
// migración) — nunca recalculado después.
//
// fecha_vencimiento: = fecha_envio + configuracion_agencia.dias_vigencia_cotizacion,
// calculado en aplicación al setear fecha_envio (al pasar a estado
// 'enviada'), no columna generada.
//
// total: mantenido por aplicación, recalculado al guardar cada
// alternativa_item — no trigger de BD.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alternativas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cotizacion_id')->constrained('cotizaciones');
            $table->string('nombre'); // ej. "Alternativa A", editable
            $table->string('estado')->default('borrador'); // 'borrador' | 'enviada' | 'aceptada' | 'descartada'
            $table->string('moneda_cotizacion'); // 'PEN' | 'USD'
            $table->decimal('tipo_cambio_aplicado', 10, 4);
            $table->string('tipo_cambio_origen'); // 'dia' | 'agencia'
            $table->timestamp('fecha_envio')->nullable();
            $table->timestamp('fecha_vencimiento')->nullable();
            $table->decimal('descuento_global_pct', 5, 2)->nullable();
            $table->decimal('total', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alternativas');
    }
};
