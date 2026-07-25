<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Greenter\GreenterService;
use App\Models\Company;
use App\Models\SunatConfig;
use App\Models\Tenant;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Panel superadmin (plan-panel-superadmin.md, Fase B.3) — completa Company + SunatConfig
// (incluido certificado) de un tenant específico desde el panel central. Todo lo que lee/
// escribe datos o archivos del tenant corre DENTRO de $tenant->run() (mismo mecanismo que
// TenantProvisioningService, Fase A) — el panel vive fuera de la resolución de tenancy.
//
// Ninguna validación de negocio lanza excepciones DENTRO de $tenant->run(): TenantRun::run()
// (vendor/stancl/tenancy) NO envuelve el callback en try/finally — si el callback lanza,
// tenancy() nunca revierte al contexto original y la app queda "pegada" al tenant
// equivocado por el resto del request. Cada closure de acá devuelve ['error' => '...'] en
// vez de throw; el controller relanza como HttpException DESPUÉS de que run() ya retornó
// (tenancy ya revertida en ese punto). Por el mismo motivo, la serialización de SunatConfig
// (que consulta Storage::disk('private'), sensible al tenant activo) siempre se arma DENTRO
// del closure — armarla afuera leería la carpeta central, no la del tenant.
class TenantSunatController extends Controller
{
    public function __construct(private AuditLogger $auditLogger, private GreenterService $greenterService)
    {
    }

    public function company(Request $request, string $id)
    {
        $tenant = $this->resolveTenant($id);

        $data = $request->validate([
            'razon_social' => 'required|string|max:250',
            'razon_social_comercial' => 'required|string|max:250',
            'n_document' => 'required|string|max:50',
            'urbanizacion' => 'required|string|max:200',
            'cod_local' => 'required|string|max:100',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:50',
            'birth_date' => 'nullable|date',
            'address' => 'nullable|string|max:200',
            'ubigeo_distrito' => 'nullable|string|max:25',
            'ubigeo_provincia' => 'nullable|string|max:25',
            'ubigeo_region' => 'nullable|string|max:25',
            'distrito' => 'nullable|string|max:80',
            'provincia' => 'nullable|string|max:80',
            'region' => 'nullable|string|max:80',
        ]);

        // Upsert por Company::first() — mismo ancla que el CompanyController de tenant
        // (Client/CompanyController) ya usa. La tabla no tiene unique index; esto es lo
        // único que impide una segunda fila.
        //
        // ->toArray() DENTRO del closure a propósito: un modelo Eloquent devuelto crudo
        // fuera de $tenant->run() revienta al serializarlo (json_encode() dispara
        // getDateFormat()->getConnection() de forma perezosa, y para entonces la
        // conexión 'tenant' ya no existe — tenancy ya revirtió). Mismo motivo por el que
        // serializeSunatConfig() ya se arma adentro en los otros 3 métodos.
        $company = $tenant->run(function () use ($data) {
            $company = Company::first();

            if ($company) {
                $company->update($data);
            } else {
                $company = Company::create($data);
            }

            return $company->toArray();
        });

        $this->auditLogger->log('tenant.company.updated', Tenant::class, $tenant->id, $data);

        return response()->json(['company' => $company]);
    }

    // Fase D, Paso 2 (plan-panel-superadmin.md) — GET de solo lectura, mismo patrón que
    // sunatConfigShow() de abajo. Sin esto, el tab "Company" del panel no tenía forma de
    // saber si un tenant ya tenía datos guardados antes de mostrar el formulario — company()
    // arriba es upsert-only (POST), nunca expuso un GET.
    public function companyShow(string $id)
    {
        $tenant = $this->resolveTenant($id);

        $company = $tenant->run(function () {
            $company = Company::first();

            return $company ? $company->toArray() : null;
        });

        return response()->json(['company' => $company]);
    }

    public function sunatConfigShow(string $id)
    {
        $tenant = $this->resolveTenant($id);

        $sunatConfig = $tenant->run(function () {
            $company = Company::first();
            $config = $company ? SunatConfig::where('company_id', $company->id)->first() : null;

            return $config ? $this->serializeSunatConfig($config) : null;
        });

        return response()->json(['sunat_config' => $sunatConfig]);
    }

