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
    // ── Lista paginada con búsqueda ──────────────────────────────────
    public function index(Request $request)
    {
        $search = $request->get('search', '');

        $clients = Client::whereRaw(
            "(COALESCE(clients.phone,'') || ' ' || COALESCE(clients.name,'') || ' ' || COALESCE(clients.full_name,'') || ' ' || COALESCE(clients.n_document,'')) ILIKE ?",
            ["%{$search}%"]
        )
            ->orderBy('id', 'desc')
            ->paginate(25);

        return response()->json([
            'total'    => $clients->total(),
            'paginate' => 25,
            'clients'  => ClientCollection::make($clients),
        ]);
    }



    // ── Búsqueda online DNI / RUC (apisperu) ────────────────────────
    public function searchDocument(string $type, string $number)
    {
        $token    = env('APISPERU_TOKEN', 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJlbWFpbCI6InVtYm9zYWNAZ21haWwuY29tIn0.hkzW13nZuMnAk7WcQ-jAV_GOddeDCvMZHY0C0srn6Lc');
        $endpoint = strtolower($type) === 'dni' ? 'dni' : 'ruc';

        try {
            $response = Http::get("https://dniruc.apisperu.com/api/v1/{$endpoint}/{$number}", [
                'token' => $token,
            ]);

            if ($response->successful()) {
                return response()->json($response->json());
            }

            return response()->json([
                'success' => false,
                'message' => 'No se pudo obtener la información',
            ], $response->status());
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al consultar el documento: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ── Campos permitidos (evita mass assignment no deseado) ─────────
    // password y user_id NO están aquí — no se pueden pisar desde este endpoint
    private function allowedFields(): array
    {
        return [
            'type_client',
            'type_document',
            'n_document',
            'cod_tipo_doc_sunat',   // nuevo — Catálogo 06 SUNAT
            'es_amazonia',          // nuevo — zona Amazonía Ley 27037
            'name',
            'surname',
            'full_name',
            'name_comerc',
            'email',
            'phone',
            'birth_date',
            'gender',
            'address',
            'ubigeo_region',
            'ubigeo_provincia',
            'ubigeo_distrito',
            'region',
            'provincia',
            'distrito',
            'state',
            'regimen_tributario',
            'es_agente_retencion',
        ];
    }


    // ── Crear cliente ────────────────────────────────────────────────
    public function store(Request $request)
    {
        // Clientes sin datos (SND) pueden compartir n_document '00000000'
        // Solo validar duplicado si tiene documento real
        if ($request->type_document !== 'SND') {
            $existePorNombre = Client::where('full_name', $request->full_name)->first();
            if ($existePorNombre) {
                return response()->json([
                    'code'    => 405,
                    'message' => 'Ya existe un cliente con el mismo nombre.',
                ]);
            }

            $existePorDoc = Client::where('n_document', $request->n_document)->first();
            if ($existePorDoc) {
                return response()->json([
                    'code'    => 405,
                    'message' => 'Ya existe un cliente con ese número de documento.',
                ]);
            }
        }

        $client = Client::create($request->only($this->allowedFields()));

        return response()->json([
            'code'    => 200,
            'message' => 'Cliente creado con éxito.',
            'client'  => ClientResource::make($client),
        ]);
    }

    public function show(string $id)
    {
        // como va ser con modal no se necesita
    }

    // ── Actualizar cliente ───────────────────────────────────────────
    public function update(Request $request, string $id)
    {
        $client = Client::findOrFail($id);

        // Validar duplicados excluyendo el registro actual
        if ($request->type_document !== 'SND') {
            $existePorNombre = Client::where('id', '!=', $id)
                ->where('full_name', $request->full_name)
                ->first();
            if ($existePorNombre) {
                return response()->json([
                    'code'    => 405,
                    'message' => 'Ya existe otro cliente con el mismo nombre.',
                ]);
            }

            $existePorDoc = Client::where('id', '!=', $id)
                ->where('n_document', $request->n_document)
                ->first();
            if ($existePorDoc) {
                return response()->json([
                    'code'    => 405,
                    'message' => 'Ya existe otro cliente con ese número de documento.',
                ]);
            }
        }

        $client->update($request->only($this->allowedFields()));

        return response()->json([
            'code'    => 200,
            'message' => 'Cliente actualizado con éxito.',
            'client'  => ClientResource::make($client),
        ]);
    }

    // ── Eliminar cliente (soft delete) ───────────────────────────────
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
