<?php

namespace App\Services;

use App\Exceptions\TenantProvisioningException;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\Guia;
use App\Models\AgenciaViajes\OpcionHotel;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorTipoConfig;
use App\Models\AgenciaViajes\Servicio;
use App\Models\AgenciaViajes\TipoCambioAgencia;
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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
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
     * recuperable después con getLastGeneratedPassword()), giro/tipo (opcionales — si
     * se omiten, no se setean explícito y quedan en el default de la migración:
     * giro=retail, tipo=real. El caller HTTP (TenantAdminController) todavía no los
     * pasa — cambio 100% aditivo, no le cambia el comportamiento).
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
        $giro = $data['giro'] ?? null;
        $tipo = $data['tipo'] ?? null;

        $this->validarDatos($ruc, $domain);

        $this->lastGeneratedPassword = null;
        if (empty($adminPassword)) {
            $adminPassword = $this->lastGeneratedPassword = Str::random(16);
        }

        $tenant = null;

        try {
            // Tenant::create() dispara sincrónicamente CreateDatabase + MigrateDatabase
            // (TenancyServiceProvider, shouldBeQueued(false)) — al volver de este create(),
            // la base física y las migraciones de tenant/core/ ya corrieron (config
            // tenancy.migration_parameters --path, plan-modulo-infraestructura-multitenant.md
            // §4). Las de tenant/verticals/{giro}/ corren aparte, ver migrarVertical().
            //
            // 'id' explícito = subdominio, en vez de dejar que UUIDGenerator (config
            // tenancy.id_generator) genere un UUID random: la base física se llama
            // prefix+id+suffix ("tenant" + id), así que un id legible da bases como
            // "tenantumbo" en vez de "tenant48ba1298-...", identificables a simple vista
            // en pgAdmin/psql sin tener que cruzar contra la tabla tenants.
            $tenantAttributes = [
                'id' => $domain,
                'ruc' => $ruc,
                'razon_social' => $razonSocial,
                'razon_social_comercial' => $razonSocialComercial,
            ];
            if ($giro !== null) {
                $tenantAttributes['giro'] = $giro;
            }
            if ($tipo !== null) {
                $tenantAttributes['tipo'] = $tipo;
            }

            $tenant = Tenant::create($tenantAttributes);

            $tenant->domains()->create(['domain' => $domain]);

            $this->migrarVertical($tenant, $giro);

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
     * Corre las migraciones de tenant/verticals/{giro}/ (si existen) SOLO para el
     * tenant recién creado — plan-modulo-infraestructura-multitenant.md §4. tenant/core/
     * ya corrió automáticamente vía el evento TenantCreated (config
     * tenancy.migration_parameters), esto es el segundo paso, condicional. No-op si
     * $giro viene null (caller HTTP que todavía no lo pasa) o si la carpeta del giro
     * no existe/está vacía — 'retail' en particular nunca tiene carpeta propia, todo
     * su contenido ya es core/.
     *
     * str_replace: los valores de `giro` son snake_case (agencia_viajes, ver
     * migración add_giro_tipo_sunat_modo_to_tenants_table), pero las carpetas de
     * verticals/ son kebab-case (agencia-viajes, mismo criterio que las ramas
     * feature/sesion-N-*) — sin este mapeo, el path construido nunca existe y esto
     * queda como no-op silencioso para CUALQUIER giro con carpeta real. Bug real
     * encontrado en Sesión 2 (quedó oculto en Sesión 0 porque la carpeta estaba
     * vacía — un path que no existe y un path vacío producen el mismo resultado
     * observable: cero migraciones corridas).
     */
    private function migrarVertical(Tenant $tenant, ?string $giro): void
    {
        if (empty($giro)) {
            return;
        }

        $carpeta = str_replace('_', '-', $giro);
        $path = database_path("migrations/tenant/verticals/{$carpeta}");

        if (! File::isDirectory($path) || count(File::glob("{$path}/*.php")) === 0) {
            return;
        }

        Artisan::call('tenants:migrate', [
            '--tenants' => [$tenant->getTenantKey()],
            '--path' => [$path],
            '--realpath' => true,
            '--force' => true,
        ]);
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
     * tener nada real (Company, SunatConfig/certificado, clientes, productos, ventas, o
     * catálogos del vertical agencia_viajes) — pensado para "me equivoqué al crearlo" o
     * "el cliente desistió a los minutos", NO como alternativa al archivado. Un tenant con
     * cualquier dato real de negocio debe archivarse (ver archivar()), nunca eliminarse —
     * la política "archivado, no borrado" (§11.2, §1c.3c) sigue vigente para cualquier
     * tenant que sí llegó a operar.
     *
     * Bug real corregido 27-jul-2026 (ver TODO.md): hasta acá, este chequeo no conocía
     * ninguna de las 7 tablas del vertical agencia_viajes (proveedores, destinos_atractivos,
     * destino_servicio, servicios, guias, proveedor_tipos_config, configuracion_agencia,
     * Sesiones 2-3) — un tenant agencia_viajes con proveedores/destinos reales cargados
     * pero sin Company/cliente/producto/venta todavía se seguía considerando "vacío" y
     * era borrable de verdad, contradiciendo la política que este mismo docstring declara.
     * Recurrencia del mismo bug corregida 27-jul-2026 (rama
     * fix/eliminar-si-vacio-opciones-hotel): Sesión 5 agregó `opciones_hotel` con
     * `proveedor_id` nullable, así que no quedaba cubierta transitivamente por el chequeo
     * de Proveedor — mismo síntoma, misma causa (tabla nueva del vertical sin sumar al
     * chequeo), esta vez atacado apenas se detectó en vez de diferirlo.
     * Ver tieneDatosVerticalAgenciaViajes().
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
                || Sale::count() > 0
                || $this->tieneDatosVerticalAgenciaViajes();
        });

        if ($tieneDatos) {
            throw new TenantProvisioningException(
                "El tenant '{$tenant->id}' ya tiene datos reales (Company, SunatConfig, clientes, " .
                'productos, ventas o catálogos del vertical agencia de viajes) — no se puede eliminar. ' .
                'Archivalo en su lugar.'
            );
        }

        $this->eliminarBaseFisica($tenant);
    }

    /**
     * Las tablas del vertical agencia_viajes (Sesiones 2-7b) solo existen en tenants
     * giro=agencia_viajes — un tenant retail nunca corrió tenant/verticals/agencia-viajes/,
     * así que consultarlas directo (Modelo::count()) lanzaría "relation does not exist".
     * 'configuracion_agencia' es la primera migración de ese set en tener nombre estable
     * (Sesión 2) y siempre llega junto con el resto (mismo folder, misma corrida de
     * tenants:migrate) — sirve como gate único en vez de repetir Schema::hasTable() por
     * cada tabla nueva que se agregue a futuro.
     *
     * No todas las tablas del vertical necesitan chequeo propio acá — solo las que pueden
     * tener datos reales SIN depender (vía FK NOT NULL) de otra tabla ya chequeada, directa
     * o transitivamente:
     * - proveedor_tarifas/guia_tarifas quedan cubiertas transitivamente por sus FK
     *   obligatorias (NOT NULL) a Proveedor/Guia, que ya se chequean.
     * - opciones_hotel_tarifas queda cubierta transitivamente por su FK obligatoria a
     *   OpcionHotel (chequeada acá).
     * - opciones_hotel SÍ necesita chequeo propio: proveedor_id es nullable ahí (una
     *   opción de hotel puede cargarse sin proveedor todavía) — bug real encontrado en
     *   Sesión 5 y corregido acá, mismo patrón que el gap original de Sesión 3.
     * - paquetes_plantilla (Sesión 6) NO necesita chequeo propio pese a ser noticia nueva:
     *   destino_atractivo_id es NOT NULL, así que cualquier fila real ya implica un
     *   DestinoAtractivo real (chequeado acá) — tour_itinerario_items/paquete_plantilla_items
     *   quedan cubiertas transitivamente por su FK obligatoria a paquetes_plantilla, dos
     *   niveles arriba de DestinoAtractivo.
     * - cotizaciones (Sesión 7a) tampoco necesita chequeo propio: cliente_id es NOT NULL,
     *   así que cualquier cotización real ya implica un Client real — cubierta por
     *   Client::count() > 0 en eliminarSiVacio() (nivel superior, no acá). cotizacion_pasajeros/
     *   alternativas/alternativa_items/opcion_mayorista/opcion_mayorista_opcionales/
     *   salidas_mayorista quedan todas cubiertas transitivamente por esa misma cadena
     *   (alternativa_id/cotizacion_id/proveedor_id son NOT NULL en cada una — ver sus
     *   migraciones). Esto corrige una asunción hecha al planificar Sesión 7b (que asumía
     *   paquetes_plantilla/cotizaciones como "raíces obligadas" sin trazar su propia cadena
     *   de FK) — verificado leyendo cada migración antes de escribir este chequeo, no
     *   asumido.
     * - tipo_cambio_agencia (Sesión 7a) SÍ necesita chequeo propio: su única FK
     *   (registrado_por → users) no cuenta, porque users NUNCA se chequea en
     *   eliminarSiVacio() (todo tenant tiene al menos su admin de provisioning) — es la
     *   única tabla nueva desde Sesión 6 sin ningún ancestro ya cubierto.
     *
     * Debe llamarse DENTRO de $tenant->run() (mismo contrato que el resto de los chequeos
     * de eliminarSiVacio() — usa el connection default resuelto por DatabaseTenancyBootstrapper).
     */
    private function tieneDatosVerticalAgenciaViajes(): bool
    {
        if (! Schema::hasTable('configuracion_agencia')) {
            return false;
        }

        return DestinoAtractivo::count() > 0
            || Servicio::count() > 0
            || DestinoServicio::count() > 0
            || Guia::count() > 0
            || Proveedor::count() > 0
            || ProveedorTipoConfig::count() > 0
            || OpcionHotel::count() > 0
            || TipoCambioAgencia::count() > 0
            || $this->configuracionAgenciaFueEditada();
    }

    /**
     * configuracion_agencia SIEMPRE tiene exactamente 1 fila — la migración
     * (2026_07_27_160300_create_configuracion_agencia_table.php, Sesión 2) la inserta en
     * up() para TODO tenant agencia_viajes, sin excepción. Igual que el producto placeholder
     * 'ADELANTO-001' (ver eliminarSiVacio()), su sola existencia no puede ser la señal de
     * "tiene datos" — ningún tenant agencia_viajes sería borrable nunca, ni siquiera uno
     * recién provisionado. En vez de excluirla del todo, se compara contra los defaults
     * exactos de esa migración: si el admin la editó (cualquier campo distinto al default),
     * SÍ cuenta como dato real — significa que alguien ya empezó a configurar la agencia.
     */
    private function configuracionAgenciaFueEditada(): bool
    {
        $config = ConfiguracionAgencia::first();

        if (! $config) {
            return false;
        }

        $defaults = [
            'edad_max_infante' => 2,
            'edad_max_nino' => 12,
            'formato_descuento_pdf' => 'solo_final',
            'mostrar_descuento_como_linea' => false,
            'dias_vigencia_cotizacion' => null,
            'dias_limpieza_alternativas_descartadas' => null,
            'max_pax_reserva_con_vuelo' => 15,
            'max_pax_reserva_grupo' => 50,
            'meses_margen_vencimiento_documento' => 6,
            'dias_aviso_pago_proveedor' => 2,
            'dias_cotizacion_estancada' => 15,
        ];

        foreach ($defaults as $campo => $valorDefault) {
            if ($config->{$campo} !== $valorDefault) {
                return true;
            }
        }

        return false;
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
