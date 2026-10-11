<?php

// Módulo Créditos. URL del panel (admin-start-kit) de cada tenant, para los enlaces que salen
// fuera de una petición (aviso diario de cobranza, Fase 5). {tenant} = el subdominio del
// tenant (tabla domains). En local: TENANT_FRONTEND_URL=http://{tenant}.sistemafe.test:5173

return [
    'url_frontend_tenant' => env('TENANT_FRONTEND_URL', 'https://{tenant}.umbosystem.com'),
];
