<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_08_26_160100_create_configuracion_codigos_table.php
//
// Módulo 12 — plan-modulo-codigos-numeracion.md §6.2, revisión 26-ago-2026
// (§11 del plan: agrega venta_directa como quinto tipo). Una fila por tipo
// de documento — tenant (sin CentralConnection), es configuración comercial
// propia de cada agencia, no un catálogo compartido.
//
// deriva_de: null para tour/paquete/cotizacion/venta_directa (numeración
// propia). 'cotizacion' para reserva — indica que ese tipo NO usa
// codigo_secuencias, sino que reusa periodo+correlativo del documento padre
// (ver App\Services\AgenciaViajes\CodigoGeneradorService::generarParaReserva()
// y cotizaciones.reservas_generadas).
//
// El prefijo default es solo la letra del tipo (T/P/C/R/V) porque
// configuracion_agencia.sigla_comercial arranca vacía — se vuelve "TDKM" (o
// lo que corresponda) recién cuando el usuario configura la sigla desde la
// pantalla de Configuración de Agencia y guarda acá.
//
// reinicio_correlativo se fuerza a 'nunca' en el backend
// (ConfiguracionCodigosController::update()) cuando incluye_periodo=false —
// regla explícita del plan §6.2, no modelada como CHECK constraint (mismo
// criterio ya usado en otras reglas de negocio del vertical, ej.
// opciones_hotel/paquete_plantilla_id).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion_codigos', function (Blueprint $table) {
            $table->id();
            $table->string('tipo')->unique(); // tour | paquete | cotizacion | reserva | venta_directa
            $table->string('prefijo');
            $table->string('deriva_de')->nullable(); // null, o 'cotizacion' para reserva
            $table->boolean('incluye_periodo')->default(false);
            $table->string('formato_periodo')->default('MMAA'); // constante hoy, no editable desde la UI
            $table->char('separador', 1)->default('-');
            $table->unsignedSmallInteger('longitud_correlativo')->default(4);
            $table->string('reinicio_correlativo')->default('nunca'); // nunca | mensual | anual
            $table->boolean('activo')->default(true);
            $table->unsignedBigInteger('updated_by')->nullable(); // sin FK real, cross-boundary a users
            $table->timestamps();
        });

        $ahora = now();

        DB::table('configuracion_codigos')->insert([
            ['tipo' => 'tour', 'prefijo' => 'T', 'deriva_de' => null, 'incluye_periodo' => false, 'formato_periodo' => 'MMAA', 'separador' => '-', 'longitud_correlativo' => 4, 'reinicio_correlativo' => 'nunca', 'activo' => true, 'updated_by' => null, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['tipo' => 'paquete', 'prefijo' => 'P', 'deriva_de' => null, 'incluye_periodo' => false, 'formato_periodo' => 'MMAA', 'separador' => '-', 'longitud_correlativo' => 4, 'reinicio_correlativo' => 'nunca', 'activo' => true, 'updated_by' => null, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['tipo' => 'cotizacion', 'prefijo' => 'C', 'deriva_de' => null, 'incluye_periodo' => true, 'formato_periodo' => 'MMAA', 'separador' => '-', 'longitud_correlativo' => 7, 'reinicio_correlativo' => 'nunca', 'activo' => true, 'updated_by' => null, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['tipo' => 'venta_directa', 'prefijo' => 'V', 'deriva_de' => null, 'incluye_periodo' => true, 'formato_periodo' => 'MMAA', 'separador' => '-', 'longitud_correlativo' => 7, 'reinicio_correlativo' => 'nunca', 'activo' => true, 'updated_by' => null, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['tipo' => 'reserva', 'prefijo' => 'R', 'deriva_de' => 'cotizacion', 'incluye_periodo' => false, 'formato_periodo' => 'MMAA', 'separador' => '-', 'longitud_correlativo' => 4, 'reinicio_correlativo' => 'nunca', 'activo' => true, 'updated_by' => null, 'created_at' => $ahora, 'updated_at' => $ahora],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion_codigos');
    }
};
