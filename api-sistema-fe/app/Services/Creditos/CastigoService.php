<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Enums\Creditos\CreditoEstado;
use App\Enums\Creditos\TipoCastigo;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoCastigo;
use App\Models\User;
use App\Services\Creditos\Motor\Fecha;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Castigo y reversión (00 1.11). Castigar no toca acumulados: la mora deja de correr porque el
 * cargador pasa los períodos de credito_castigos al motor; revertir cierra el período y la mora
 * se reanuda sin retroactivo.
 */
class CastigoService
{
    public function __construct(
        private readonly AuditoriaCredito $auditoria,
        private readonly Reloj $reloj,
    ) {
    }

    public function castigar(Credito $credito, TipoCastigo $tipo, ?string $motivo, ?User $usuario, ?Fecha $fecha = null): CreditoCastigo
    {
        return DB::transaction(function () use ($credito, $tipo, $motivo, $usuario, $fecha): CreditoCastigo {
            $credito = Credito::whereKey($credito->id)->lockForUpdate()->firstOrFail();
            if ($credito->estado !== CreditoEstado::Activo) {
                throw new HttpException(422, 'Solo se castiga un crédito activo.');
            }
            $fecha ??= $this->reloj->hoy();

            $castigo = CreditoCastigo::create([
                'credito_id' => $credito->id,
                'fecha_castigo' => $fecha->aTexto(),
                'tipo' => $tipo,
                'motivo' => $motivo,
                'castigado_por' => $usuario?->id,
            ]);
            $credito->update(['estado' => CreditoEstado::Castigado, 'fecha_castigo' => $fecha->aTexto()]);
            $this->auditoria->registrar("credito.castigar.{$tipo->value}", $credito, $credito->id, ['estado' => 'activo'], ['estado' => 'castigado', 'fecha_castigo' => $fecha->aTexto()], $motivo, $usuario);

            return $castigo;
        });
    }

    public function revertir(Credito $credito, string $motivo, User $usuario): CreditoCastigo
    {
        return DB::transaction(function () use ($credito, $motivo, $usuario): CreditoCastigo {
            $credito = Credito::whereKey($credito->id)->lockForUpdate()->firstOrFail();
            $castigo = $credito->castigos()->whereNull('fecha_reversion')->latest('id')->first();
            if ($credito->estado !== CreditoEstado::Castigado || $castigo === null) {
                throw new HttpException(422, 'El crédito no está castigado.');
            }
            $hoy = $this->reloj->hoy()->aTexto();

            $castigo->update(['fecha_reversion' => $hoy, 'motivo_reversion' => $motivo, 'revertido_por' => $usuario->id]);
            $credito->update(['estado' => CreditoEstado::Activo, 'fecha_castigo' => null]);
            $this->auditoria->registrar('credito.revertir_castigo', $credito, $credito->id, ['estado' => 'castigado'], ['estado' => 'activo', 'fecha_reversion' => $hoy], $motivo, $usuario);

            return $castigo->refresh();
        });
    }
}
