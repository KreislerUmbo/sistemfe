<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddStatusToTenantsTable extends Migration
{
    // Fijo a 'central', mismo motivo que create_tenants_table: esta tabla vive en la
    // base central, no en la conexión default (puede estar apuntando a una tenant DB
    // si el DatabaseTenancyBootstrapper ya inicializó tenancy en el proceso actual).
    protected $connection = 'central';

    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // 'archivado', no borrado (plan §11.2): SUNAT exige conservar comprobantes
            // ~5 años, así que dar de baja un negocio nunca borra su base/storage, solo
            // le bloquea el acceso (ver EnsureTenantIsActive).
            $table->string('status', 20)->default('activo')->after('id');
            $table->timestamp('fecha_archivado')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['status', 'fecha_archivado']);
        });
    }
}
