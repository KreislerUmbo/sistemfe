<?php

namespace App\Services;

use App\Exceptions\TenantProvisioningException;
use App\Models\Client\Client;
use App\Models\Company;
use App\Models\Product\Product;
use App\Models\Sale\Sale;
use App\Models\SunatConfig;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\CashConceptSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\PermissionsDemoSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Stancl\Tenancy\Database\Models\Domain;

// Extraído de app/Console/Commands/ProvisionTenant.php (plan-panel-superadmin.md, Fase A)
// para que el Command CLI y el panel HTTP (TenantAdminController) llamen a la misma
// lógica — nunca duplicada. El Command pasa a ser un wrapper delgado sobre este servicio.
class TenantProvisioningService
{
    private ?string $lastGeneratedPassword = null;

    /**
     * $data espera: ruc, razon_social, razon_social_comercial, domain, admin_name,
     * admin_email, admin_password (opcional — si se omite, se genera uno random,
     * recuperable después con getLastGeneratedPassword()).
     */
    public function provision(array $data): Tenant
    {
        $ruc = $data['ruc'];
        $razonSocial = $data['razon_social'];
        $razonSocialComercial = $data['razon_social_comercial'];
        $domain = $data['domain'];
        $adminName = $data['admin_name'];
        $adminEmail = $data['admin_email'];
        $adminPassword = $data['admin_password'] ?? null;

        $this->validarDatos($ruc, $domain);

        $this->lastGeneratedPassword = null;
        if (empty($adminPassword)) {
            $adminPassword = $this->lastGeneratedPassword = Str::random(16);
        }

        $tenant = null;

        try {
            // Tenant::create() dispara sincrónicamente CreateDatabase + MigrateDatabase
            // (TenancyServiceProvider, shouldBeQueued(false)) — al volver de este create(),
            // la base física y sus migraciones tenant ya corrieron.
            //
            // 'id' explícito = subdominio, en vez de dejar que UUIDGenerator (config
            // tenancy.id_generator) genere un UUID random: la base física se llama
            // prefix+id+suffix ("tenant" + id), así que un id legible da bases como
            // "tenantumbo" en vez de "tenant48ba1298-...", identificables a simple vista
            // en pgAdmin/psql sin tener que cruzar contra la tabla tenants.
            $tenant = Tenant::create([
                'id' => $domain,
                'ruc' => $ruc,
                'razon_social' => $razonSocial,
                'razon_social_comercial' => $razonSocialComercial,
            ]);

            $tenant->domains()->create(['domain' => $domain]);

            $tenant->run(function () use ($adminName, $adminEmail, $adminPassword) {
                (new PermissionsDemoSeeder())->run();

                // Módulo Caja — Fase 0 (plan-modulo-caja.md §3): catálogos base con
                // seed inicial, para que un tenant nuevo arranque con los métodos de
                // pago ya usados hoy y conceptos típicos de caja, ambos editables
                // después por el dueño.
                (new PaymentMethodSeeder())->run();
                (new CashConceptSeeder())->run();

                $role = Role::where('name', 'Super-Admin')->where('guard_name', 'api')->first();

                $admin = User::create([
                    'name' => $adminName,
                    'email' => $adminEmail,
                    'password' => $adminPassword,
                ]);

                $admin->assignRole($role);
            });
        } catch (\Throwable $e) {
            $this->rollback($tenant);

            throw $e;
        }

        return $tenant;
    }

    public function getLastGeneratedPassword(): ?string
    {
        return $this->lastGeneratedPassword;
    }

    /**
     * Mismas 3 reglas que ya existían en el Command, ahora compartidas por CLI y HTTP.
     * Los checks de "campo requerido" NO viven acá a propósito — cada caller los resuelve
     * con el mecanismo idiomático de su contexto (opciones de consola vs.
     * Request::validate()).
     */
    private function validarDatos(string $ruc, string $domain): void
    {
        // --domain (CLI) / domain (HTTP) espera SOLO el fragmento de subdominio (ej.
        // 'umbo'), nunca un hostname completo. InitializeTenancyBySubdomain::
        // makeSubdomain() usa explode('.', $hostname)[0] para resolver el tenant — si
        // acá se guarda un hostname completo (ej. 'umbo.umbosystem.com'), esa fila de
        // domains nunca va a matchear ninguna request real, y el tenant queda
        // inalcanzable en silencio (bug real encontrado y corregido en la migración del
        // negocio real de Umbo, Fase 2 — ver plan §1c.3n).
        if (str_contains($domain, '.')) {
            throw new TenantProvisioningException(
                "El dominio debe ser solo el fragmento de subdominio (ej. 'umbo'), no un hostname completo. Recibido: '{$domain}'."
            );
        }

        if (Tenant::findByRuc($ruc)) {
            throw new TenantProvisioningException("Ya existe un tenant con RUC/documento {$ruc}.");
        }

        if (Domain::where('domain', $domain)->exists()) {
            throw new TenantProvisioningException("El subdominio '{$domain}' ya está en uso.");
        }
    }

