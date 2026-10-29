<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// T-010: cada pago de una venta guarda su n.º de operación (Yape, transferencia…) y la moneda
// real en que se pagó. `amount` sigue siendo el equivalente en la moneda de la venta (R7);
// `monto_pagado` es lo que de verdad se entregó, en `moneda_pago`, a `tipo_cambio` (soles por dólar).
//
// Pagos ya guardados: se completan aquí mismo (dato del mismo tenant, sin catálogo externo) —
// moneda_pago = moneda de la venta normalizada, monto_pagado = amount, tipo_cambio = 1.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->string('nro_operacion', 50)->nullable()->comment('N.º de operación / referencia del pago');
            $table->string('moneda_pago', 3)->nullable()->comment("Moneda en que se pagó: 'PEN' | 'USD'");
            $table->decimal('monto_pagado', 12, 2)->nullable()->comment('Monto entregado, en moneda_pago');
            $table->decimal('tipo_cambio', 10, 4)->nullable()->comment('Soles por dólar; 1 si la moneda es la de la venta');
        });

        DB::statement("
            UPDATE sale_payments sp
            SET moneda_pago = CASE WHEN UPPER(TRIM(COALESCE(s.currency, ''))) IN ('USD', '$', 'US$') THEN 'USD' ELSE 'PEN' END,
                monto_pagado = sp.amount,
                tipo_cambio = 1
            FROM sales s
            WHERE s.id = sp.sale_id
        ");
        // Pagos sin venta (no debería haber): soles.
        DB::statement("UPDATE sale_payments SET moneda_pago = 'PEN', monto_pagado = amount, tipo_cambio = 1 WHERE moneda_pago IS NULL");

        DB::statement('ALTER TABLE sale_payments ALTER COLUMN moneda_pago SET NOT NULL');
        DB::statement('ALTER TABLE sale_payments ALTER COLUMN monto_pagado SET NOT NULL');
        DB::statement('ALTER TABLE sale_payments ALTER COLUMN tipo_cambio SET NOT NULL');
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropColumn(['nro_operacion', 'moneda_pago', 'monto_pagado', 'tipo_cambio']);
        });
    }
};
