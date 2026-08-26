<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_08_26_160200_create_codigo_secuencias_table.php
//
// Módulo 12 — plan-modulo-codigos-numeracion.md §6.3. Contador separado de
// configuracion_codigos para que la pantalla de ajustes no compita por el
// mismo lock que la generación de códigos en caliente
// (App\Services\AgenciaViajes\CodigoGeneradorService::generar()).
//
// Una fila por tipo con numeración propia (tour/paquete/cotizacion/
// venta_directa) — reserva NO tiene fila acá, no tiene contador propio
// (deriva del de su cotización padre, ver cotizaciones.reservas_generadas).
//
// periodo queda siempre null hoy: ningún tipo resetea su correlativo por
// periodo (tour/paquete/venta_directa no llevan periodo en el código;
// cotización lo muestra pero tampoco reinicia, sigue la cuenta como un
// número de factura — reinicio_correlativo='nunca' forzado en
// configuracion_codigos). La columna existe para un 'mensual'/'anual'
// futuro, sin uso real por ahora.
//
// El siguiente correlativo se obtiene con lockForUpdate() dentro de una
// transacción que el caller ya abrió — nunca con MAX(correlativo) sobre la
// tabla del documento, mismo mecanismo (con el mismo bug de concurrencia ya
// resuelto en su momento) que serie_comprobantes/SerieComprobanteService.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('codigo_secuencias', function (Blueprint $table) {
            $table->id();
            $table->string('tipo'); // tour | paquete | cotizacion | venta_directa
            $table->string('periodo')->nullable(); // siempre null hoy, ver comentario arriba
            $table->unsignedInteger('ultimo_correlativo')->default(0);
            $table->timestamps();

            $table->unique(['tipo', 'periodo']);
        });

        $ahora = now();

        DB::table('codigo_secuencias')->insert([
            ['tipo' => 'tour', 'periodo' => null, 'ultimo_correlativo' => 0, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['tipo' => 'paquete', 'periodo' => null, 'ultimo_correlativo' => 0, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['tipo' => 'cotizacion', 'periodo' => null, 'ultimo_correlativo' => 0, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['tipo' => 'venta_directa', 'periodo' => null, 'ultimo_correlativo' => 0, 'created_at' => $ahora, 'updated_at' => $ahora],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('codigo_secuencias');
    }
};
