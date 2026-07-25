<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// Login del guard 'central' (panel superadmin) — deliberadamente fuera de
// auth:central/central.token: es el endpoint que genera el token, no uno que lo consuma.
class CentralAuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (! $token = auth('central')->attempt($credentials)) {
            return response()->json(['message' => 'Credenciales inválidas.'], 401);
        }

        $user = auth('central')->user();

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('central')->factory()->getTTL() * 60,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->pluck('name'),
            ],
        ]);
    }
}
