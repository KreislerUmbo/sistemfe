<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_08_11_090100_add_descripcion_fotos_to_proveedores_table.php
//
// Consolidación de hoteles — un proveedor ahora puede tener su propia ficha
// pública (descripción + galería), mismo patrón `fotos` json ya usado por
// destinos_atractivos/paquetes_plantilla (array de paths en el disco
// 'public', vía FotoUploadService).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->text('descripcion')->nullable()->after('nombre_comercial');
            $table->json('fotos')->nullable()->after('logo');
        });
    }

    public function down(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->dropColumn(['descripcion', 'fotos']);
        });
    }
};
