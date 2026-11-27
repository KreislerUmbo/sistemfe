<?php

// T-140 (revisión) — el T.C. de una aplicación en otra moneda pasa a 6 decimales. Con 4, un pago en
// soles no siempre cancela exacto una factura en dólares: USD 1,234.56 con S/ 4,600.00 da 4,599.97
// (3.7260) o 4,600.09 (3.7261). Con 3.726027 da 4,600.00. El T.C. del pago y el de la compra siguen
// con 4: son referencias, no definen cuánto se cancela.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Ensancha sin perder nada: los valores con 4 decimales quedan iguales.
        DB::statement('alter table aplicaciones_pago_proveedor alter column tipo_cambio type numeric(12, 6)');
    }

    public function down(): void
    {
        DB::statement('alter table aplicaciones_pago_proveedor alter column tipo_cambio type numeric(10, 4)');
    }
};
