<?php

namespace App\Services\Crm;

use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\Reserva;
use App\Models\Client\CrmAccion;
use App\Models\Client\CrmActividad;
use App\Models\User;
use App\Services\HoraPeru;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * T-051 (CRM C2) — próximas acciones con el cliente. Un solo lugar para lo que comparten los
 * controllers: validar una acción nueva, proponerla al enviar una cotización, cerrarla (a mano o
 * sola, al aceptar la cotización) y decidir quién puede tocarla.
 *
 * Quién puede marcar hecha o reprogramar (decidido con el usuario, 02-nov-2026): su responsable
 * siempre; además, quien puede editar la referencia (CotizacionPolicy / ReservaPolicy, o
 * edit_client si la acción no tiene referencia).
 */
class AccionesCrm
{
    /** Responsable válido: un usuario activo (users.state 2 = inactivo) y no eliminado. */
    public static function reglaResponsable(): Exists
    {
        return Rule::exists('users', 'id')->whereNot('state', User::STATE_INACTIVO)->whereNull('deleted_at');
    }

    /**
     * Reglas de una acción nueva bajo $prefijo ('' o 'proxima_accion.', 'siguiente.'…). Fecha de
     * negocio en hora de Perú, nunca anterior a hoy.
     *
     * @return array<string, mixed>
     */
    public static function reglas(string $prefijo = '', bool $conTipo = true): array
    {
        $requerido = $prefijo === '' ? 'required' : 'required_with:' . rtrim($prefijo, '.');

        $reglas = [
            "{$prefijo}fecha" => [$requerido, 'date_format:Y-m-d', 'after_or_equal:' . HoraPeru::hoyTexto()],
            "{$prefijo}responsable_id" => [$requerido, 'integer', self::reglaResponsable()],
            "{$prefijo}descripcion" => ['nullable', 'string', 'max:' . CrmAccion::DESCRIPCION_MAX],
        ];
        if ($conTipo) {
            $reglas["{$prefijo}tipo"] = [$requerido, Rule::in(array_keys(CrmAccion::TIPOS))];
        }

        return $reglas;
    }

    /** @return array<string, string> */
    public static function mensajes(string $prefijo = ''): array
    {
        return [
            "{$prefijo}fecha.after_or_equal" => 'La fecha de la acción no puede ser anterior a hoy.',
            "{$prefijo}fecha.date_format" => 'La fecha de la acción no es válida.',
            "{$prefijo}fecha.required_with" => 'Indica la fecha de la acción.',
            "{$prefijo}responsable_id.required_with" => 'Indica el responsable de la acción.',
            "{$prefijo}responsable_id.exists" => 'El responsable tiene que ser un usuario activo.',
            "{$prefijo}tipo.required_with" => 'Indica qué hay que hacer.',
        ];
    }

    /**
     * Al enviar una alternativa (correo, WhatsApp u otro medio): la acción "Llamar" que la
     * pantalla propone y el vendedor pudo cambiar. null = la quitó.
     *
     * @param  array{fecha: string, responsable_id: int}|null  $propuesta
     */
    public function crearAlEnviar(Alternativa $alternativa, ?array $propuesta): ?CrmAccion
    {
        if (! $propuesta) {
            return null;
        }

        return CrmAccion::create([
            'cliente_id' => $alternativa->cotizacion->cliente_id,
            'referencia_tipo' => CrmActividad::REFERENCIA_COTIZACION,
            'referencia_id' => $alternativa->cotizacion_id,
            'tipo' => CrmAccion::TIPO_LLAMAR,
            'fecha' => $propuesta['fecha'],
            'responsable_id' => $propuesta['responsable_id'],
            'creada_por' => auth('api')->id(),
        ]);
    }

    /**
     * Al aceptar una cotización (ReservaController::aceptar(), VentaDirectaController::store())
     * y, con T-052, al cerrarla como perdida: sus acciones pendientes ya no tienen sentido. Se
     * cierran con una actividad 'estado' que lo cuenta. Devuelve cuántas cerró.
     */
    public function cerrarPendientesDeCotizacion(int $cotizacionId, string $motivo): int
    {
        $pendientes = CrmAccion::pendientes()
            ->deReferencia(CrmActividad::REFERENCIA_COTIZACION, $cotizacionId)
            ->orderBy('fecha')
            ->get();

        if ($pendientes->isEmpty()) {
            return 0;
        }

        $usuarioId = auth('api')->id();
        CrmAccion::whereKey($pendientes->modelKeys())->update(['hecha_en' => now(), 'hecha_por' => $usuarioId]);

        $detalle = $pendientes
            ->map(fn (CrmAccion $a) => '«' . $a->etiquetaTipo() . '» del ' . $a->fecha->format('d/m'))
            ->implode(', ');

        CrmActividad::create([
            'cliente_id' => $pendientes->first()->cliente_id,
            'referencia_tipo' => CrmActividad::REFERENCIA_COTIZACION,
            'referencia_id' => $cotizacionId,
            'tipo' => CrmActividad::TIPO_ESTADO,
            'nota' => "{$motivo}: se " . ($pendientes->count() === 1 ? 'cerró la acción pendiente ' : 'cerraron las acciones pendientes ') . $detalle . '.',
            'usuario_id' => $usuarioId,
        ]);

        return $pendientes->count();
    }

