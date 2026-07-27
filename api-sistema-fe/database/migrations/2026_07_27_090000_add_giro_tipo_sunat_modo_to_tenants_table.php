<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddGiroTipoSunatModoToTenantsTable extends Migration
{
    // Fijo a 'central', mismo motivo que create_tenants_table/add_status_to_tenants_table:
    // esta tabla vive en la base central, no en la conexión default (puede estar
    // apuntando a una tenant DB si el DatabaseTenancyBootstrapper ya inicializó tenancy
    // en el proceso actual).
    protected $connection = 'central';

    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('giro')->default('retail')
                ->comment('Giro de negocio del tenant. retail = facturación electrónica
                estándar (core original, sin vertical). agencia_viajes y futuros
                verticales usan su propio valor.');
            $table->string('tipo')->default('real')
                ->comment('real | demo. Selector obligatorio en el wizard del panel
                superadmin para tenants nuevos — el default acá es solo backfill de
                tenants existentes, no implica default silencioso hacia adelante.');
            $table->string('sunat_modo')->default('pruebas')
                ->comment('pruebas | produccion. pruebas = modo beta/sandbox de
                Greenter, nunca emite comprobantes con valor legal. Default pruebas
                cubre backfill de tenants demo (regla) y de tenants reales existentes
                (decisión explícita: revisar uno por uno antes de pasar a producción).');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['giro', 'tipo', 'sunat_modo']);
        });
    }
}
