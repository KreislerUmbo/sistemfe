<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\Modulo;
use App\Models\Central\TenantModuloOverride;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\ModulosTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// T-135 (D1) — interruptor de módulos del detalle del tenant, en el Panel Superadmin.
class TenantModuloController extends Controller
{
    public function __construct(
        private ModulosTenant $modulos,
        private AuditLogger $auditLogger,
    ) {}

    public function index(string $id)
    {
        return response()->json(['modulos' => $this->catalogo(Tenant::query()->findOrFail($id))]);
    }

    public function update(Request $request, string $id, string $codigo)
    {
        $tenant = Tenant::query()->findOrFail($id);
        $data = $request->validate([
            'habilitado' => 'required|boolean',
            'notas' => 'nullable|string|max:255',
        ]);

        $modulo = $this->catalogo($tenant)->firstWhere('codigo', $codigo);
        abort_if($modulo === null, 404, 'Ese módulo no existe para este tenant.');

        $habilitado = (bool) $data['habilitado'];
        $this->modulos->cambiar($tenant, $codigo, $habilitado, $data['notas'] ?? null);

        $this->auditLogger->log('tenant.modulo_updated', Tenant::class, $tenant->id, [
            'modulo' => $codigo,
            'valor_anterior' => $modulo['habilitado'],
            'valor_nuevo' => $habilitado,
        ]);

        return response()->json(['modulos' => $this->catalogo($tenant)]);
    }

    // Lee directo de la base central, sin la caché del tenant.
    private function catalogo(Tenant $tenant): Collection
    {
        $overrides = TenantModuloOverride::where('tenant_id', $tenant->id)->get()->keyBy('modulo_id');

        return Modulo::query()
            ->where(fn ($q) => $q->whereNull('giro')->orWhere('giro', $tenant->giro))
            ->orderBy('nombre')
            ->get()
            ->map(fn (Modulo $m) => [
                'codigo' => $m->codigo,
                'nombre' => $m->nombre,
                'descripcion' => $m->descripcion,
                'habilitado' => (bool) $overrides->get($m->id)?->habilitado,
                'notas' => $overrides->get($m->id)?->notas,
            ])
            ->values();
    }
}
