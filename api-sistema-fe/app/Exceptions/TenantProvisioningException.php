<?php

namespace App\Exceptions;

// Agnóstica de transporte a propósito (ni HTTP ni consola) — tanto ProvisionTenant (CLI)
// como TenantAdminController (HTTP, Fase A) capturan este tipo y deciden cada uno cómo
// mostrar el error en su propio contexto.
class TenantProvisioningException extends \Exception
{
}
