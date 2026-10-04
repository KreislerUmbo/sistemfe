<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Enums\Creditos\FuncionCartera;
use App\Enums\Creditos\ModoAsignacionCartera;
use App\Enums\Creditos\RequisitoFicha;
use App\Enums\Creditos\TipoArchivoCliente;
use App\Models\Client\Client;
use App\Models\Creditos\CarteraAsignacion;
use App\Models\Creditos\CreditoClienteArchivo;
use App\Models\Creditos\CreditoClienteFicha;
use App\Models\Creditos\CreditoClienteLimite;
use App\Models\Creditos\CreditoConfiguracion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Datos de crédito del cliente (00 1.14, ficha de cobro, 04c): ficha, archivos (DNI, foto) en el
 * disco privado del tenant, cartera (asesor y cobrador, con historial) y límites propios.
 */
class ClienteCreditoService
{
    public const DISCO = 'private';
    /** Fotos tomadas con el celular: se reducen antes de guardarlas. */
    private const LADO_MAXIMO_PX = 1600;
    private const CALIDAD_JPEG = 80;
    private const PERMISO_COBRAR = 'creditos.cobrar';
    private const PERMISO_COLOCAR = 'creditos.crear';
    private const PERMISO_ASIGNAR = 'creditos.cartera.asignar';
    /** users.state: 1 = activo, 2 = inactivo (pantalla de Usuarios). */
    private const USUARIO_INACTIVO = 2;

    public function __construct(
        private readonly AuditoriaCredito $auditoria,
        private readonly Reloj $reloj,
    ) {
    }

    /** @param array<string, mixed> $datos campos validados de la ficha */
    public function guardarFicha(Client $cliente, array $datos, User $usuario): CreditoClienteFicha
    {
        return CreditoClienteFicha::updateOrCreate(
            ['cliente_id' => $cliente->id],
            [...$datos, 'actualizado_por' => $usuario->id],
        );
    }

    public function subirArchivo(Client $cliente, TipoArchivoCliente $tipo, UploadedFile $archivo, User $usuario): CreditoClienteArchivo
    {
        $imagen = ImageManager::gd()->read($archivo->getRealPath())->scaleDown(self::LADO_MAXIMO_PX, self::LADO_MAXIMO_PX);
        $ruta = "creditos/clientes/{$cliente->id}/{$tipo->value}-" . Str::uuid() . '.jpg';
        Storage::disk(self::DISCO)->put($ruta, (string) $imagen->toJpeg(self::CALIDAD_JPEG));

        return CreditoClienteArchivo::create([
            'cliente_id' => $cliente->id,
            'tipo' => $tipo,
            'ruta_archivo' => $ruta,
            'registrado_por' => $usuario->id,
        ]);
    }

    /**
     * Requisitos de la configuración que el cliente todavía no cumple (se exigen al activar).
     *
     * @return list<RequisitoFicha>
     */
    public function fichaFaltante(int $clienteId): array
    {
        $exigidos = array_filter(array_map(
            static fn (string $r): ?RequisitoFicha => RequisitoFicha::tryFrom($r),
            CreditoConfiguracion::actual()->requisitos_ficha ?? [],
        ));
        if ($exigidos === []) {
            return [];
        }

        $cliente = Client::find($clienteId);
        $ficha = CreditoClienteFicha::where('cliente_id', $clienteId)->first();
        $archivos = CreditoClienteArchivo::where('cliente_id', $clienteId)->distinct()->pluck('tipo')
            ->map(static fn ($t): string => $t instanceof TipoArchivoCliente ? $t->value : (string) $t)->all();
        $lleno = static fn (?string $v): bool => trim((string) $v) !== '';

        return array_values(array_filter($exigidos, static fn (RequisitoFicha $r): bool => ! match ($r) {
            RequisitoFicha::DniAnverso, RequisitoFicha::DniReverso, RequisitoFicha::FotoCliente => in_array($r->value, $archivos, true),
            RequisitoFicha::Telefono => $lleno($cliente?->phone),
            RequisitoFicha::DireccionCobro => $lleno($ficha?->direccion_cobro),
            RequisitoFicha::Referencia => $lleno($ficha?->referencia),
            RequisitoFicha::Ubicacion => $ficha?->latitud !== null && $ficha?->longitud !== null,
            RequisitoFicha::Ocupacion => $lleno($ficha?->ocupacion),
        }));
    }