    /**
     * Rollback manual, no transaccional — una transacción SQL no puede abarcar la base
     * central + la base física del tenant (conexiones/sistemas distintos). Solo limpia
     * intentos de provisioning fallidos sin datos reales; no contradice la política de
     * "archivado, no borrado" de tenants ya establecidos (§11.2, §1c.3c).
     */
    private function rollback(?Tenant $tenant): void
    {
        if (! $tenant) {
            return;
        }

        $tenant = $tenant->fresh();

        if ($tenant) {
            $this->eliminarBaseFisica($tenant);
        }
    }

    /**
     * Único punto que borra de verdad — base física + domains + fila de `tenants`.
     * Dos callers: rollback() (provisioning fallido, sin condición) y
     * eliminarSiVacio() (panel, con el guard de "vacío" ya pasado antes de llegar acá).
     * Nunca se llama directo desde fuera de este archivo.
     */
    private function eliminarBaseFisica(Tenant $tenant): void
    {
        if ($tenant->database()->manager()->databaseExists($tenant->database()->getName())) {
            $tenant->database()->manager()->deleteDatabase($tenant);
        }

        $tenant->domains()->delete();
        $tenant->delete();
    }

    /**
     * Panel superadmin — botón "Eliminar" del listado de tenants (plan-panel-superadmin.md,
     * Fase D). Deliberadamente estrecho: solo borra de verdad si el tenant nunca llegó a
     * tener nada real (Company, SunatConfig/certificado, clientes, productos o ventas) —
     * pensado para "me equivoqué al crearlo" o "el cliente desistió a los minutos", NO como
     * alternativa al archivado. Un tenant con cualquier dato real de negocio debe archivarse
     * (ver archivar()), nunca eliminarse — la política "archivado, no borrado" (§11.2,
     * §1c.3c) sigue vigente para cualquier tenant que sí llegó a operar.
     */
    public function eliminarSiVacio(Tenant $tenant): void
    {
        $tieneDatos = $tenant->run(function () {
            return Company::first() !== null
                || SunatConfig::first() !== null
                || Client::count() > 0
                // Excluye 'ADELANTO-001' — producto placeholder sembrado por una
                // migración (2026_07_11_100004_seed_advance_special_product.php) en
                // TODO tenant sin excepción, no representa inventario real. Sin este
                // exclude, eliminarSiVacio() nunca dejaría borrar ningún tenant nuevo
                // (hallazgo real, encontrado probando este método antes de dar la
                // sesión por cerrada — ver plan-panel-superadmin.md, Fase D).
                || Product::where('sku', '!=', 'ADELANTO-001')->count() > 0
                || Sale::count() > 0;
        });

        if ($tieneDatos) {
            throw new TenantProvisioningException(
                "El tenant '{$tenant->id}' ya tiene datos reales (Company, SunatConfig, clientes, " .
                'productos o ventas) — no se puede eliminar. Archivalo en su lugar.'
            );
        }

        $this->eliminarBaseFisica($tenant);
    }

    /**
     * Extraído de app/Console/Commands/ArchiveTenant.php — mismo criterio que provision():
     * el Command pasa a ser un wrapper delgado sobre este servicio, nunca duplica la lógica.
     * "Archivado, no borrado" (§11.2): jamás toca la base física ni el storage, solo
     * bloquea login/API (EnsureTenantIsActive middleware) marcando status/fecha_archivado.
     */
    public function archivar(Tenant $tenant): Tenant
    {
        if ($tenant->status === 'archivado') {
            throw new TenantProvisioningException("El tenant '{$tenant->id}' ya está archivado.");
        }

        $tenant->status = 'archivado';
        $tenant->fecha_archivado = now();
        $tenant->save();

        return $tenant->fresh();
    }

    /**
     * Extraído de app/Console/Commands/RestoreTenant.php — mismo criterio que archivar().
     */
    public function restaurar(Tenant $tenant): Tenant
    {
        if ($tenant->status !== 'archivado') {
            throw new TenantProvisioningException(
                "El tenant '{$tenant->id}' no está archivado (status actual: '{$tenant->status}')."
            );
        }

        $tenant->status = 'activo';
        $tenant->fecha_archivado = null;
        $tenant->save();

        return $tenant->fresh();
    }
}
