<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_08_26_170000_add_activo_to_proveedor_tarifas_table.php
//
// Hasta hoy no había forma de retirar una tarifa del catálogo activo sin
// borrarla — ProveedorTarifaController::destroy() bloqueaba el borrado si
// la tarifa ya estaba referenciada en alguna cotización/reserva/plantilla
// (correcto, el precio ya quedó congelado ahí) pero no ofrecía ninguna
// alternativa real.
//
// Se decidió NO reusar `vigente_hasta` para esto: esa columna ya tiene DOS
// significados propios en este código (vencimiento natural por fecha, y
// "versión cerrada" cuando ProveedorTarifaController::update() edita una
// tarifa ya usada y crea una fila nueva — ver update(), líneas ~132-161).
// Superponerle un tercer significado ("el admin la apagó a mano") haría
// imposible para cualquier reporte futuro distinguir por qué una tarifa
// dejó de estar vigente. `activo` es una señal aparte, reversible, mismo
// patrón que `paquetes_plantilla.activo`/`proveedores.estado` en este mismo
// vertical.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proveedor_tarifas', function (Blueprint $table) {
            $table->boolean('activo')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('proveedor_tarifas', function (Blueprint $table) {
            $table->dropColumn('activo');
        });
    }
};