    /**
     * Usuarios elegibles en el selector de cartera (can(): cuenta el Super-Admin y los permisos
     * directos). Si el asesor también cobra, el asesor necesita ambos permisos.
     *
     * @return array{asesores: list<array{id: int, nombre: string}>, cobradores: list<array{id: int, nombre: string}>}
     */
    public function usuariosCartera(): array
    {
        // Solo usuarios activos: un inactivo o eliminado no puede recibir cartera (04c.1).
        $usuarios = User::where(static fn ($q) => $q->whereNull('state')->orWhere('state', '!=', self::USUARIO_INACTIVO))
            ->orderBy('name')->get(['id', 'name']);
        $lista = static fn (callable $filtro): array => $usuarios->filter($filtro)
            ->map(static fn (User $u): array => ['id' => $u->id, 'nombre' => $u->name])->values()->all();

        return [
            'asesores' => $lista(fn (User $u): bool => $this->puedeSerAsesor($u)),
            'cobradores' => $lista(static fn (User $u): bool => $u->can(self::PERMISO_COBRAR)),
        ];
    }

    /** @return array{asesor_id: int|null, cobrador_id: int|null, asesor_cobra: bool} */
    public function cartera(Client $cliente): array
    {
        return [
            'asesor_id' => CarteraAsignacion::usuarioVigente($cliente->id, FuncionCartera::Asesor),
            'cobrador_id' => CarteraAsignacion::usuarioVigente($cliente->id, FuncionCartera::Cobrador),
            'asesor_cobra' => (bool) CreditoConfiguracion::actual()->asesor_cobra,
        ];
    }

    /** @return list<array<string, mixed>> asignaciones del cliente, la más reciente primero */
    public function historialCartera(Client $cliente): array
    {
        $filas = CarteraAsignacion::delCliente($cliente->id)->orderByDesc('id')->get();
        $nombres = User::whereIn('id', $filas->pluck('usuario_id')->merge($filas->pluck('asignado_por'))->unique())->pluck('name', 'id');

        return $filas->map(static fn (CarteraAsignacion $a): array => [
            'funcion' => $a->funcion->value,
            'usuario' => $nombres[$a->usuario_id] ?? null,
            'vigente_desde' => $a->vigente_desde?->format('Y-m-d'),
            'vigente_hasta' => $a->vigente_hasta?->format('Y-m-d'),
            'asignado_por' => $nombres[$a->asignado_por] ?? null,
        ])->all();
    }

    /**
     * Cambia el asesor y el cobrador del cliente: cierra la asignación vigente (no la borra) y abre
     * la nueva; null = sin asignar. Con asesor_cobra el cobrador es siempre el asesor.
     *
     * @return array{asesor_id: int|null, cobrador_id: int|null, asesor_cobra: bool}
     */
    public function asignarCartera(Client $cliente, ?User $asesor, ?User $cobrador, User $asignador): array
    {
        $asesorCobra = (bool) CreditoConfiguracion::actual()->asesor_cobra;
        if ($asesorCobra) {
            $cobrador = $asesor;
        }
        if ($asesor !== null && ! $this->puedeSerAsesor($asesor)) {
            throw new HttpException(422, $asesorCobra
                ? 'El usuario elegido necesita permiso para registrar y para cobrar créditos.'
                : 'El usuario elegido no tiene permiso para registrar créditos.');
        }
        if ($cobrador !== null && ! $cobrador->can(self::PERMISO_COBRAR)) {
            throw new HttpException(422, 'El usuario elegido no tiene permiso para cobrar créditos.');
        }

        return DB::transaction(function () use ($cliente, $asesor, $cobrador, $asignador): array {
            $antes = $this->vigentesBloqueadas($cliente);
            $this->cambiar($cliente, FuncionCartera::Asesor, $antes[FuncionCartera::Asesor->value] ?? null, $asesor, $asignador);
            $this->cambiar($cliente, FuncionCartera::Cobrador, $antes[FuncionCartera::Cobrador->value] ?? null, $cobrador, $asignador);

            $previo = ['asesor_id' => $antes['asesor']?->usuario_id, 'cobrador_id' => $antes['cobrador']?->usuario_id];
            $nuevo = ['asesor_id' => $asesor?->id, 'cobrador_id' => $cobrador?->id];
            if ($previo !== $nuevo) {
                $this->auditoria->registrar('cartera.asignar', $cliente, null, $previo, $nuevo, null, $asignador);
            }

            return $this->cartera($cliente);
        });
    }