    public function sunatConfigStore(Request $request, string $id)
    {
        $tenant = $this->resolveTenant($id);

        $data = $request->validate([
            'ruc' => 'nullable|string|max:20',
            'razon_social_sunat' => 'nullable|string|max:250',
            'modo' => 'required|in:beta,produccion',
            'sol_usuario' => 'required|string',
            'sol_clave' => 'required|string',
            'cuenta_bancaria_detraccion' => 'nullable|string|max:50',
        ]);

        $resultado = $tenant->run(function () use ($data) {
            $company = Company::first();

            if (! $company) {
                return ['error' => 'Este tenant no tiene Company configurada todavía — completá ese paso primero.'];
            }

            $existing = SunatConfig::where('company_id', $company->id)->first();

            // Gate: no permitir activar produccion sin certificado ya válido — el
            // certificado se sube en un endpoint aparte, así que solo puede existir
            // sobre una fila que ya estaba ahí.
            if ($data['modo'] === 'produccion' && (! $existing || ! $existing->certificadoEsValido())) {
                return [
                    'error' => 'No se puede activar modo=produccion sin un certificado propio ' .
                        'válido ya cargado — subilo primero con POST .../sunat-config/certificado.',
                ];
            }

            $payload = array_merge($data, ['company_id' => $company->id]);

            if ($existing) {
                $existing->update($payload);
                $config = $existing;
            } else {
                $config = SunatConfig::create($payload);
            }

            return ['sunat_config' => $this->serializeSunatConfig($config)];
        });

        if (isset($resultado['error'])) {
            throw new HttpException(422, $resultado['error']);
        }

        $this->auditLogger->log('tenant.sunat_config.updated', Tenant::class, $tenant->id, [
            'modo' => $data['modo'],
        ]);

        return response()->json($resultado);
    }

    public function sunatConfigCertificado(Request $request, string $id)
    {
        $tenant = $this->resolveTenant($id);

        $request->validate([
            'certificado' => 'required|file|max:5120', // 5MB
            'certificado_password' => 'required|string',
        ]);

        $file = $request->file('certificado');

        if (strtolower($file->getClientOriginalExtension()) !== 'pfx') {
            throw new HttpException(422, 'El certificado debe ser un archivo .pfx.');
        }

        // Conversión .pfx → PEM acá, fuera del contexto de tenant (no necesita BD ni
        // storage todavía) — SignedXml::setCertificate() (Greenter) espera PEM sin
        // cifrar, nunca un .pfx crudo. certificado_password se guarda igual (encrypted)
        // por trazabilidad, pero ya no hace falta para firmar: el PEM resultante queda
        // desencriptado en disco.
        $password = $request->input('certificado_password');
        $pfxContent = file_get_contents($file->getRealPath());

        if (! openssl_pkcs12_read($pfxContent, $certs, $password)) {
            throw new HttpException(422, 'No se pudo leer el certificado .pfx — verificá que el password sea correcto.');
        }

        $pem = $certs['cert'] . $certs['pkey'];
        $datosX509 = openssl_x509_parse($certs['cert']);

        if (! $datosX509) {
            throw new HttpException(422, 'No se pudo leer la información del certificado.');
        }

        // Fecha real del propio certificado — no se le pide al usuario que la tipee
        // aparte, evita que un formulario desincronizado del archivo real.
        $fechaVencimiento = date('Y-m-d', $datosX509['validTo_time_t']);

        $resultado = $tenant->run(function () use ($pem, $password, $fechaVencimiento) {
            $company = Company::first();

            if (! $company) {
                return ['error' => 'Este tenant no tiene Company configurada todavía — completá ese paso primero.'];
            }

            $config = SunatConfig::where('company_id', $company->id)->first();

            if (! $config) {
                return [
                    'error' => 'Este tenant no tiene SunatConfig todavía — completá ese paso ' .
                        'primero (POST .../sunat-config).',
                ];
            }

            $pathAnterior = $config->certificado_path;
            $path = 'sunat/certificado-' . now()->timestamp . '.pem';

            // Guardar el nuevo ANTES de tocar la fila o borrar el viejo — nunca dejar
            // certificado_path apuntando a algo que no se terminó de escribir.
            Storage::disk('private')->put($path, $pem);

            $config->update([
                'certificado_path' => $path,
                'certificado_password' => $password,
                'certificado_fecha_vencimiento' => $fechaVencimiento,
            ]);

            if ($pathAnterior && $pathAnterior !== $path && Storage::disk('private')->exists($pathAnterior)) {
                Storage::disk('private')->delete($pathAnterior);
            }

            return ['sunat_config' => $this->serializeSunatConfig($config)];
        });

        if (isset($resultado['error'])) {
            throw new HttpException(422, $resultado['error']);
        }

        $this->auditLogger->log('tenant.certificado.uploaded', Tenant::class, $tenant->id, [
            'certificado_fecha_vencimiento' => $fechaVencimiento,
        ]);

        return response()->json($resultado);
    }

