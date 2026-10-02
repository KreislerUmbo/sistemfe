<?php

declare(strict_types=1);

namespace App\Enums\Creditos;

/** Datos de la ficha del cliente que se pueden exigir para activar un crédito (04c). */
enum RequisitoFicha: string
{
    case DniAnverso = 'dni_anverso';
    case DniReverso = 'dni_reverso';
    case FotoCliente = 'foto_cliente';
    case Telefono = 'telefono';
    case DireccionCobro = 'direccion_cobro';
    case Referencia = 'referencia';
    case Ubicacion = 'ubicacion';
    case Ocupacion = 'ocupacion';

    /** Lo que se exige si el negocio no cambia la configuración. */
    public const DEFAULT = ['dni_anverso', 'dni_reverso', 'foto_cliente', 'direccion_cobro', 'ubicacion'];

    public function etiqueta(): string
    {
        return match ($this) {
            self::DniAnverso => 'DNI (anverso)',
            self::DniReverso => 'DNI (reverso)',
            self::FotoCliente => 'Foto del cliente',
            self::Telefono => 'Teléfono',
            self::DireccionCobro => 'Dirección de cobro',
            self::Referencia => 'Referencia de la dirección',
            self::Ubicacion => 'Ubicación en el mapa',
            self::Ocupacion => 'Ocupación o negocio',
        };
    }
}
