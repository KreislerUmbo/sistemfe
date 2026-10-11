<?php

declare(strict_types=1);

namespace App\Mail\Creditos;

use App\Services\Creditos\Motor\Fecha;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Aviso diario de cobranza (Fase 5). Se envía síncrono desde el comando (no ShouldQueue): así
 * el registro de credito_avisos sabe en el momento si salió o falló, y el PDF adjunto no
 * viaja serializado en la cola.
 */
class AvisoCobranzaDiaMail extends Mailable
{
    /**
     * @param array{cuotas: int, clientes: int, monto: string, atrasados: int} $resumen
     * @param list<array{cobrador: string, cuotas: int, monto: string}> $porCobrador
     */
    public function __construct(
        public readonly string $empresa,
        public readonly ?string $correoEmpresa,
        public readonly string $usuario,
        public readonly Fecha $fecha,
        public readonly array $resumen,
        public readonly array $porCobrador,
        public readonly string $urlAgenda,
        public readonly ?string $pdf,
        public readonly bool $prueba = false,
    ) {
    }

    public function envelope(): Envelope
    {
        $asunto = $this->resumen['cuotas'] > 0
            ? "Hoy: {$this->resumen['cuotas']} cobros · S/ " . self::soles($this->resumen['monto'])
            : 'Hoy no hay cobros programados';

        return new Envelope(
            from: new Address((string) config('mail.from.address'), $this->empresa),
            replyTo: $this->correoEmpresa ? [new Address($this->correoEmpresa, $this->empresa)] : [],
            subject: ($this->prueba ? '[Prueba] ' : '') . $asunto . ' — ' . $this->fechaCorta(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.creditos.aviso-cobranza-dia',
            with: [
                'empresa' => $this->empresa,
                'usuario' => $this->usuario,
                'fechaLarga' => CarbonImmutable::parse($this->fecha->aTexto())->locale('es')->isoFormat('dddd D [de] MMMM'),
                'resumen' => $this->resumen,
                'monto' => self::soles($this->resumen['monto']),
                // Otro nombre que la propiedad: Laravel pasa las propiedades públicas a la vista por
                // encima de "with", y pisaría la lista ya formateada.
                'cobradores' => array_map(static fn (array $c): array => [...$c, 'monto' => self::soles($c['monto'])], $this->porCobrador),
                'urlAgenda' => $this->urlAgenda,
                'conAdjunto' => $this->pdf !== null,
                'prueba' => $this->prueba,
            ],
        );
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        if ($this->pdf === null) {
            return [];
        }
        $pdf = $this->pdf;

        return [Attachment::fromData(static fn (): string => $pdf, "agenda_cobranza_{$this->fecha->aTexto()}.pdf")->withMime('application/pdf')];
    }

    private function fechaCorta(): string
    {
        return CarbonImmutable::parse($this->fecha->aTexto())->format('d/m/Y');
    }

    private static function soles(string $monto): string
    {
        return number_format((float) $monto, 2, '.', ',');
    }
}
