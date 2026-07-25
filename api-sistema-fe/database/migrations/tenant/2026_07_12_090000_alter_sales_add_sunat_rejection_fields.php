<?php
// database/migrations/2026_07_12_090000_alter_sales_add_sunat_rejection_fields.php
//
// Mismo gap que ya habíamos resuelto en notes: enviarSunat() nunca
// persistía el motivo de rechazo de SUNAT (ni tampoco una excepción de
// Greenter antes de llegar a procesarRespuestaSunat()) — solo se mostraba
// una vez en el response transitorio y se perdía. Confirmado en la
// práctica con la venta #16: quedó con correlativo reservado, sin
// xml/cdr, y sin ningún rastro de por qué falló el envío.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('sunat_error_code')->nullable()->after('hash_cpe');
            $table->text('sunat_error_message')->nullable()->after('sunat_error_code');
            $table->timestamp('sunat_sent_at')->nullable()->after('sunat_error_message');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['sunat_error_code', 'sunat_error_message', 'sunat_sent_at']);
        });
    }
};
