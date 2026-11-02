<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaDestino;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\TipoCambioAgencia;
use App\Services\HoraPeru;
use App\Services\TextoFormatoService;
use App\Services\TipoCambio\TipoCambioSanityService;
use App\Services\TipoCambio\TipoCambioSunatResolver;
use Illuminate\Support\Facades\DB;

// T-033 — crear una alternativa vive acá para que lo usen "+ Nueva" (AlternativaController::store())
// y la alternativa automática de Nueva cotización (CotizacionController::store()) sin duplicarlo.
// Los errores salen como AlternativaCreacionException (422 en el controller).
class AlternativaCreacionService
{
    public const MAX_ALTERNATIVAS_POR_COTIZACION = 5;

    public function __construct(
        private TipoCambioSanityService $tipoCambioSanity,
        private TipoCambioSunatResolver $tipoCambioSunat,
    ) {
    }

    /**
     * Crea la alternativa con su AlternativaDestino inicial. Nombre vacío = el sugerido.
     *
     * @throws AlternativaCreacionException
     */
    public function crear(
        Cotizacion $cotizacion,
        ?string $nombre,
        string $moneda,
        string $tipoCambioOrigen,
        ?float $tipoCambioValor,
        bool $tipoCambioConfirmado,
        ?int $usuarioId,
    ): Alternativa {
        if ($cotizacion->alternativas()->count() >= self::MAX_ALTERNATIVAS_POR_COTIZACION) {
            throw new AlternativaCreacionException('Esta cotización ya tiene el máximo de ' . self::MAX_ALTERNATIVAS_POR_COTIZACION . ' alternativas permitidas.');
        }

        $nombre = trim((string) $nombre);

        // Savepoint si el llamador ya abrió transacción (CotizacionController::store()).
        return DB::transaction(function () use ($cotizacion, $nombre, $moneda, $tipoCambioOrigen, $tipoCambioValor, $tipoCambioConfirmado, $usuarioId) {
            $tipoCambio = $this->resolverTipoCambio($tipoCambioOrigen, $tipoCambioValor, $usuarioId, $tipoCambioConfirmado);

            $alternativa = Alternativa::create([
                'cotizacion_id' => $cotizacion->id,
                // 29-ago-2026 — capitalización tipo título solo cuando el usuario tipeó algo; el
                // nombre sugerido ya está bien formado.
                'nombre' => $nombre !== '' ? TextoFormatoService::capitalizarNombrePropio($nombre) : $this->nombreSugerido($cotizacion),
                'estado' => 'borrador',
                'moneda_cotizacion' => $moneda,
                'tipo_cambio_aplicado' => $tipoCambio->valor,
                'tipo_cambio_origen' => $tipoCambioOrigen,
                'total' => 0,
            ]);

            // Sesión 12c — toda alternativa nace con exactamente 1 destino, igual que el backfill de
            // 12b dejó a las históricas.
            AlternativaDestino::create([
                'alternativa_id' => $alternativa->id,
                'destino_atractivo_id' => AlternativaDestino::resolverDestinoAtractivoId($cotizacion->destino),
                'destino_texto' => $cotizacion->destino,
                'orden' => 1,
                'fecha_inicio' => $cotizacion->fecha_viaje_desde,
                'fecha_fin' => $cotizacion->fecha_viaje_hasta,
            ]);

            return $alternativa;
        });
    }

    // "Alternativa {letra}": la letra que toca por cantidad (A, B, C…), saltando las que ya estén
    // usadas por nombre (si se borró la B, la tercera no repite "Alternativa C").
    public function nombreSugerido(Cotizacion $cotizacion): string
    {
        $usados = $cotizacion->alternativas()->pluck('nombre')
            ->map(fn ($n) => mb_strtolower(trim((string) $n), 'UTF-8'))
            ->all();

        for ($i = count($usados); $i < 26; $i++) {
            $nombre = 'Alternativa ' . chr(65 + $i);
            if (! in_array(mb_strtolower($nombre, 'UTF-8'), $usados, true)) {
                return $nombre;
            }
        }

        return 'Alternativa ' . (count($usados) + 1);
    }

    /**
     * Orden (decisión del usuario, 01-nov-2026): valor escrito → último registrado con ese origen →
     * (solo origen 'dia') venta SUNAT, que queda registrada → 422 pidiendo el valor.
     *
     * @throws AlternativaCreacionException
     */
    public function resolverTipoCambio(string $origen, ?float $valorNuevo, ?int $usuarioId, bool $confirmado = false): TipoCambioAgencia
    {
        if ($valorNuevo !== null) {
            if (! $this->tipoCambioSanity->esRazonable($valorNuevo) && ! $confirmado) {
                throw new AlternativaCreacionException(
                    "El tipo de cambio {$valorNuevo} está fuera del rango esperado para USD/PEN (" . TipoCambioSanityService::RANGO_MINIMO . '-' . TipoCambioSanityService::RANGO_MAXIMO . '). Si es correcto, confírmalo.',
                    ['requiere_confirmacion' => true, 'requiere_tipo_cambio' => true],
                );
            }

            return TipoCambioAgencia::create([
                'fecha' => HoraPeru::hoyTexto(),
                'origen' => $origen,
                'valor' => $valorNuevo,
                'registrado_por' => $usuarioId,
            ]);
        }

        $ultimo = TipoCambioAgencia::where('origen', $origen)->orderByDesc('fecha')->orderByDesc('id')->first();
        if ($ultimo) {
            return $ultimo;
        }

        if ($origen === 'dia' && $usuarioId !== null) {
            $sunat = $this->tipoCambioSunat->resolverParaFecha(HoraPeru::hoy());
            if ($sunat && $sunat->venta !== null) {
                // La fecha es la de SUNAT: si hoy no publicó, el último día hábil que sí.
                return TipoCambioAgencia::create([
                    'fecha' => $sunat->fecha->toDateString(),
                    'origen' => 'dia',
                    'valor' => $sunat->venta,
                    'registrado_por' => $usuarioId,
                ]);
            }
        }

        $etiqueta = $origen === 'dia' ? 'del día' : 'de la agencia';
        throw new AlternativaCreacionException(
            "No hay tipo de cambio {$etiqueta} registrado ni uno de SUNAT disponible. Escribe el tipo de cambio.",
            ['requiere_tipo_cambio' => true],
        );
    }
}
