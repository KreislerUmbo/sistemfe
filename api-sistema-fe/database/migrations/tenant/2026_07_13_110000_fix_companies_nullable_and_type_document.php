<?php
// Correctiva: urbanizacion/cod_local pasan a nullable — son datos SUNAT-específicos,
// un negocio que solo usa el sistema para control interno (sin facturar
// electrónicamente todavía) no los tiene. Se completan cuando el negocio configura
// su primer envío a SUNAT. Se agrega type_document (mismo patrón que
// clients/users — Catálogo 06 SUNAT), nullable, sin default: no se puede asumir
// RUC en el momento del alta, se completa junto con lo anterior.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('urbanizacion', 200)->nullable()->change();
            $table->string('cod_local', 100)->nullable()->change();
            $table->string('type_document', 15)->nullable()->after('n_document');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('urbanizacion', 200)->change();
            $table->string('cod_local', 100)->change();
            $table->dropColumn('type_document');
        });
    }
};
