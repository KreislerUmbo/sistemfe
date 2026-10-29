<?php

return [
    // T-011: cobro al contado al facturar una reserva. El cobro en dólares no se despliega sin la
    // caja por moneda (T-013). En false, facturar una reserva se comporta como antes (la venta queda
    // pendiente de cobro) y rechaza cualquier `pagos`. Apagado por defecto: un servidor sin la
    // variable (producción hoy) no lo enciende solo al desplegar. Se enciende en local (.env) y en
    // los tests (phpunit.xml).
    'cobro_al_facturar' => (bool) env('AGENCIA_COBRO_AL_FACTURAR', false),
];
