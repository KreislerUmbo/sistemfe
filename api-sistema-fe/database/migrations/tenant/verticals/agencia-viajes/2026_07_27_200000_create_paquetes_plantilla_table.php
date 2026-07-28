<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_27_200000_create_paquetes_plantilla_table.php
//
// Sesión 6 del vertical Agencia de Viajes (plan-hoja-de-ruta-ejecucion.md) —
// plan-modulo-cotizaciones-reservas.md §3.7 + plan-modulo-tours-catalogo.md
// §5 (confirmado 24-jul-2026: "tour" y paquetes_plantilla son la misma
// entidad, validado con documentos reales de la agencia — Full Day Alto
// Mayo, Tours Lamas Nativo).
//
// destino_atractivo_id: FK real a destinos_atractivos (Sesión 2) — zona o
// lugar principal del tour.
//
// precio_venta_final: actualizado 25-jul-2026 en el plan — ya no es el
// único precio real (eso vive en opciones_hotel_tarifas, misma estructura
// que opcion_mayorista de §2.4), queda como precio "desde" para listados.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paquetes_plantilla', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique()->nullable(); // prefijo libre del vendedor, ej. "PDKM-CZ"
            $table->string('categoria'); // 'local' | 'nacional' | 'internacional'
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->json('fotos')->nullable(); // mismo patrón que destinos_atractivos.fotos
            $table->foreignId('destino_atractivo_id')->constrained('destinos_atractivos');
            $table->unsignedSmallInteger('duracion_horas');
            $table->time('hora_salida')->nullable();
            $table->time('hora_retorno')->nullable();
            $table->text('lugar_recojo')->nullable();
            $table->text('no_incluye')->nullable();
            $table->text('recomendaciones')->nullable();
            $table->boolean('vuelo_incluido')->default(false);
            $table->string('vuelo_aerolinea')->nullable();
            $table->text('vuelo_detalle')->nullable();
            $table->decimal('precio_venta_final', 10, 2)->nullable(); // precio "desde" para listados, no el único precio real
            $table->date('vigencia_desde')->nullable();
            $table->date('vigencia_hasta')->nullable();
            $table->boolean('publicado_web')->default(false); // no hace nada todavía, interruptor para el futuro portal web
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paquetes_plantilla');
    }
};