    /**
     * Cartera al registrar un cliente (04c): queda con quien lo registra si puede tener cartera,
     * o con el asesor elegido si quien registra puede asignar. Sin esto, un asesor sin
     * creditos.ver_todos dejaría de ver al cliente que acaba de registrar.
     */
    public function asignarAlRegistrar(Client $cliente, User $registrador, ?User $elegido = null): void
    {
        $asesor = $elegido !== null && $registrador->can(self::PERMISO_ASIGNAR) ? $elegido : $registrador;
        if (! $this->puedeSerAsesor($asesor)) {
            return;
        }
        $this->asignarCartera($cliente, $asesor, null, $registrador);
    }

    /**
     * Usuarios que hoy tienen clientes en su cartera, incluidos inactivos y eliminados (de esos
     * justamente hay que traspasar). Para el selector "Traspasar desde" (04c.1).
     *
     * @return list<array{id: int, nombre: string, activo: bool, eliminado: bool, asesor: int, cobrador: int}>
     */
    public function titularesCartera(): array
    {
        $conteos = CarteraAsignacion::vigentes()->where('tipo', ModoAsignacionCartera::Cliente)
            ->selectRaw('usuario_id, funcion, count(*) as total')->groupBy('usuario_id', 'funcion')->get();
        $usuarios = User::withTrashed()->whereIn('id', $conteos->pluck('usuario_id')->unique())->get(['id', 'name', 'state', 'deleted_at'])->keyBy('id');

        return $conteos->groupBy('usuario_id')->map(function ($filas, $id) use ($usuarios): array {
            $u = $usuarios[$id] ?? null;
            $total = static fn (FuncionCartera $f): int => (int) ($filas->first(static fn ($x) => ($x->funcion instanceof FuncionCartera ? $x->funcion : FuncionCartera::from($x->funcion)) === $f)?->total ?? 0);

            return [
                'id' => (int) $id,
                'nombre' => $u?->name ?? "Usuario #{$id}",
                'activo' => $u !== null && ! $u->trashed() && (int) $u->state !== self::USUARIO_INACTIVO,
                'eliminado' => $u === null || $u->trashed(),
                'asesor' => $total(FuncionCartera::Asesor),
                'cobrador' => $total(FuncionCartera::Cobrador),
            ];
        })->sortBy('nombre')->values()->all();
    }

    /** Clientes que el usuario tiene hoy en su cartera (cualquier función). */
    public function clientesEnCartera(int $usuarioId): int
    {
        return CarteraAsignacion::vigentes()->where('tipo', ModoAsignacionCartera::Cliente)
            ->where('usuario_id', $usuarioId)->distinct()->count('referencia_id');
    }

