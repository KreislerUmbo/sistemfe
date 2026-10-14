<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Envío de la cotización por correo (plan-modulo-envio-correo-cotizacion.md §3.1).
// Interruptor por tenant que decide el Superadmin (se puede ofrecer como adicional
// al plan mensual) y tope diario de envíos, para que una agencia no consuma la
// cuota compartida de Brevo de todas las demás.
class AddCorreoCotizacionesToTenantsTable extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('correo_cotizaciones_habilitado')->default(false)
                ->comment('true = la agencia puede enviar cotizaciones por correo desde el sistema.');
            $table->unsignedInteger('correo_limite_diario')->default(50)
                ->comment('Tope de envíos por día (hora de Perú) para este tenant.');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['correo_cotizaciones_habilitado', 'correo_limite_diario']);
        });
    }
}
