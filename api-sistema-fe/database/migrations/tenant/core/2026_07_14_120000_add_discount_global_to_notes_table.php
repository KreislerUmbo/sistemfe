<?php
// database/migrations/tenant/2026_07_14_120000_add_discount_global_to_notes_table.php
//
// NC04 (Descuento global, catálogo 09) clona todas las líneas de la venta
// tal cual (igual que una nota total) pero aplica un descuento único sobre
// el conjunto. A diferencia de una nota parcial normal, ese descuento no
// vive en ninguna note_detail — hay que persistirlo a nivel de la nota para
// poder recalcular los mismos totales de forma consistente en
// enviarNotaSunat() (que relee note_details desde la BD) y para que
// GreenterService::getNote() arme las líneas del XML netas del descuento,
// igual que ya hace FacturacionElectronicaController con
// sales.discount_global — ver plan, Fase 3.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->decimal('discount_global', 12, 2)->default(0)->after('mto_imp_venta');
        });
    }

    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->dropColumn('discount_global');
        });
    }
};
