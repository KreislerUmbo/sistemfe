<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\ProveedorTipo;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Panel superadmin — CRUD del catálogo central `proveedor_tipos` (compartido por todos
// los tenants del giro agencia_viajes). Hasta esta sesión no tenía CRUD (fijo, sembrado
// por ProveedorTipoSeeder) — ProveedorTipoConfigController (tenant) solo puede
// habilitar/deshabilitar cuáles usa CADA tenant, nunca crear/editar el catálogo en sí.
//
// slug NUNCA se acepta del payload — se deriva de `nombre` una sola vez, al crear, y
// queda inmutable para siempre (ProveedorTarifaController y otros puntos del código
// tienen lógica de negocio atada a slugs fijos como 'hotel'; permitir que cambie
// rompería esas reglas en silencio para cualquier tenant que ya lo esté usando).
//
// Sin borrado real (mismo criterio que TenantPlanController/PaymentMethodController):
// destroy() desactiva, nunca borra la fila — proveedor_tipos no tiene FK real hacia
// Proveedor.tipo_id (cross-boundary, sin FK de Postgres entre tenant y central), así que
// no hay forma barata de confirmar "nadie lo está usando" antes de borrar de verdad.
class ProveedorTipoController extends Controller
{
    private const GIRO = 'agencia_viajes';

    public function __construct(private AuditLogger $auditLogger)
    {
    }

    public function index()
    {
        $tipos = ProveedorTipo::where('giro', self::GIRO)
            ->orderBy('nombre')
            ->get();

        return response()->json(['proveedor_tipos' => $tipos]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre' => [
                'required', 'string', 'max:255',
                Rule::unique('central.proveedor_tipos', 'nombre')->where('giro', self::GIRO),
            ],
            'activo' => 'boolean',
        ]);

        $tipo = ProveedorTipo::create([
            'nombre' => $data['nombre'],
            'slug' => Str::slug($data['nombre']),
            'giro' => self::GIRO,
            'activo' => $data['activo'] ?? true,
        ]);

        $this->auditLogger->log('proveedor_tipo.created', ProveedorTipo::class, (string) $tipo->id, $data);

        return response()->json(['proveedor_tipo' => $tipo], 201);
    }

    // Solo `nombre` (etiqueta) y `activo` son editables — slug queda fijo desde la
    // creación (ver comentario de clase).
    public function update(Request $request, string $id)
    {
        $tipo = $this->resolveTipo($id);

        $data = $request->validate([
            'nombre' => [
                'required', 'string', 'max:255',
                Rule::unique('central.proveedor_tipos', 'nombre')->where('giro', self::GIRO)->ignore($tipo->id),
            ],
            'activo' => 'boolean',
        ]);

        $tipo->update([
            'nombre' => $data['nombre'],
            'activo' => $data['activo'] ?? $tipo->activo,
        ]);

        $this->auditLogger->log('proveedor_tipo.updated', ProveedorTipo::class, (string) $tipo->id, $data);

        return response()->json(['proveedor_tipo' => $tipo]);
    }

    public function destroy(string $id)
    {
        $tipo = $this->resolveTipo($id);
        $tipo->update(['activo' => false]);

        $this->auditLogger->log('proveedor_tipo.deactivated', ProveedorTipo::class, (string) $tipo->id, []);

        return response()->json(['proveedor_tipo' => $tipo]);
    }

    private function resolveTipo(string $id): ProveedorTipo
    {
        $tipo = ProveedorTipo::where('giro', self::GIRO)->find($id);

        if (! $tipo) {
            throw new HttpException(404, 'Tipo de proveedor no encontrado.');
        }

        return $tipo;
    }
}
