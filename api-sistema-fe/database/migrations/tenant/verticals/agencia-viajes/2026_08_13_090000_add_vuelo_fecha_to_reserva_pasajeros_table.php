<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sesión pendiente-reservas-mejoras — vuelo_hora_ida/vuelta ya existían
// desde Sesión 8a (create_reserva_pasajeros_table) pero sin fecha: una
// hora sin fecha no dice de qué día es, importante en viajes de varios
// días. Aclarado con el usuario: este campo es para el vuelo que el
// PASAJERO compró por su cuenta, ajeno al pasaje aéreo que vende la
// agencia (ese es alternativa_items.origen_tipo='pasaje_aereo', con su
// propia tarifa — completamente separado).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reserva_pasajeros', function (Blueprint $table) {
            $table->date('vuelo_fecha_ida')->nullable()->after('vuelo_aerolinea_ida');
            $table->date('vuelo_fecha_vuelta')->nullable()->after('vuelo_aerolinea_vuelta');
        });
    }

    public function down(): void
    {
        Schema::table('reserva_pasajeros', function (Blueprint $table) {
            $table->dropColumn(['vuelo_fecha_ida', 'vuelo_fecha_vuelta']);
        });
    }
};