    /**
     * "Marcar hecha": opcionalmente anota el resultado como actividad (T-050) y agenda la
     * siguiente, las dos con la misma referencia. Todo o nada.
     *
     * @param  array{tipo: string, nota?: string|null}|null  $resultado
     * @param  array{tipo: string, fecha: string, responsable_id: int, descripcion?: string|null}|null  $siguiente
     * @return array{actividad: ?CrmActividad, siguiente: ?CrmAccion}
     */
    public function marcarHecha(CrmAccion $accion, User $usuario, ?array $resultado, ?array $siguiente): array
    {
        return DB::transaction(function () use ($accion, $usuario, $resultado, $siguiente) {
            $bloqueada = CrmAccion::whereKey($accion->id)->lockForUpdate()->firstOrFail();
            if (! $bloqueada->estaPendiente()) {
                throw new HttpException(422, 'Esta acción ya estaba marcada como hecha.');
            }
            $bloqueada->update(['hecha_en' => now(), 'hecha_por' => $usuario->id]);

            $actividad = null;
            if ($resultado) {
                $nota = trim((string) ($resultado['nota'] ?? ''));
                $actividad = CrmActividad::create([
                    'cliente_id' => $bloqueada->cliente_id,
                    'referencia_tipo' => $bloqueada->referencia_tipo,
                    'referencia_id' => $bloqueada->referencia_id,
                    'tipo' => $resultado['tipo'],
                    'nota' => $nota !== '' ? $nota : null,
                    'usuario_id' => $usuario->id,
                ]);
            }

            $nueva = null;
            if ($siguiente) {
                $nueva = CrmAccion::create([
                    'cliente_id' => $bloqueada->cliente_id,
                    'referencia_tipo' => $bloqueada->referencia_tipo,
                    'referencia_id' => $bloqueada->referencia_id,
                    'tipo' => $siguiente['tipo'],
                    'descripcion' => self::textoONull($siguiente['descripcion'] ?? null),
                    'fecha' => $siguiente['fecha'],
                    'responsable_id' => $siguiente['responsable_id'],
                    'creada_por' => $usuario->id,
                ]);
            }

            return ['actividad' => $actividad, 'siguiente' => $nueva];
        });
    }

    /**
     * Una acción pendiente como la ve la pantalla (cotización, reserva, ficha, bandeja).
     * Necesita la relación responsable cargada.
     *
     * @return array<string, mixed>
     */
    public function presentar(CrmAccion $accion, User $usuario): array
    {
        return [
            'id' => $accion->id,
            'cliente_id' => $accion->cliente_id,
            'referencia_tipo' => $accion->referencia_tipo,
            'referencia_id' => $accion->referencia_id,
            'tipo' => $accion->tipo,
            'tipo_etiqueta' => $accion->etiquetaTipo(),
            'descripcion' => $accion->descripcion,
            'fecha' => $accion->fecha->toDateString(),
            'responsable' => ['id' => $accion->responsable_id, 'nombre' => self::nombreUsuario($accion->responsable)],
            'puede_gestionar' => $this->puedeGestionar($usuario, $accion),
        ];
    }

    public static function nombreUsuario(?User $usuario): string
    {
        return trim(($usuario?->name ?? '') . ' ' . ($usuario?->surname ?? ''));
    }

    public function puedeGestionar(User $usuario, CrmAccion $accion): bool
    {
        if ((int) $accion->responsable_id === (int) $usuario->id) {
            return true;
        }
        if (! $accion->referencia_tipo) {
            return $usuario->can('edit_client');
        }

        $referencia = $this->buscarReferencia($accion->referencia_tipo, (int) $accion->referencia_id);

        return $referencia !== null && $this->puedeEditarReferencia($usuario, $referencia);
    }

    /**
     * Cotización o reserva de una referencia. Quién puede verla o tocarla lo decide la Policy.
     */
    public function buscarReferencia(string $tipo, int $id): Cotizacion|Reserva|null
    {
        return match ($tipo) {
            CrmActividad::REFERENCIA_COTIZACION => Cotizacion::find($id),
            CrmActividad::REFERENCIA_RESERVA => Reserva::find($id),
            default => null,
        };
    }

    public function clienteDeReferencia(Cotizacion|Reserva $referencia): ?int
    {
        if ($referencia instanceof Cotizacion) {
            return $referencia->cliente_id;
        }

        return Cotizacion::query()
            ->whereHas('alternativas', fn ($q) => $q->where('id', $referencia->alternativa_id))
            ->value('cliente_id');
    }

    /** Permiso base de la pantalla + Policy de fila (su vendedor o ver_todas). */
    public function puedeEditarReferencia(User $usuario, Model $referencia): bool
    {
        $permiso = $referencia instanceof Cotizacion ? 'cotizaciones.editar' : 'reservas.editar';

        return $usuario->can($permiso) && Gate::forUser($usuario)->allows('update', $referencia);
    }

    public function puedeVerReferencia(User $usuario, Model $referencia): bool
    {
        $permisos = $referencia instanceof Cotizacion
            ? ['cotizaciones.ver', 'cotizaciones.ver_todas']
            : ['reservas.ver', 'reservas.ver_todas'];

        return $usuario->canAny($permisos) && Gate::forUser($usuario)->allows('view', $referencia);
    }

    private static function textoONull(?string $texto): ?string
    {
        $texto = trim((string) $texto);

        return $texto !== '' ? $texto : null;
    }
}
