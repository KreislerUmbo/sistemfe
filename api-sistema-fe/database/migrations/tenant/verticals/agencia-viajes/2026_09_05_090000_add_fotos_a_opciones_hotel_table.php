<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_09_05_090000_add_fotos_a_opciones_hotel_table.php
//
// Pedido del usuario (05-sep-2026): poder cargar hasta 3 fotos por hotel al
// agregarlo/editarlo en el comparador de mayoristas (Internacional). Mismo
// patrón `fotos` json ya usado en destinos_atractivos/paquetes_plantilla
// (FotoUploadService::procesarLote(), un directorio propio por entidad).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opciones_hotel', function (Blueprint $table) {
            $table->json('fotos')->nullable()->after('nombre_hotel');
        });
    }

    public function down(): void
    {
        Schema::table('opciones_hotel', function (Blueprint $table) {
            $table->dropColumn('fotos');
        });
    }
};
