<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Tipo de cambio SUNAT/SBS (TipoCambioSunatSyncService) — Decolecta es la
    // fuente primaria, requiere token Bearer (confirmado en vivo: sin token
    // devuelve 401, no es realmente opcional pese a lo que sugiere su propia
    // documentación). e-api.net.pe es el fallback, sin autenticación.
    'decolecta' => [
        'url' => env('DECOLECTA_API_URL', 'https://api.decolecta.com'),
        'token' => env('DECOLECTA_API_TOKEN'),
    ],

    'e_api' => [
        'url' => env('E_API_URL', 'https://free.e-api.net.pe'),
    ],

    // Búsqueda de DNI/RUC al registrar clientes (ClientController::searchDocument). Solo desde
    // .env: antes tenía un token por defecto escrito en el código (04c P5).
    'apisperu' => [
        'token' => env('APISPERU_TOKEN'),
    ],

    // Avisos push del navegador (Web Push, Fase 5 de Créditos). Un solo par de claves VAPID para
    // toda la plataforma; se generan con `php artisan webpush:claves`. Sin ellas el push se omite.
    'webpush' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:avisos@umbosystem.com'),
    ],

    // API de Brevo (no el SMTP): solo la usa el Superadmin para registrar y verificar el
    // dominio de correo de cada agencia (plan-modulo-envio-correo-cotizacion.md §2.1, §3.1).
    // Solo en el entorno central; nunca se expone al tenant ni al frontend.
    'brevo' => [
        'api_key' => env('BREVO_API_KEY'),
        'base_url' => env('BREVO_API_URL', 'https://api.brevo.com/v3'),
    ],

];
