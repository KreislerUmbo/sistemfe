<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\ProveedorTipo;
use App\Models\AgenciaViajes\ProveedorTipoConfig;
use Illuminate\Http\Request;

// Solo lectura + toggle — el catálogo en sí (proveedor_tipos, central) no
// tiene CRUD acá, es fijo (Hotel/Transporte/Mayorista/Guía, sembrado por
// ProveedorTipoSeeder). Lo único editable por tenant es si lo tiene
// habilitado (proveedor_tipos_config), plan-modulo-proveedores.md §2.6.
//
// Sesión 3 dejó proveedor_tipos_config VACÍA a propósito (sin sembrado
// automático al provisionar, ver TODO.md) — una fila ausente para un
// proveedor_tipo_id significa "deshabilitado", no "todavía sin decidir".
class ProveedorTipoConfigController extends Controller
{
    public function index(Request $request)
    {
        $tipos = ProveedorTipo::where('giro', 'agencia_viajes')
            ->where('activo', true)
            ->orderBy('nombre')
            ->get();

        $configs = ProveedorTipoConfig::whereIn('proveedor_tipo_id', $tipos->pluck('id'))
            ->get()
            ->keyBy('proveedor_tipo_id');

        return response()->json([
            'proveedor_tipos' => $tipos->map(function ($tipo) use ($configs) {
                return [
                    'id' => $tipo->id,
                    'nombre' => $tipo->nombre,
                    'slug' => $tipo->slug,
                    'habilitado' => (bool) ($configs->get($tipo->id)?->habilitado ?? false),
                ];
            })->values(),
        ]);
    }

    public function toggle(Request $request, string $id)
    {
        $tipo = ProveedorTipo::where('giro', 'agencia_viajes')->findOrFail($id);

        $config = ProveedorTipoConfig::firstOrCreate(
            ['proveedor_tipo_id' => $tipo->id],
            ['habilitado' => false]
        );

        $config->update(['habilitado' => ! $config->habilitado]);

        return response()->json([
            'code' => 200,
            'message' => $config->habilitado ? 'Tipo de proveedor habilitado' : 'Tipo de proveedor deshabilitado',
            'proveedor_tipo' => [
                'id' => $tipo->id,
                'nombre' => $tipo->nombre,
                'slug' => $tipo->slug,
                'habilitado' => (bool) $config->habilitado,
            ],
        ]);
    }
}
