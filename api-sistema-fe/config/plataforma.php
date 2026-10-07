<?php

return [

    /*
    | Tenants "dueños de la plataforma" (07-oct-2026): los únicos que administran el
    | catálogo central compartido por todos los tenants — Portal web (sistemas y sus
    | categorías) y los recursos/manuales de capacitación. El resto de tenants solo lo
    | lee (ej. "Manual del sistema"): no ven esos menús y el API les responde 403 al
    | escribir. Antes cualquier Super-Admin de cualquier tenant podía editarlo, porque
    | Gate::before le salta los permisos.
    |
    | Lista separada por comas de ids de tenant (= subdominio, ver
    | TenantProvisioningService). En producción es 'market' (market.umbosystem.com,
    | el tenant de UmboSystem); en dev local se usa 'umbo' vía .env. Si ninguno
    | coincide, nadie puede escribir en el catálogo (falla cerrado, no abierto).
    */
    'tenants' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PLATAFORMA_TENANTS', 'market'))
    ))),

];
