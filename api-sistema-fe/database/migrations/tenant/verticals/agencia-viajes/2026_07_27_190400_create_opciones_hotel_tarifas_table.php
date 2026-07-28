<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_27_190400_create_opciones_hotel_tarifas_table.php
//
// Sesión 5 del vertical Agencia de Viajes — plan-modulo-cotizaciones-reservas.md
// §2.4. FK real a opciones_hotel (misma DB tenant, creada en esta misma sesión).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opciones_hotel_tarifas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opcion_hotel_id')->constrained('opciones_hotel');
            $table->string('tipo_habitacion'); // 'matrimonial' | 'doble' | 'triple' | 'familiar'
            $table->decimal('precio_costo', 10, 2);
            $table->decimal('precio_venta', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opciones_hotel_tarifas');
    }
};
