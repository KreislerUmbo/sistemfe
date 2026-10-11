<?php

declare(strict_types=1);

namespace App\Services\Creditos\Avisos;

use App\Mail\Creditos\AvisoCobranzaDiaMail;
use App\Models\Company;
use App\Models\Creditos\CreditoAviso;
use App\Models\Creditos\CreditoAvisoPreferencia;
use App\Models\Creditos\CreditoConfiguracion;
use App\Models\User;
use App\Services\Creditos\AlcanceCartera;
use App\Services\Creditos\Dinero;
use App\Services\Creditos\Motor\Fecha;
use App\Services\Creditos\Reloj;
use App\Services\Creditos\Reportes\AgendaCobranzaService;
use App\Services\Creditos\Reportes\Periodo;
use App\Services\Creditos\Reportes\ReportePdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

/**
 * Aviso diario "lo que cobras hoy" (Fase 5, 05-avisos-cobranza.md). Corre dentro del tenant.
 * Cada usuario que cobra (o ve toda la cartera) recibe su agenda del día — lo que vence hoy y,
 * según la configuración, lo atrasado — con el mismo alcance que la pantalla Agenda
 * (AgendaCobranzaService + AlcanceCartera). Con creditos.avisos_pdf_adjunto el PDF va adjunto;
 * sin él, un enlace a la Agenda que exige iniciar sesión.
 *
 * credito_avisos hace la corrida idempotente: un usuario recibe un solo aviso por día y canal
 * aunque el comando corra cada 15 minutos; un envío fallido se reintenta en las corridas
 * siguientes del mismo día hasta CreditoAviso::MAXIMO_INTENTOS.
 */
class AvisoCobranzaService
{
    public const PERMISO_ADJUNTO = 'creditos.avisos_pdf_adjunto';
    private const PERMISO_COBRAR = 'creditos.cobrar';
    /** Lunes a sábado: los domingos no se cobra salvo que el negocio lo configure. */
    private const DIAS_POR_DEFECTO = [1, 2, 3, 4, 5, 6];
    private const USUARIO_INACTIVO = User::STATE_INACTIVO;

    public function __construct(
        private readonly AgendaCobranzaService $agenda,
        private readonly ReportePdf $reportePdf,
        private readonly Reloj $reloj,
    ) {
    }

    /**
     * Corrida programada del tenant actual.
     *
     * @return array{corrio: bool, motivo: string|null, enviados: int, omitidos: int, fallidos: int}
     */
    public function ejecutar(string $urlAgenda): array
    {
        $resultado = ['corrio' => false, 'motivo' => null, 'enviados' => 0, 'omitidos' => 0, 'fallidos' => 0];
        $config = CreditoConfiguracion::actual();
        $motivo = $this->motivoParaNoCorrer($config, $this->reloj->ahora());
        if ($motivo !== null) {
            return [...$resultado, 'motivo' => $motivo];
        }

        $hoy = $this->reloj->hoy();
        foreach ($this->destinatarios() as $usuario) {
            $estado = $this->avisar($usuario, $hoy, $config, $urlAgenda);
            match ($estado) {
                CreditoAviso::ESTADO_ENVIADO => $resultado['enviados']++,
                CreditoAviso::ESTADO_OMITIDO => $resultado['omitidos']++,
                CreditoAviso::ESTADO_FALLIDO => $resultado['fallidos']++,
                default => null,   // ya resuelto en una corrida anterior
            };
        }

        return [...$resultado, 'corrio' => true];
    }

    /** null si toca enviar ahora; si no, el motivo. */
    public function motivoParaNoCorrer(CreditoConfiguracion $config, CarbonImmutable $ahora): ?string
    {
        if (! $config->avisos_activo) {
            return 'avisos apagados';
        }
        $dias = $config->avisos_dias ?: self::DIAS_POR_DEFECTO;
        if (! in_array($ahora->dayOfWeekIso, array_map('intval', $dias), true)) {
            return 'hoy no es día de aviso';
        }
        if ($ahora->format('H:i') < $config->avisos_hora) {
            return 'todavía no es la hora (' . $config->avisos_hora . ')';
        }

        return null;
    }

    /**
     * Usuarios activos que cobran o ven toda la cartera. El Super-Admin queda fuera: es el
     * soporte de la plataforma, no personal del negocio (aunque can() le dé todo).
     *
     * @return Collection<int, User>
     */
    public function destinatarios(): Collection
    {
        return User::where(static fn ($q) => $q->whereNull('state')->orWhere('state', '!=', self::USUARIO_INACTIVO))
            ->orderBy('id')->get()
            ->reject(static fn (User $u): bool => $u->hasRole('Super-Admin'))
            ->filter(static fn (User $u): bool => $u->can(self::PERMISO_COBRAR) || $u->can(AlcanceCartera::PERMISO_VER_TODOS))
            ->values();
    }

