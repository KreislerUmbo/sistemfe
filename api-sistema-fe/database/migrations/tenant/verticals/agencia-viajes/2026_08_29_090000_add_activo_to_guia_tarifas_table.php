<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_08_29_090000_add_activo_to_guia_tarifas_table.php
//
// Mismo gap que proveedor_tarifas tenía hasta el 26-ago-2026 (commit
// 5347e66): GuiaTarifaController no tenía update()/destroy() a propósito
// ("el plan solo pide GET/POST anidado bajo guía", comentario original de
// la Sesión 5) — sin forma de editar ni retirar una tarifa de guía. Se
// replica exactamente el mismo patrón ya probado con proveedor_tarifas:
// destroy() real bloqueado si la tarifa está en uso (alternativa_items/
// paquete_plantilla_items la referencian por FK), y activo/desactivar()/
// activar() como alternativa reversible.
//
// No se reusa vigente_hasta por el mismo motivo que en proveedor_tarifas:
// ya tiene su propio significado (vencimiento natural por fecha, y
// "versión cerrada" cuando update() edita una tarifa en uso y crea una
// fila nueva) — superponer un tercer significado ahí rompería cualquier
// reporte futuro que quiera distinguir por qué una tarifa dejó de estar
// vigente.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guia_tarifas', function (Blueprint $table) {
            $table->boolean('activo')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('guia_tarifas', function (Blueprint $table) {
            $table->dropColumn('activo');
        });
    }
};
