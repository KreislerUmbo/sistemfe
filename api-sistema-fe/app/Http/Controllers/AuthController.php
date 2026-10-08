<?php
// api-sistema-fe/app/Http/Controllers/AuthController.php esto es para el login del admin del sistema
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\StorageUrl;


class AuthController extends Controller
{

    // register() (POST auth/register) se retiró en la auditoría de seguridad del
    // 08-oct-2026: era público y creaba un usuario del panel con token válido en
    // cualquier tenant, lo que daba lectura a ventas/clientes/productos. Los
    // usuarios se crean solo desde Usuarios (UserController::store, con permiso).

    /**
     * Get a JWT via given credentials.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function login()
    {
        $credentials = request(['email', 'password']);

        if (! $token = auth('api')->attempt($credentials)) {
            return response()->json(['error' => 'Correo o contraseña incorrectos.'], 401);
        }

        if ((int) auth('api')->user()->state === User::STATE_INACTIVO) {
            auth('api')->invalidate(true);

            return response()->json(['error' => 'Tu usuario está desactivado. Pide a un administrador que lo reactive.'], 403);
        }

        return $this->respondWithToken($token);
    }

    /**
     * Get the authenticated User.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function me()
    {
        return response()->json(auth('api')->user());
    }

    /**
     * Log the user out (Invalidate the token).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout()
    {
        auth('api')->logout();

        return response()->json(['message' => 'Successfully logged out']);
    }

    /**
     * Refresh a token.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh()
    {
        return $this->respondWithToken(auth('api')->refresh());
    }

    /**
     * Get the token array structure.
     *
     * @param  string $token
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondWithToken($token)
    {
        // getAllPermissions() mezcla los permisos del rol con los asignados
        // directo al usuario — ->role->permissions (legacy) solo traía los
        // del rol, así que un permiso directo nunca llegaba al frontend
        // (bug confirmado Módulo Caja Fase 5, 19-jul-2026).
        $permissions = auth('api')->user()->getAllPermissions()->pluck('name');

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            "user" => [
                "fullname" => auth('api')->user()->name,
                "email" => auth('api')->user()->email,
                "avatar" => StorageUrl::resolve(auth('api')->user()->avatar),
                "role" => [
                    "id" => auth('api')->user()->role->id,
                    "name" => auth('api')->user()->role->name,
                ],
                "permissions" => $permissions,
                "formato_impresion_default" => auth('api')->user()->formato_impresion_default,
            ],
        ]);
    }
}
