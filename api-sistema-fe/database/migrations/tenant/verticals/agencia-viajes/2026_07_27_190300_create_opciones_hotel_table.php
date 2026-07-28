<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_27_190300_create_opciones_hotel_table.php
//
// Sesión 5 del vertical Agencia de Viajes — plan-modulo-proveedores.md §2.6
// / plan-modulo-cotizaciones-reservas.md §2.4.
//
// opcion_mayorista_id: SIN FK — la tabla `opcion_mayorista` es Sesión 7,
// todavía no existe. paquete_plantilla_id: SIN FK — la tabla
// `paquetes_plantilla` es Sesión 6, tampoco existe todavía. Ambas quedan
// como unsignedBigInteger nullable; la constraint real (foreignId
// ->constrained()) se agrega vía ALTER TABLE cuando esas sesiones aterricen
// — ver TODO.md.
//
// Regla de negocio "uno de los dos entre opcion_mayorista_id/
// paquete_plantilla_id, no ambos" NO se modela como CHECK constraint acá —
// se valida a nivel de aplicación cuando se construya el CRUD real
// (Sesión 6/7), mismo criterio que otras reglas de negocio del proyecto
// que no son invariantes de schema (ej. condicion_especial en ventas).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opciones_hotel', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('opcion_mayorista_id')->nullable(); // opcion_mayorista.id (Sesión 7) — sin FK todavía, tabla no existe
            $table->unsignedBigInteger('paquete_plantilla_id')->nullable(); // paquetes_plantilla.id (Sesión 6) — sin FK todavía, tabla no existe
            $table->foreignId('proveedor_id')->nullable()->constrained('proveedores');
            $table->string('nombre_hotel');
            $table->unsignedTinyInteger('categoria_estrellas')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opciones_hotel');
    }
};
