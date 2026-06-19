<?php
// api-sistema-fe/app/Http/Controllers/Portal/PortalAuthController.php es para la registro,login a mi cuenta del portal
namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client\Client;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class PortalAuthController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'surname' => 'required|string|max:255',
            'email' => 'required|email|unique:clients,email',
            'phone' => 'required|string',
            'n_document' => 'nullable|string|unique:clients,n_document',
            'password' => 'required|string|min:6',
        ]);
        if ($validator->fails()) { // si no pasa la validacion
            return response()->json(['errors' => $validator->errors()], 422); // devuelve un json con los errores
        }
        $client = Client::create([
            'name' => $request->name,
            'surname' => $request->surname,
            'full_name' => $request->name . ' ' . $request->surname,
            'email' => $request->email,
            'phone' => $request->phone,
            'type_document' => 'DNI',
            'n_document' => $request->n_document,
            'password' => $request->password,
            'type_client' => 1,
            'state' => 1,
        ]);
        $token = JWTAuth::fromUser($client);

        return response()->json([
            'access_token' => $token,
            'client' => $client
        ], 201);
    }
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
    public function logout(Request $request)
    {
        $request->user('client')->currentAccessToken()->delete();
        return response()->json(['message' => 'Sesión cerrada']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user('client'));
    }
}