    /**
     * Traspasa la cartera de un usuario (que se va, cambia de zona…) a otro: cierra cada
     * asignación vigente y abre la nueva, con historial y auditoría (04c.1). Los créditos
     * conservan su asesor_id: quién colocó cada crédito no cambia.
     *
     * @param 'ambas'|'asesor'|'cobrador' $funciones con asesor_cobra siempre se traspasan ambas
     * @return int clientes traspasados
     */
    public function traspasarCartera(int $desdeId, User $hacia, string $funciones, User $asignador): int
    {
        if ($desdeId === $hacia->id) {
            throw new HttpException(422, 'Elige un usuario distinto al que tiene hoy la cartera.');
        }
        if ((int) $hacia->state === self::USUARIO_INACTIVO) {
            throw new HttpException(422, 'El usuario que recibe la cartera está inactivo.');
        }
        $lista = CreditoConfiguracion::actual()->asesor_cobra || $funciones === 'ambas'
            ? [FuncionCartera::Asesor, FuncionCartera::Cobrador]
            : [FuncionCartera::from($funciones)];
        if (in_array(FuncionCartera::Asesor, $lista, true) && ! $this->puedeSerAsesor($hacia)) {
            throw new HttpException(422, 'El usuario que recibe no tiene permiso para registrar' . (CreditoConfiguracion::actual()->asesor_cobra ? ' y cobrar' : '') . ' créditos.');
        }
        if (in_array(FuncionCartera::Cobrador, $lista, true) && ! $hacia->can(self::PERMISO_COBRAR)) {
            throw new HttpException(422, 'El usuario que recibe no tiene permiso para cobrar créditos.');
        }

        return DB::transaction(function () use ($desdeId, $hacia, $lista, $asignador): int {
            $hoy = $this->reloj->hoy()->aTexto();
            $vigentes = CarteraAsignacion::vigentes()->where('tipo', ModoAsignacionCartera::Cliente)
                ->where('usuario_id', $desdeId)->whereIn('funcion', array_map(static fn (FuncionCartera $f): string => $f->value, $lista))
                ->lockForUpdate()->get();
            if ($vigentes->isEmpty()) {
                throw new HttpException(422, 'Ese usuario no tiene clientes en su cartera.');
            }

            foreach ($vigentes as $a) {
                $a->update(['vigente_hasta' => $hoy]);
                CarteraAsignacion::create([
                    'usuario_id' => $hacia->id,
                    'funcion' => $a->funcion,
                    'tipo' => ModoAsignacionCartera::Cliente,
                    'referencia_id' => $a->referencia_id,
                    'vigente_desde' => $hoy,
                    'asignado_por' => $asignador->id,
                ]);
            }
            $clientes = $vigentes->pluck('referencia_id')->unique()->values();
            $this->auditoria->registrar('cartera.traspasar', $hacia, null,
                ['usuario_id' => $desdeId, 'clientes' => $clientes->all()],
                ['usuario_id' => $hacia->id, 'funciones' => array_map(static fn (FuncionCartera $f): string => $f->value, $lista)],
                null, $asignador);

            return $clientes->count();
        });
    }

    /** @param array{max_creditos_activos: int|null, deuda_maxima: string|null, bloqueado: bool, motivo_bloqueo: string|null} $datos */
    public function guardarLimites(Client $cliente, array $datos, User $usuario): CreditoClienteLimite
    {
        return DB::transaction(function () use ($cliente, $datos, $usuario): CreditoClienteLimite {
            $actual = CreditoClienteLimite::where('cliente_id', $cliente->id)->lockForUpdate()->first();
            $campos = ['max_creditos_activos', 'deuda_maxima', 'bloqueado', 'motivo_bloqueo'];
            $antes = $actual?->only($campos);
            $limite = CreditoClienteLimite::updateOrCreate(
                ['cliente_id' => $cliente->id],
                [...$datos, 'motivo_bloqueo' => $datos['bloqueado'] ? $datos['motivo_bloqueo'] : null, 'actualizado_por' => $usuario->id],
            );
            $this->auditoria->registrar('cliente.limites', $cliente, null, $antes, $limite->only($campos), $datos['motivo_bloqueo'] ?? null, $usuario);

            return $limite;
        });
    }

    private function puedeSerAsesor(User $usuario): bool
    {
        return $usuario->can(self::PERMISO_COLOCAR)
            && (! CreditoConfiguracion::actual()->asesor_cobra || $usuario->can(self::PERMISO_COBRAR));
    }

    /** @return array<string, CarteraAsignacion|null> vigentes por función, bloqueadas hasta el commit */
    private function vigentesBloqueadas(Client $cliente): array
    {
        $vigentes = CarteraAsignacion::vigentes()->delCliente($cliente->id)->lockForUpdate()->get()
            ->keyBy(static fn (CarteraAsignacion $a): string => $a->funcion->value);

        return [
            FuncionCartera::Asesor->value => $vigentes[FuncionCartera::Asesor->value] ?? null,
            FuncionCartera::Cobrador->value => $vigentes[FuncionCartera::Cobrador->value] ?? null,
        ];
    }

    private function cambiar(Client $cliente, FuncionCartera $funcion, ?CarteraAsignacion $vigente, ?User $usuario, User $asignador): void
    {
        if ($vigente?->usuario_id === $usuario?->id) {
            return;
        }
        $hoy = $this->reloj->hoy()->aTexto();
        $vigente?->update(['vigente_hasta' => $hoy]);
        if ($usuario !== null) {
            CarteraAsignacion::create([
                'usuario_id' => $usuario->id,
                'funcion' => $funcion,
                'tipo' => ModoAsignacionCartera::Cliente,
                'referencia_id' => $cliente->id,
                'vigente_desde' => $hoy,
                'asignado_por' => $asignador->id,
            ]);
        }
    }
}
