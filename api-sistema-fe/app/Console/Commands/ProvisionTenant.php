<?php

namespace App\Console\Commands;

use App\Exceptions\TenantProvisioningException;
use App\Services\TenantProvisioningService;
use Illuminate\Console\Command;

class ProvisionTenant extends Command
{
    protected $signature = 'tenants:provision
        {--ruc= : RUC o DNI del negocio (identificador para Tenant->data, mismo mecanismo que findByRuc())}
        {--razon-social= : Razón social}
        {--razon-social-comercial= : Nombre comercial}
        {--domain= : Subdominio, ej. "negocio2" para negocio2.sistemafe.test}
        {--admin-name= : Nombre del usuario admin inicial}
        {--admin-email= : Email del usuario admin inicial}
        {--admin-password= : Password del admin. Si se omite, se genera uno random y se muestra al final}
        {--giro= : Giro de negocio del tenant. Valores válidos: retail | agencia_viajes}
        {--tipo= : real | demo}';

    protected $description = 'Provisiona un tenant nuevo: Tenant + Domain + roles/permisos + usuario admin. No crea companies (paso aparte).';

    // GIROS_VALIDOS centralizado en TenantProvisioningService::GIROS_VALIDOS (antes
    // duplicado acá) — así el panel HTTP (TenantAdminController::store()/update())
    // valida contra la misma lista sin tener que mantener dos copias en sync.
    // sunat_modo no tiene flag acá a propósito
    // (plan-modulo-infraestructura-multitenant.md §4) — se resuelve solo por el
    // default de la migración ('pruebas').
    private const TIPOS_VALIDOS = ['real', 'demo'];

    public function handle(): int
    {
        $ruc = $this->option('ruc');
        $razonSocial = $this->option('razon-social');
        $razonSocialComercial = $this->option('razon-social-comercial');
        $domain = $this->option('domain');
        $adminName = $this->option('admin-name');
        $adminEmail = $this->option('admin-email');
        $adminPassword = $this->option('admin-password');
        $giro = $this->option('giro');
        $tipo = $this->option('tipo');

        foreach ([
            'ruc' => $ruc,
            'razon-social' => $razonSocial,
            'razon-social-comercial' => $razonSocialComercial,
            'domain' => $domain,
            'admin-name' => $adminName,
            'admin-email' => $adminEmail,
            'giro' => $giro,
            'tipo' => $tipo,
        ] as $option => $value) {
            if (empty($value)) {
                $this->error("Falta --{$option}.");

                return self::FAILURE;
            }
        }

        if (! in_array($giro, TenantProvisioningService::GIROS_VALIDOS, true)) {
            $this->error("--giro inválido: '{$giro}'. Valores válidos: " . implode(', ', TenantProvisioningService::GIROS_VALIDOS) . '.');

            return self::FAILURE;
        }

        if (! in_array($tipo, self::TIPOS_VALIDOS, true)) {
            $this->error("--tipo inválido: '{$tipo}'. Valores válidos: " . implode(', ', self::TIPOS_VALIDOS) . '.');

            return self::FAILURE;
        }

        $service = new TenantProvisioningService();

        try {
            $tenant = $service->provision([
                'ruc' => $ruc,
                'razon_social' => $razonSocial,
                'razon_social_comercial' => $razonSocialComercial,
                'domain' => $domain,
                'admin_name' => $adminName,
                'admin_email' => $adminEmail,
                'admin_password' => $adminPassword,
                'giro' => $giro,
                'tipo' => $tipo,
            ]);
        } catch (TenantProvisioningException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Provisioning falló, revirtiendo lo que se haya alcanzado a crear: ' . $e->getMessage());

            return self::FAILURE;
        }

        $generatedPassword = $service->getLastGeneratedPassword();

        // fresh(): sunat_modo nunca se setea explícito en provision() (queda al default
        // de la migración), así que el objeto en memoria no lo trae hasta releerlo de la
        // base — sin esto, la tabla de abajo muestra sunat_modo en blanco aunque la
        // columna real en Postgres ya quedó en 'pruebas'.
        $tenant = $tenant->fresh();

        $this->info('Tenant provisionado correctamente.');
        $this->table(['Campo', 'Valor'], [
            ['tenant_id', $tenant->id],
            ['domain', $domain . '.sistemafe.test'],
            ['giro', $tenant->giro],
            ['tipo', $tenant->tipo],
            ['sunat_modo', $tenant->sunat_modo],
            ['admin_email', $adminEmail],
            ['admin_password', $generatedPassword ? "{$generatedPassword} (generado, guardalo ahora)" : '(la que pasaste por --admin-password)'],
        ]);
        $this->warn('Pendiente: companies NO se creó — es un paso aparte, correlo antes de que este negocio pueda facturar.');

        return self::SUCCESS;
    }
}
