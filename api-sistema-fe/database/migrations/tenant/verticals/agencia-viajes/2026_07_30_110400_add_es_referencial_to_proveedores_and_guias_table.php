<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_30_110400_add_es_referencial_to_proveedores_and_guias_table.php
//
// Sesión 11b4 — proveedor/guía "de referencia": representa el precio de
// lista de la agencia cuando todavía no se sabe qué empresa/persona
// específica va a operar el servicio (usado, por ejemplo, como
// proveedor_servicio/guia por defecto de una proveedor_tarifa/guia_tarifa
// resuelta al explotar un tour dentro de un combo, antes de reasignar al
// operador real). Sin cambios estructurales más allá del flag — un
// proveedor/guía referencial tiene tarifas normales (mismo costo, margen,
// versión por vigencia).
//
// NOTA DE ALCANCE (documentada explícitamente, no un olvido): esta
// migración solo agrega el flag. El bloqueo duro "no se puede marcar un
// pago como realizado contra un proveedor/guía referencial" (punto 8 del
// diseño) y la extensión del reporte operativo (punto 7) NO se implementan
// en esta sesión porque no existe todavía NINGÚN controller/endpoint de
// "pago_proveedor"/"cronograma_pago_proveedor" ni un endpoint de "reporte
// operativo" en el backend real contra el cual enganchar esa lógica —
// ambas tablas (Sesión 8b/9b) son solo schema/modelo, sin CRUD ni acciones
// construidas todavía. Ver historial de plan-modulo-cotizaciones-reservas.md
// para el detalle completo de este hallazgo.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->boolean('es_referencial')->default(false)->after('estado');
        });

        Schema::table('guias', function (Blueprint $table) {
            $table->boolean('es_referencial')->default(false)->after('activo');
        });
    }

    public function down(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->dropColumn('es_referencial');
        });

        Schema::table('guias', function (Blueprint $table) {
            $table->dropColumn('es_referencial');
        });
    }
};