    // Fase E, Paso 2 (plan-panel-superadmin.md) — botón/endpoint informativo: confirma
    // que el tenant puede emitir (Company, SunatConfig activo, certificado — demo en
    // beta, propio y vigente en producción) SIN quemar ningún correlativo ni tocar
    // 'sales'/'serie_comprobantes'/SUNAT en absoluto. Reusa
    // GreenterService::verificarListoParaEmitir() (extraído en esta misma sesión, Paso
    // 2 — el mismo guard que enviarSunat() ya usa antes de reservarCorrelativo() desde
    // el Paso 1), nunca duplicado acá.
    //
    // Punto 4 del diseño original (conectividad real contra el WSDL de SUNAT sin
    // emitir): investigado, NO implementado — ver nota extensa en
    // plan-panel-superadmin.md, Fase E, Paso 2, sección "Alcance real". Resumen: Greenter
    // sí expone una operación de solo-consulta (Ws\Services\ConsultCdrService::
    // getStatus(), consulta un comprobante YA emitido por ruc/tipo/serie/número — no
    // emite nada), pero (a) la librería solo trae la URL de este servicio para
    // producción (SunatEndpoints::FE_CONSULTA_CDR), sin equivalente beta, y (b)
    // ejercitarlo con sentido exige una interpretación cuidadosa de los códigos de
    // fault SOAP que devuelve SUNAT para distinguir "credenciales inválidas" de
    // "documento no existe" — un error de esa interpretación podría reportar un falso
    // "no puede emitir" (o peor, un falso "sí puede") en un panel que un superadmin va a
    // tomar como fuente de verdad. Se deja fuera de esta sesión a propósito, documentado
    // como limitación conocida, no como olvido.
    //
    // Mismo patrón de closure que company()/sunatConfigStore()/sunatConfigCertificado()
    // de arriba: ninguna excepción escapa DENTRO de $tenant->run() (ver comentario de
    // clase) — se captura adentro y se relanza DESPUÉS de que run() ya retornó.
    public function testEmission(string $id)
    {
        $tenant = $this->resolveTenant($id);

        $resultado = $tenant->run(function () {
            $company = Company::first();

            try {
                $detalle = $this->greenterService->verificarListoParaEmitir($company);
            } catch (HttpException $e) {
                return ['error' => $e->getMessage()];
            }

            return ['detalle' => $detalle];
        });

        $exitoso = ! isset($resultado['error']);

        // Auditar SIEMPRE, éxito o fallo — es una consulta sobre datos fiscales de un
        // tenant, entra en el mismo criterio ya establecido de "toda acción sensible
        // queda auditada" (ver company()/sunatConfigStore() arriba).
        $this->auditLogger->log('tenant.test_emission', Tenant::class, $tenant->id, [
            'resultado' => $exitoso ? 'ok' : 'error',
            'motivo' => $resultado['error'] ?? null,
            'modo' => $resultado['detalle']['modo'] ?? null,
        ]);

        if (! $exitoso) {
            throw new HttpException(422, $resultado['error']);
        }

        return response()->json(['test_emission' => $resultado['detalle']]);
    }

    private function resolveTenant(string $id): Tenant
    {
        $tenant = Tenant::find($id);

        if (! $tenant) {
            throw new HttpException(404, 'Tenant no encontrado.');
        }

        return $tenant;
    }

    // Nunca expone sol_clave/certificado_password (encrypted en BD, pero tampoco hace
    // falta mandarlas de vuelta al frontend). certificado_path tampoco se expone —
    // "certificado_cargado"/"certificado_valido" alcanzan para lo que el panel necesita
    // mostrar.
    private function serializeSunatConfig(SunatConfig $config): array
    {
        return [
            'id' => $config->id,
            'ruc' => $config->ruc,
            'razon_social_sunat' => $config->razon_social_sunat,
            'modo' => $config->modo,
            'sol_usuario' => $config->sol_usuario,
            'cuenta_bancaria_detraccion' => $config->cuenta_bancaria_detraccion,
            'certificado_cargado' => $config->tieneCertificadoPropio(),
            'certificado_valido' => $config->certificadoEsValido(),
            'certificado_fecha_vencimiento' => $config->certificado_fecha_vencimiento,
            'activo' => $config->activo,
        ];
    }
}
