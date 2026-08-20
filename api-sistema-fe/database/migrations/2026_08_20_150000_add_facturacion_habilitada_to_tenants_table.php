<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Facturación externa por tenant + por reserva (PEGAR-EN-CLAUDE-CODE-
// facturacion-externa-tenant.md, 2026-08-20). Un tenant de giro
// agencia_viajes puede operar "solo operativo" (cotiza/reserva acá, factura
// afuera con su propio sistema SUNAT) o "paquete completo" (factura también
// acá, ReservaFacturacionController). Movible en cualquier momento en
// cualquier dirección — apagar este flag NUNCA borra ni oculta
// Sale/SaleDetail/CxC/NC ya emitidos, esos son del core y no dependen de
// esta columna.
class AddFacturacionHabilitadaToTenantsTable extends Migration
{
    // Fijo a 'central', mismo motivo que
    // add_giro_tipo_sunat_modo_to_tenants_table.
    protected $connection = 'central';

    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Nullable a propósito, SIN default — mismo criterio "sin
            // default silencioso" que tipo/sunat_modo: selector obligatorio
            // en el wizard del panel superadmin para tenants nuevos. NULL
            // en tenants existentes se trata como falsy por el gate (no
            // factura en la plataforma) hasta que el superadmin lo decida
            // explícito. Backfill real vía comando dedicado
            // (tenants:backfill-facturacion-habilitada), corrido a mano con
            // aprobación explícita — nunca automático en esta migración.
            $table->boolean('facturacion_habilitada')->nullable()
                ->comment('true = factura en esta plataforma (ReservaFacturacionController '
                    . 'habilitado). false = tenant "solo operativo", factura afuera. NULL = sin '
                    . 'decidir todavía (tratado como falsy). Editable en cualquier momento desde '
                    . 'el panel superadmin sin afectar histórico.');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('facturacion_habilitada');
        });
    }
}
