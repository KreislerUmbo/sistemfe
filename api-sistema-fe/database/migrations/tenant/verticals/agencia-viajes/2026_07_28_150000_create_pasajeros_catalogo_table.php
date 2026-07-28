<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_150000_create_pasajeros_catalogo_table.php
//
// Sesión 9c del vertical Agencia de Viajes (plan-hoja-de-ruta-ejecucion.md) —
// plan-modulo-cotizaciones-reservas.md §6.5: perfil reutilizable de
// pasajero, independiente de cualquier reserva puntual — evita volver a
// subir el mismo documento cada vez que esa persona viaja de nuevo. Lo
// específico de UN viaje (vuelos ida/vuelta, alimentación especial para
// ese tour) se queda en reserva_pasajeros; acá solo identidad + documento
// (este último en pasajero_documentos, migración siguiente).
//
// cliente_id: nullable, FK real a clients (core, App\Models\Client\Client)
// — si el pasajero también es un cliente registrado con cuenta propia.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pasajeros_catalogo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->nullable()->constrained('clients');
            $table->string('nombre');
            $table->string('nacionalidad')->nullable();
            $table->date('fecha_nacimiento')->nullable(); // permite derivar adulto/niño/infante automático
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pasajeros_catalogo');
    }
};
