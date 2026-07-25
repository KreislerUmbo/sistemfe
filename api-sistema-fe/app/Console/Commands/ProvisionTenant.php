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
        {--admin-password= : Password del admin. Si se omite, se genera uno random y se muestra al final}';

    protected $description = 'Provisiona un tenant nuevo: Tenant + Domain + roles/permisos + usuario admin. No crea companies (paso aparte).';

    public function handle(): int
    {
        $ruc = $this->option('ruc');
        $razonSocial = $this->option('razon-social');
        $razonSocialComercial = $this->option('razon-social-comercial');
        $domain = $this->option('domain');
        $adminName = $this->option('admin-name');
        $adminEmail = $this->option('admin-email');
        $adminPassword = $this->option('admin-password');

        foreach ([
            'ruc' => $ruc,
            'razon-social' => $razonSocial,
            'razon-social-comercial' => $razonSocialComercial,
            'domain' => $domain,
            'admin-name' => $adminName,
            'admin-email' => $adminEmail,
        ] as $option => $value) {
            if (empty($value)) {
                $this->error("Falta --{$option}.");

                return self::FAILURE;
            }
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
            ]);
        } catch (TenantProvisioningException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Provisioning falló, revirtiendo lo que se haya alcanzado a crear: ' . $e->getMessage());

            return self::FAILURE;
        }

        $generatedPassword = $service->getLastGeneratedPassword();

        $this->info('Tenant provisionado correctamente.');
        $this->table(['Campo', 'Valor'], [
            ['tenant_id', $tenant->id],
            ['domain', $domain . '.sistemafe.test'],
            ['admin_email', $adminEmail],
            ['admin_password', $generatedPassword ? "{$generatedPassword} (generado, guardalo ahora)" : '(la que pasaste por --admin-password)'],
        ]);
        $this->warn('Pendiente: companies NO se creó — es un paso aparte, correlo antes de que este negocio pueda facturar.');

        return self::SUCCESS;
    }
}
