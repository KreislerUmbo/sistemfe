<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_090100_create_cotizaciones_table.php
//
// Sesión 7a del vertical Agencia de Viajes — plan-modulo-cotizaciones-reservas.md
// §3.1: header de cotización, no se repite en cada alternativa.
//
// codigo: calculado {codigo_prefijo}-{año}-{correlativo}, correlativo propio
// POR PREFIJO (no global) — se resuelve en App\Models\AgenciaViajes\Cotizacion
// (evento creating() + Cotizacion::generarCodigo()), NO como columna generada
// de Postgres, tal como pide el plan. Único por tenant.
//
// cliente_id: FK real a clients (App\Models\Client\Client) — el cliente del
// core ya existente, no un cliente propio del vertical.
//
// destino: texto libre — destino "de referencia" del pedido inicial, NO es
// lo mismo que destino_atractivo_id (FK real usada en otras tablas del
// vertical, ej. paquetes_plantilla).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cotizaciones', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_prefijo');
            $table->string('codigo')->unique();
            $table->foreignId('cliente_id')->constrained('clients');
            $table->string('destino');
            $table->date('fecha_viaje_tentativa')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cotizaciones');
    }
};
