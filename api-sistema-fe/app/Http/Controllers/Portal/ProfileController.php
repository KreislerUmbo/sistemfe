<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Client\Client;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    public function show()
    {
        $client = auth('client')->user();

        return response()->json([
            'client' => $client
        ]);
    }

    public function update(Request $request)
    {
        $client = auth('client')->user();
        if (!$client) {
            return response()->json([
                'success' => false,
                'message' => 'No autenticado'
            ], 401);
        }
        $request->validate([
            'name' => 'required|max:200',
            'surname' => 'required|max:250',
            'email' => 'required|email',
            'phone' => 'nullable|max:25',
            'type_document' => 'required',
            'n_document' => 'required',
            'address' => 'nullable|max:250',
            'region' => 'nullable|max:80',
            'provincia' => 'nullable|max:80',
            'distrito' => 'nullable|max:80',
        ]);

        $client->update([
            'name' => $request->name,
            'surname' => $request->surname,
            'full_name' => $request->name . ' ' . $request->surname,
            'email' => $request->email,
            'phone' => $request->phone,
            'type_document' => $request->type_document,
            'n_document' => $request->n_document,
            'address' => $request->address,
            'region' => $request->region,
            'provincia' => $request->provincia,
            'distrito' => $request->distrito,
        ]);
        return response()->json([
            'success' => true,
            'message' => 'Datos actualizados correctamente'
        ]);
    }

    public function updatePassword(Request $request)
    {
        $request->validate([ //validaciones de la contraseña
            'current_password' => 'required',
            'password' => 'required|min:6|confirmed'
        ]);


        $client = auth('client')->user();
        if (!Hash::check($request->current_password, $client->password)) //verificar si la contraseña es correcta porque compara con la base de datos
        {
            return response()->json([
                'message' => 'La contraseña actual es incorrecta'
            ], 422);
        }

        $client->password = Hash::make($request->password);//aqui se encripta la contraseña

        $client->save();//guardar

        return response()->json([
            'success' => true,
            'message' => 'Contraseña actualizada correctamente'
        ]);
    }
}