    /**
     * Aviso por correo de un usuario para el día. Devuelve el estado final, o null si ese día ya
     * estaba resuelto. Con $prueba no registra nada ni respeta lo ya enviado (botón "Enviar
     * prueba"): devuelve enviado / omitido / fallido igual.
     */
    public function avisar(User $usuario, Fecha $hoy, CreditoConfiguracion $config, string $urlAgenda, bool $prueba = false): ?string
    {
        $aviso = $prueba ? null : $this->reservar($usuario, $hoy);
        if (! $prueba && $aviso === null) {
            return null;
        }

        $agenda = $this->agenda->agenda($usuario, Periodo::dia($hoy), (bool) $config->avisos_incluir_atrasados);
        $resumen = $this->resumen($agenda);
        $verTodos = $usuario->can(AlcanceCartera::PERMISO_VER_TODOS);
        $correo = CreditoAvisoPreferencia::de($usuario)->correoUtilizable();

        $motivo = match (true) {
            // La prueba siempre llega, aunque hoy no haya cobros: es para ver cómo se ve.
            $resumen['cuotas'] === 0 && ! $prueba && ! ($verTodos && $config->avisos_sin_cobros) => 'sin cobros hoy',
            $correo === null => 'sin correo verificado o con el correo apagado',
            default => null,
        };
        if ($motivo !== null) {
            return $this->cerrar($aviso, CreditoAviso::ESTADO_OMITIDO, $resumen, $motivo);
        }

        try {
            $adjunto = $resumen['cuotas'] > 0 && $usuario->can(self::PERMISO_ADJUNTO)
                ? $this->reportePdf->generar('agenda', $agenda, $usuario, $this->reloj->ahora(), confidencial: true)->output()
                : null;
            $empresa = Company::first();
            Mail::to($correo)->send(new AvisoCobranzaDiaMail(
                empresa: $empresa?->razon_social ?: 'Créditos',
                correoEmpresa: $empresa?->email ?: null,
                usuario: $usuario->name,
                fecha: $hoy,
                resumen: $resumen,
                porCobrador: $verTodos ? $this->porCobrador($agenda) : [],
                urlAgenda: $urlAgenda,
                pdf: $adjunto,
                prueba: $prueba,
            ));
        } catch (\Throwable $e) {
            Log::error('Aviso de cobranza: falló el envío', ['usuario' => $usuario->id, 'error' => $e->getMessage()]);

            return $this->cerrar($aviso, CreditoAviso::ESTADO_FALLIDO, $resumen, mb_substr($e->getMessage(), 0, 500));
        }

        return $this->cerrar($aviso, CreditoAviso::ESTADO_ENVIADO, $resumen, null);
    }

    /**
     * Toma (o retoma) el aviso del día. null = ya enviado u omitido, o fallido sin intentos.
     */
    private function reservar(User $usuario, Fecha $hoy): ?CreditoAviso
    {
        $aviso = CreditoAviso::firstOrCreate(
            ['user_id' => $usuario->id, 'tipo' => CreditoAviso::TIPO_COBRANZA_DIA, 'fecha' => $hoy->aTexto(), 'canal' => CreditoAviso::CANAL_CORREO],
            ['estado' => CreditoAviso::ESTADO_PENDIENTE],
        );
        if (in_array($aviso->estado, [CreditoAviso::ESTADO_ENVIADO, CreditoAviso::ESTADO_OMITIDO], true)
            || $aviso->intentos >= CreditoAviso::MAXIMO_INTENTOS) {
            return null;
        }
        $aviso->increment('intentos');

        return $aviso;
    }

    /** @param array{cuotas: int, clientes: int, monto: string, atrasados: int} $resumen */
    private function cerrar(?CreditoAviso $aviso, string $estado, array $resumen, ?string $detalle): string
    {
        $aviso?->update([
            'estado' => $estado,
            'detalle' => $detalle,
            'cantidad_cobros' => $resumen['cuotas'],
            'monto_esperado' => $resumen['monto'],
            'enviado_en' => $estado === CreditoAviso::ESTADO_ENVIADO ? now() : null,
        ]);

        return $estado;
    }

    /**
     * Totales del aviso: cuotas, clientes y monto (cuota + mora) de la agenda, y cuántas cuotas
     * están atrasadas (el grupo sin día de la agenda).
     *
     * @param array<string, mixed> $agenda
     * @return array{cuotas: int, clientes: int, monto: string, atrasados: int}
     */
    public function resumen(array $agenda): array
    {
        $atrasados = collect($agenda['dias'])->firstWhere('dia', null);

        return [
            'cuotas' => (int) $agenda['totales']['cuotas'],
            'clientes' => (int) $agenda['totales']['clientes'],
            'monto' => (string) $agenda['totales']['monto'],
            'atrasados' => (int) ($atrasados['totales']['cuotas'] ?? 0),
        ];
    }

    /**
     * Desglose por cobrador para quien ve toda la cartera.
     *
     * @param array<string, mixed> $agenda
     * @return list<array{cobrador: string, cuotas: int, monto: string}>
     */
    public function porCobrador(array $agenda): array
    {
        $porCobrador = [];
        foreach ($agenda['dias'] as $dia) {
            foreach ($dia['cobradores'] as $c) {
                $porCobrador[$c['cobrador']]['cuotas'] = ($porCobrador[$c['cobrador']]['cuotas'] ?? 0) + (int) $c['totales']['cuotas'];
                $porCobrador[$c['cobrador']]['centavos'] = ($porCobrador[$c['cobrador']]['centavos'] ?? 0) + Dinero::aCentavos($c['totales']['monto']);
            }
        }
        ksort($porCobrador);

        return array_map(
            static fn (string $nombre, array $t): array => ['cobrador' => $nombre, 'cuotas' => $t['cuotas'], 'monto' => Dinero::aSoles($t['centavos'])],
            array_keys($porCobrador),
            array_values($porCobrador),
        );
    }
}
