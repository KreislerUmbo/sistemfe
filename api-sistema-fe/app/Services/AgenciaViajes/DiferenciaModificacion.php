<?php

namespace App\Services\AgenciaViajes;

// Resultado de ModificacionDiferenciaService::calcular() (T-003). Solo datos: arreglos con todo
// el dinero en CENTAVOS enteros. No escribe nada en la reserva.
//
// lineas:    una por servicio (versión nueva) y una por servicio de la reserva que se quita.
//            estado: igual | cambio_precio | agregado | quitado | para_revisar |
//            requiere_nota_credito | ajuste.
// pasajeros: igual | agregado | quitado | requiere_nota_credito (quitado y facturado);
//            un quitado sin facturar lleva falta_penalidad = true mientras no tenga su penalidad
//            (T-004: la penalidad sale como una línea `agregado`). penalidad_sobrante_item_id =
//            la penalidad de un pasajero que ya no se quita: bloquea hasta que se borre.
// totales:   vendido, nuevo, diferencia, ya_facturado, anticipos_aplicados.
// bloqueos:  motivos por los que no se puede aplicar (R-AGE-06), cada uno con a dónde lleva.
// huella:    hash estable de todo lo anterior; T-005 lo compara con el que vio el usuario.
final class DiferenciaModificacion
{
    /**
     * @param  array<int, array<string, mixed>>  $lineas
     * @param  array<int, array<string, mixed>>  $pasajeros
     * @param  array<string, int>  $totales
     * @param  array<int, array<string, mixed>>  $bloqueos
     */
    public function __construct(
        public readonly string $moneda,
        public readonly array $lineas,
        public readonly array $pasajeros,
        public readonly array $totales,
        public readonly array $bloqueos,
        public readonly bool $hayCambios,
        public readonly string $huella,
    ) {
    }

    // Sin cambios también es "nada que aplicar".
    public function sePuedeAplicar(): bool
    {
        return $this->hayCambios && $this->bloqueos === [];
    }

    public function toArray(): array
    {
        return [
            'moneda' => $this->moneda,
            'lineas' => $this->lineas,
            'pasajeros' => $this->pasajeros,
            'totales' => $this->totales,
            'bloqueos' => $this->bloqueos,
            'hay_cambios' => $this->hayCambios,
            'se_puede_aplicar' => $this->sePuedeAplicar(),
            'huella' => $this->huella,
        ];
    }
}
