<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\CuentaBancaria;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $cuenta->update($validator->validated());

        return response()->json(['code' => 200, 'message' => 'Cuenta bancaria actualizada', 'cuenta_bancaria' => $cuenta]);
    }

    public function destroy(string $id)
    {
        CuentaBancaria::findOrFail($id)->delete();

        return response()->json(['code' => 200, 'message' => 'Cuenta bancaria eliminada']);
    }
}
