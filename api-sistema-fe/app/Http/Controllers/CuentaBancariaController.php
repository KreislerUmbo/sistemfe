<?php

namespace App\Http\Controllers;

use App\Models\Compras\PagoProveedor;
use App\Models\CuentaBancaria;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

// T-136: del sistema base (antes en AgenciaViajes), con cuentas_bancarias.gestionar.
class CuentaBancariaController extends Controller
{
    public function index()
    {
        return response()->json([
            'cuentas_bancarias' => CuentaBancaria::orderBy('orden')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'banco' => 'required|string|max:100',
            'titular' => 'required|string|max:150',
            'numero_cuenta' => 'required|string|max:50',
            'cci' => 'nullable|string|max:50',
            'alias' => 'nullable|string|max:100',
            'activo' => 'nullable|boolean',
            'orden' => 'nullable|integer',
            'moneda' => 'nullable|string|in:'.implode(',', CuentaBancaria::MONEDAS),
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $cuenta = CuentaBancaria::create($validator->validated());

        return response()->json(['code' => 200, 'message' => 'Cuenta bancaria creada', 'cuenta_bancaria' => $cuenta]);
    }

    public function update(Request $request, string $id)
    {
        $cuenta = CuentaBancaria::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'banco' => 'required|string|max:100',
            'titular' => 'required|string|max:150',
            'numero_cuenta' => 'required|string|max:50',
            'cci' => 'nullable|string|max:50',
            'alias' => 'nullable|string|max:100',
            'activo' => 'nullable|boolean',
            'orden' => 'nullable|integer',
            'moneda' => 'nullable|string|in:'.implode(',', CuentaBancaria::MONEDAS),
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        // T-136: con pagos, la moneda queda fija (ni otra ni vacía): sus pagos salieron en esa moneda.
        $datos = $validator->validated();
        if (array_key_exists('moneda', $datos) && $datos['moneda'] !== $cuenta->moneda
            && PagoProveedor::where('cuenta_bancaria_id', $cuenta->id)->exists()) {
            return response()->json([
                'code' => 422,
                'message' => 'No se puede cambiar la moneda: la cuenta ya tiene pagos a proveedores.',
            ], 422);
        }

        $cuenta->update($datos);

        return response()->json(['code' => 200, 'message' => 'Cuenta bancaria actualizada', 'cuenta_bancaria' => $cuenta]);
    }

    public function destroy(string $id)
    {
        $cuenta = CuentaBancaria::findOrFail($id);

        // T-136: sus pagos (también los anulados) dicen de qué cuenta salió el dinero.
        if (PagoProveedor::where('cuenta_bancaria_id', $cuenta->id)->exists()) {
            return response()->json([
                'code' => 422,
                'message' => 'No se puede eliminar: la cuenta tiene pagos a proveedores. Desactívala en su lugar.',
            ], 422);
        }

        $cuenta->delete();

        return response()->json(['code' => 200, 'message' => 'Cuenta bancaria eliminada']);
    }
}
