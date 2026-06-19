<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\Client\ClientCollection;
use App\Http\Resources\Client\ClientResource;
use App\Models\Client\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $search = request('search');

        $clients = Client::whereRaw("(COALESCE(clients.phone,'') || ' ' || COALESCE(clients.name,'') || ' ' || COALESCE(clients.n_document,'')) ILIKE ?", ["%{$search}%"])
            ->orderBy("id", "desc")
            ->paginate(25);

        return response()->json([
            "total" => $clients->total(),
            "paginate" => 25,
            "clients" => ClientCollection::make($clients)
        ]);
    }



    public function searchDocument($type, $number)
    {
        
        $token = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJlbWFpbCI6InVtYm9zYWNAZ21haWwuY29tIn0.hkzW13nZuMnAk7WcQ-jAV_GOddeDCvMZHY0C0srn6Lc'; // O usa: env('APISPERU_TOKEN')
        
        $endpoint = strtolower($type) === 'dni' ? 'dni' : 'ruc'; //AQUI LO QUE HACE ES CONVERTIR EL TIPO DE DOCUMENTO EN MINUSCULAS
        
        try {
            $response = Http::get("https://dniruc.apisperu.com/api/v1/{$endpoint}/{$number}", [//ENVIAMOS LA PETICION A LA API DE APISPERU
                'token' => $token
            ]);
            
            if ($response->successful()) {//SI LA PETICION FUE EXITOSA
                return response()->json($response->json());//RETORNA LA INFORMACION
            }
            
            return response()->json([ //SI NO FUE EXITOSA
                'success' => false,
                'message' => 'No se pudo obtener la información'
            ], $response->status());
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al consultar el documento: ' . $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {

        $is_exist_client = Client::where('full_name', $request->full_name)->first();
        if ($is_exist_client) {
            return response()->json([
                "code" => 405,
                "message" => "El cliente ya existe con el mismo nombre",
            ]);
        }

        $is_exist_client = Client::where('n_document', $request->n_document)->first();
        if ($is_exist_client) {
            return response()->json([
                "code" => 405,
                "message" => "El cliente ya existe con ese número de documento",
            ]);
        }

        $client = Client::create($request->all());

        return response()->json([
            "code" => 200,
            "message" => "Se creo el cliente con éxito",
           "clients" => ClientResource::make($client)
        ]);
    }

    public function show(string $id)
    {
        // como va ser con modal no se necesita
    }

    public function update(Request $request, string $id)
    {
        $is_exist_client = Client::where('id', '!=', $id)
            ->where('full_name', $request->full_name)->first();
        if ($is_exist_client) {
            return response()->json([
                "code" => 405,
                "message" => "El cliente ya existe con el mismo nombre",
            ]);
        }

        $is_exist_client = Client::where('id', '!=', $id)
            ->where('n_document', $request->n_document)->first();
        if ($is_exist_client) {
            return response()->json([
                "code" => 405,
                "message" => "El cliente ya existe con ese número de documento",
            ]);
        }

        $client = Client::findOrFail($id); //findOrFail es diferente a find porque si no lo encuentra lanza un error
        $client->update($request->all());

        return response()->json([
            "code" => 200,
            "message" => "Se actualizo el cliente con éxito",
            "clients" => ClientResource::make($client)
        ]);
    }

    public function destroy(string $id)
    {
        $client = Client::findOrFail($id);
        $client->delete();

        return response()->json([
            "code" => 200,
            "message" => "Cliente eliminado con exito"
        ]);
    }
}
