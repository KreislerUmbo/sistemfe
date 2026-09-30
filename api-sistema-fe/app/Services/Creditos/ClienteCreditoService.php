<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Enums\Creditos\ModoAsignacionCartera;
use App\Enums\Creditos\TipoArchivoCliente;
use App\Models\Client\Client;
use App\Models\Creditos\CarteraAsignacion;
use App\Models\Creditos\CreditoClienteArchivo;
use App\Models\Creditos\CreditoClienteFicha;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Datos de cobranza del cliente (00 1.14 y ficha de cobro): ficha, archivos (DNI, foto) en el
 * disco privado del tenant y asignación de cobrador con historial (03-api decisión 4).
 */
class ClienteCreditoService
{
    public const DISCO = 'private';
    /** Fotos tomadas con el celular: se reducen antes de guardarlas. */
    private const LADO_MAXIMO_PX = 1600;
    private const CALIDAD_JPEG = 80;
    private const PERMISO_COBRAR = 'creditos.cobrar';

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

    /** Cierra la asignación vigente (no la borra) y abre la nueva; null = queda sin cobrador. */
    public function asignarCobrador(Client $cliente, ?User $cobrador, User $asignador): ?CarteraAsignacion
    {
        if ($cobrador !== null && ! $cobrador->can(self::PERMISO_COBRAR)) {
            throw new HttpException(422, 'El usuario elegido no tiene permiso para cobrar créditos.');
        }

        return DB::transaction(function () use ($cliente, $cobrador, $asignador): ?CarteraAsignacion {
            $hoy = $this->reloj->hoy()->aTexto();
            $vigente = CarteraAsignacion::vigentes()
                ->where('tipo', ModoAsignacionCartera::Cliente)
                ->where('referencia_id', $cliente->id)
                ->lockForUpdate()
                ->first();
            if ($vigente?->cobrador_id === $cobrador?->id) {
                return $vigente;
            }
            $vigente?->update(['vigente_hasta' => $hoy]);

            $nueva = $cobrador === null ? null : CarteraAsignacion::create([
                'cobrador_id' => $cobrador->id,
                'tipo' => ModoAsignacionCartera::Cliente,
                'referencia_id' => $cliente->id,
                'vigente_desde' => $hoy,
                'asignado_por' => $asignador->id,
            ]);
            $this->auditoria->registrar('cartera.asignar', $cliente, null, ['cobrador_id' => $vigente?->cobrador_id], ['cobrador_id' => $cobrador?->id], null, $asignador);

            return $nueva;
        });
    }
}
