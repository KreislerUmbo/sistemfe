<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_27_160100_create_servicios_table.php
//
// Sesión 2 del vertical Agencia de Viajes — plan-modulo-tours-catalogo.md
// §3. Catálogo reutilizable de servicios (Traslado ida y vuelta, Traslado
// aeropuerto-hotel, Hospedaje, Entrada/Boleto, etc.) — no es lo mismo que
// "tipo de proveedor": el mismo proveedor de transporte puede ofrecer
// servicios distintos hacia el mismo destino, cada uno con tarifa propia.
//
// tipo_proveedor_id referencia proveedor_tipos.id, que es CENTRAL
// (db_tenant_central, ver Sesión 1) — sin FK real de Postgres cross-DB
// (no es posible entre bases físicas distintas), mismo criterio ya usado
// en proveedor_tipos_config (plan-modulo-proveedores.md §2.6). La
// validación de que el id referenciado exista de verdad queda a nivel de
// aplicación (controller), no de base de datos — pendiente de Sesión 3+
// cuando se construya el CRUD real de este catálogo.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servicios', function (Blueprint $table) {
            $table->id();
            $table->string('nombre'); // ej. "Traslado ida y vuelta", "Hospedaje", "Entrada/Boleto"
            $table->unsignedBigInteger('tipo_proveedor_id')->nullable(); // proveedor_tipos.id (central) — sin FK real cross-DB
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servicios');
    }
};
