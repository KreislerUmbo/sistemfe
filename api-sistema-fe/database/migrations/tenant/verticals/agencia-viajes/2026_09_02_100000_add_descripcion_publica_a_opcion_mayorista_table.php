<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_09_02_100000_add_descripcion_publica_a_opcion_mayorista_table.php
//
// Fix C1 — el PDF comercial revelaba la razón social del mayorista al
// cliente (PEGAR-EN-CLAUDE-CODE-fix-leak-mayorista-pdf.md,
// auditoria-arquitectonica-agencia-viajes.md §9.3).
// AlternativaController::resolverNombreItemPdf() caía a
// opcionMayorista->proveedor->razon_social cuando no había mejor dato —
// imprimía el dato fiscal SUNAT del mayorista directo en el documento
// que recibe el cliente. descripcion_publica es el texto que el
// vendedor SÍ quiere que el cliente vea (ej. "Paquete Panamá 6D/5N"),
// sin ningún camino de vuelta al Proveedor real.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opcion_mayorista', function (Blueprint $table) {
            $table->string('descripcion_publica')->nullable()->after('incluye');
        });
    }

    public function down(): void
    {
        Schema::table('opcion_mayorista', function (Blueprint $table) {
            $table->dropColumn('descripcion_publica');
        });
    }
};
