<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use App\Models\Client\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Role;


class AuthController extends Controller
{

    /**
     * Register a User.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        $validator = Validator::make(request()->all(), [
            'name' => 'required',
            'email' => 'required|email|unique:clients,email',
            'password' => 'required|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors()->toJson(), 400);
        }

/*         $client = Client::where('email', $request->email)->first();
        if ($client) {
            return response()->json([
                'error' => 'Este Cliente ya esta registrado, debe iniciar sesion'
            ], 401);
        } */

        // Buscar el rol "cliente" (puedes obtenerlo por nombre o por ID fijo)
        $clienteRole = Role::where('name', 'Cliente')->first();
        if (!$clienteRole) {
            // Si no existe, podrías crearlo automáticamente (recomendado hacerlo en un seeder)
            $clienteRole = Role::create(['name' => 'Cliente']);
        }

        $client = Client::create([
            'name' => request()->name,
            'email' => request()->email,
            'password' => bcrypt(request()->password),
            'role_id' => $clienteRole->id,
            'user_id' => auth('api')->check()
                ? auth('api')->id()
                : null, // será null si no está logueado
        ]);
        // Opcional: generar token automáticamente después del registro
        $token = JWTAuth::fromUser($client);
        return $this->respondWithToken($token);
    }
  
  /*       $user = new User;
        $user->name = request()->name;
        $user->email = request()->email;
        $user->password = bcrypt(request()->password);
        $user->save();
  
        return response()->json($user, 201);
    } */


    /**
     * Get a JWT via given credentials.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        $client = Client::where('email', $request->email)->first();

        if (!$client) {
            return response()->json([
                'error' => 'Credenciales inválidas'
            ], 401);
        }

        if (!Hash::check($request->password, $client->password)) {
            return response()->json([
                'error' => 'Credenciales inválidas'
            ], 401);
        }

        $token = JWTAuth::fromUser($client);

        return response()->json([
            'access_token' => $token,
            'client' => $client
        ]);
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
        // auth('api')->logout();

        return response()->json(['message' => 'Successfully logged out']);
    }

    /**
     * Refresh a token.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh()//esta funcion deberia ser para
    {
       // return $this->respondWithToken(auth('api')->refresh());
        return $this->respondWithToken(JWTAuth::refresh());
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
        $role = auth('api')->user()->role;
        $permissions = $role->permissions->pluck('name');

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => JWTAuth::factory()->getTTL() * 60,
            "user" => [
                "fullname" => auth('api')->user()->name,
                "email" => auth('api')->user()->email,
                "avatar" => auth('api')->user()->avatar ? env('APP_URL') . '/storage/' . auth('api')->user()->avatar : null,
                "role" => [
                    "id" => auth('api')->user()->role->id,
                    "name" => auth('api')->user()->role->name,
                ],
                "permissions" => $permissions
            ],
        ]);
    }
}
