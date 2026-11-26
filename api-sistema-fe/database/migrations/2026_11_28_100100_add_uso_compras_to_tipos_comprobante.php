<?php

// T-135 (D2) — qué filas de la tabla 10 de SUNAT sirven para registrar compras, y la fila 91
// (comprobante del exterior), que faltaba. Quedan fuera 00, 06, 09, 11 y NV.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    private const USO_COMPRAS = ['01', '02', '03', '04', '05', '07', '08', '10', '12', '13', '14', '91'];

    public function up(): void
    {
        Schema::connection('central')->table('tipos_comprobante', function (Blueprint $table) {
            $table->boolean('uso_compras')->default(false);
        });

        DB::connection('central')->table('tipos_comprobante')->insertOrIgnore([
            'codigo' => '91',
            'nombre' => 'Comprobante de no domiciliado',
            'es_documento_sunat' => true,
            'activo_greenter' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('central')->table('tipos_comprobante')
            ->whereIn('codigo', self::USO_COMPRAS)
            ->update(['uso_compras' => true]);
    }

    public function down(): void
    {
        DB::connection('central')->table('tipos_comprobante')->where('codigo', '91')->delete();

        Schema::connection('central')->table('tipos_comprobante', function (Blueprint $table) {
            $table->dropColumn('uso_compras');
        });
    }
};
