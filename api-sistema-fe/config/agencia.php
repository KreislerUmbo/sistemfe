<?php

return [
    // T-011: cobro al contado al facturar una reserva. El cobro en dólares no se despliega sin la
    // caja por moneda (T-013): producción lo deja en false hasta entonces. En false, facturar una
    // reserva se comporta como antes (la venta queda pendiente de cobro) y rechaza cualquier `pagos`.
    'cobro_al_facturar' => (bool) env('AGENCIA_COBRO_AL_FACTURAR', true),
];
