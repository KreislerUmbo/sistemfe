<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\Aerolinea;
use App\Services\Tenancy\GiroActual;
use App\Services\TextoFormatoService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

// T-015 — Catálogo común de aerolíneas, visto desde un tenant de Agencia. La tabla es CENTRAL:
// escribir desde acá es a propósito (la agencia agrega la que falta y queda para todas), solo en
// `aerolineas` y vía el modelo con CentralConnection.
//
// Solo giro Agencia (404 en otro giro, mismo criterio que TraspasoTrabajoController): el permiso
// solo no corta a un retail, porque el rol Propietario recibe todos los permisos.
class AerolineaController extends Controller
{
    private const MAX_RESULTADOS = 20;

    public function __construct(private readonly GiroActual $giro)
    {
    }

    // GET aerolineas?buscar= — activas, por nombre o código IATA; máx. 20.
    public function index(Request $request): JsonResponse
    {
        $this->soloAgencia();

        $buscar = trim((string) $request->query('buscar', ''));

        $consulta = Aerolinea::where('activa', true)->orderBy('nombre');
        if ($buscar !== '') {
            $termino = Aerolinea::normalizar($buscar);
            $consulta->where(function ($q) use ($termino) {
                $q->where('nombre_normalizado', 'like', '%'.self::escaparLike($termino).'%')
                    ->orWhereRaw('lower(codigo_iata) = ?', [$termino]);
            });
        }

        return response()->json([
            'data' => $consulta->limit(self::MAX_RESULTADOS)->get(['id', 'nombre', 'codigo_iata']),
        ]);
    }

    // POST aerolineas — agrega; si ya existe (sin distinguir mayúsculas ni espacios) devuelve la
    // existente (200) en vez de duplicar; si dos agencias la agregan a la vez, la violación del
    // índice único también termina devolviendo la existente.
    public function store(Request $request): JsonResponse
    {
        $this->soloAgencia();

        $data = $request->validate([
            'nombre' => ['required', 'string', 'min:2', 'max:100'],
            'codigo_iata' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{2,3}$/'],
        ]);

        $nombre = self::formatearNombre($data['nombre']);
        $normalizado = Aerolinea::normalizar($nombre);

        $existente = $this->buscarPorNombre($normalizado);
        if ($existente) {
            return response()->json(['data' => $existente, 'creada' => false]);
        }

        try {
            // En transacción (savepoint si ya hay una): una violación del único no debe dejar
            // abortada la conexión antes de buscar la existente.
            $aerolinea = DB::connection('central')->transaction(fn () => Aerolinea::create([
                'nombre' => $nombre,
                'nombre_normalizado' => $normalizado,
                'codigo_iata' => isset($data['codigo_iata']) ? strtoupper($data['codigo_iata']) : null,
                'activa' => true,
                'creada_por_tenant' => tenant()?->getTenantKey(),
            ]));
        } catch (QueryException $e) {
            // Carrera: otra agencia insertó el mismo nombre entre el chequeo y el insert.
            $existente = $this->buscarPorNombre($normalizado);
            if (! $existente) {
                throw $e;
            }

            return response()->json(['data' => $existente, 'creada' => false]);
        }

        return response()->json(['data' => $aerolinea->only(['id', 'nombre', 'codigo_iata']), 'creada' => true], 201);
    }

    // Regla de mayúsculas (simple): solo si el nombre viene TODO en minúsculas ("viva air") se
    // aplica TextoFormatoService (capitalización tipo título → "Viva Air"). Si trae alguna
    // mayúscula se respeta tal cual: siglas y marcas como "KLM", "easyJet", "JetSMART" o
    // "KLM Royal Dutch Airlines" (TextoFormatoService las pasaría a "Easyjet", "Klm…", y en un
    // catálogo común eso le llega a todas las agencias). Si el nombre ya existe, igual se
    // devuelve el del catálogo, así que "latam" nunca crea otra LATAM.
    public static function formatearNombre(string $nombre): string
    {
        $nombre = trim(preg_replace('/\s+/u', ' ', $nombre));
        $esTodoMinusculas = mb_strtolower($nombre) === $nombre;

        return $esTodoMinusculas ? TextoFormatoService::capitalizarNombrePropio($nombre) : $nombre;
    }

    private function buscarPorNombre(string $normalizado): ?array
    {
        // Si la existente está inactiva o unida a otra, se devuelve la vigente a la que apunta.
        $aerolinea = Aerolinea::where('nombre_normalizado', $normalizado)->first();
        for ($i = 0; $aerolinea && $aerolinea->unida_a_id && $i < 10; $i++) {
            $aerolinea = Aerolinea::find($aerolinea->unida_a_id);
        }

        return $aerolinea?->only(['id', 'nombre', 'codigo_iata']);
    }

    private static function escaparLike(string $texto): string
    {
        return addcslashes($texto, '%_\\');
    }

    private function soloAgencia(): void
    {
        if (! $this->giro->es('agencia_viajes')) {
            throw new HttpException(404, 'No disponible para este giro.');
        }
    }
}
