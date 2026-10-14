<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\TenantDominioCorreo;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\Central\BrevoDominiosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Sección "Correo" del detalle de un tenant en el Panel Superadmin
// (plan-modulo-envio-correo-cotizacion.md §3.1): interruptor de la función
// (se puede ofrecer como adicional al plan), tope diario y dominio de la
// agencia autenticado en Brevo. Cada cambio queda en central_audit_logs.
class TenantCorreoController extends Controller
{
    public function __construct(
        private BrevoDominiosService $brevo,
        private AuditLogger $auditLogger,
    ) {
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->datos($this->resolveTenant($id)));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenant = $this->resolveTenant($id);
        $data = $request->validate([
            'habilitado' => 'required|boolean',
            'limite_diario' => 'required|integer|min:1|max:1000',
        ]);

        $antes = ['habilitado' => (bool) $tenant->correo_cotizaciones_habilitado, 'limite_diario' => (int) $tenant->correo_limite_diario];
        $tenant->correo_cotizaciones_habilitado = $data['habilitado'];
        $tenant->correo_limite_diario = $data['limite_diario'];
        $tenant->save();

        $this->auditLogger->log('tenant.correo_cotizaciones_updated', Tenant::class, $tenant->id, [
            'antes' => $antes,
            'despues' => $data,
        ]);

        return response()->json($this->datos($tenant->fresh()));
    }

    public function registrarDominio(Request $request, string $id): JsonResponse
    {
        $tenant = $this->resolveTenant($id);
        $data = $request->validate([
            'dominio' => ['required', 'string', 'max:190', 'regex:/^(?!-)([a-z0-9-]{1,63}\.)+[a-z]{2,}$/i'],
        ], ['dominio.regex' => 'Escribe solo el dominio, por ejemplo agencia.com (sin @ ni https://).']);
        $dominio = strtolower($data['dominio']);

        try {
            $resultado = $this->brevo->registrar($dominio);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }

        TenantDominioCorreo::updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'dominio' => $dominio,
                'brevo_estado' => TenantDominioCorreo::ESTADO_PENDIENTE,
                'dns_records' => $resultado['dns_records'],
                'ultimo_error' => null,
                'verificado_en' => null,
                'ultimo_chequeo_en' => now(),
            ],
        );

        $this->auditLogger->log('tenant.correo_dominio_registrado', Tenant::class, $tenant->id, ['dominio' => $dominio]);

        return response()->json($this->datos($tenant));
    }

    public function verificarDominio(string $id): JsonResponse
    {
        $tenant = $this->resolveTenant($id);
        $registro = TenantDominioCorreo::where('tenant_id', $tenant->id)->first();
        if (! $registro) {
            throw new HttpException(422, 'Primero registra el dominio.');
        }

        try {
            $resultado = $this->brevo->autenticar($registro->dominio);
        } catch (RuntimeException $e) {
            $registro->update(['ultimo_error' => $e->getMessage(), 'ultimo_chequeo_en' => now()]);
            throw new HttpException(422, $e->getMessage());
        }

        $estadoAnterior = $registro->brevo_estado;
        $registro->update([
            'brevo_estado' => $resultado['autenticado'] ? TenantDominioCorreo::ESTADO_VERIFICADO : TenantDominioCorreo::ESTADO_FALLIDO,
            'dns_records' => $resultado['dns_records'] ?: $registro->dns_records,
            'ultimo_error' => $resultado['autenticado'] ? null : ($resultado['mensaje'] ?? 'Brevo todavía no encuentra los registros DNS.'),
            'verificado_en' => $resultado['autenticado'] ? ($registro->verificado_en ?? now()) : null,
            'ultimo_chequeo_en' => now(),
        ]);

        if ($estadoAnterior !== $registro->brevo_estado) {
            $this->auditLogger->log('tenant.correo_dominio_verificacion', Tenant::class, $tenant->id, [
                'dominio' => $registro->dominio,
                'estado' => $registro->brevo_estado,
            ]);
        }

        return response()->json($this->datos($tenant));
    }

    public function eliminarDominio(string $id): JsonResponse
    {
        $tenant = $this->resolveTenant($id);
        $registro = TenantDominioCorreo::where('tenant_id', $tenant->id)->first();
        if (! $registro) {
            return response()->json($this->datos($tenant));
        }

        try {
            $this->brevo->eliminar($registro->dominio);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }

        $registro->delete();
        $this->auditLogger->log('tenant.correo_dominio_eliminado', Tenant::class, $tenant->id, ['dominio' => $registro->dominio]);

        return response()->json($this->datos($tenant));
    }

    private function datos(Tenant $tenant): array
    {
        $registro = TenantDominioCorreo::where('tenant_id', $tenant->id)->first();

        return [
            'habilitado' => (bool) $tenant->correo_cotizaciones_habilitado,
            'limite_diario' => (int) ($tenant->correo_limite_diario ?? 50),
            'brevo_configurado' => $this->brevo->configurado(),
            'respaldo' => (string) config('mail.cotizaciones_respaldo'),
            'dominio' => $registro ? [
                'dominio' => $registro->dominio,
                'estado' => $registro->brevo_estado,
                'registros' => BrevoDominiosService::registrosDns($registro->dns_records),
                'ultimo_error' => $registro->ultimo_error,
                'verificado_en' => $registro->verificado_en,
                'ultimo_chequeo_en' => $registro->ultimo_chequeo_en,
            ] : null,
        ];
    }

    private function resolveTenant(string $id): Tenant
    {
        $tenant = Tenant::find($id);
        if (! $tenant) {
            throw new HttpException(404, 'Tenant no encontrado.');
        }

        return $tenant;
    }
}
