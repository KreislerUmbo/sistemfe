<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\SalidaOperativa;
use App\Models\AgenciaViajes\SalidaServicio;
use App\Models\AgenciaViajes\SalidaVehiculo;
use App\Models\Company;
use Illuminate\Support\Collection;

// Operación de una salida — fila 13, fase 2c (plan-tours-venta-simple-costo-real.md
// §3.13, D14-D16).
//
// - foto(): lo que hay que avisar a los proveedores de una salida en un
//   momento dado (servicios con su cantidad, vehículos con sus pasajeros).
// - avisos(): compara la foto de antes con la de después de un cambio
//   (cancelar una reserva, reprogramar la salida) y redacta qué ajustar con
//   cada proveedor ("El Mirador (Almuerzo): ahora son 6, no 9").
// - ordenes(): la orden de servicio de cada proveedor, con el texto listo
//   para WhatsApp. Datos mínimos (Ley 29733): restaurante y entradas reciben
//   cantidades y alimentación especial sin nombres; el transportista, por
//   vehículo, las reservas con titular y pasajeros, sin documentos.
class SalidaOperacionService
{
    private const RELACIONES = [
        'tourOrigen',
        'servicios.proveedor',
        'vehiculos.proveedor',
        'vehiculos.reservas',
        'reservaItems.reserva.pasajeros',
        'reservaItems.reserva.alternativa.cotizacion.cliente',
    ];

    /**
     * Foto de la salida para comparar antes/después. null si ya no existe.
     */
    public function foto(int $salidaId): ?array
    {
        $salida = SalidaOperativa::with(self::RELACIONES)->find($salidaId);
        if (! $salida) {
            return null;
        }

        $reservas = $this->reservasActivas($salida);
        $paxQueCuentan = $this->ocupan($reservas->flatMap(fn ($r) => $r->pasajeros));

        return [
            'id' => $salida->id,
            'tour' => $salida->tourOrigen?->nombre ?? 'Salida',
            'fecha' => $salida->fecha->format('d/m/Y'),
            'pax' => $paxQueCuentan,
            'minimo' => $salida->tourOrigen?->pax_minimo_salida,
            'servicios' => $salida->servicios->mapWithKeys(fn (SalidaServicio $sv) => [$sv->id => [
                'nombre' => $sv->nombre,
                'paquete_plantilla_item_id' => $sv->paquete_plantilla_item_id,
                'proveedor_id' => $sv->proveedor_id,
                'proveedor' => $this->nombreProveedor($sv->proveedor),
                'cantidad_manual' => $sv->cantidad_manual,
                'cantidad' => $sv->cantidad_manual ?? $paxQueCuentan,
                'notas' => $sv->notas,
            ]])->all(),
            'vehiculos' => $salida->vehiculos->values()->mapWithKeys(fn (SalidaVehiculo $v, int $k) => [$v->id => [
                'nombre' => $v->descripcion ?: 'Vehículo '.($k + 1),
                'proveedor_id' => $v->proveedor_id,
                'proveedor' => $this->nombreProveedor($v->proveedor),
                'datos' => $v->only(['proveedor_id', 'descripcion', 'chofer_nombre', 'chofer_telefono', 'capacidad', 'guia_id', 'notas', 'orden']),
                'reserva_ids' => $v->reservas->pluck('id')->intersect($reservas->pluck('id'))->values()->all(),
                'pasajeros' => $this->ocupan($reservas->whereIn('id', $v->reservas->pluck('id'))->flatMap(fn ($r) => $r->pasajeros)),
            ]])->all(),
        ];
    }

    /**
     * Qué ajustar con cada proveedor después de un cambio.
     *
     * @return array<int, string>
     */
    public function avisos(array $antes, ?array $despues): array
    {
        $avisos = [];

        if ($despues === null) {
            foreach ($antes['servicios'] as $sv) {
                if ($sv['proveedor']) {
                    $avisos[] = "{$sv['proveedor']} ({$sv['nombre']}): la salida del {$antes['fecha']} ya no va.";
                }
            }
            foreach ($antes['vehiculos'] as $v) {
                if ($v['proveedor']) {
                    $avisos[] = "{$v['proveedor']} ({$v['nombre']}): la salida del {$antes['fecha']} ya no va.";
                }
            }

            return $avisos ?: ["La salida del {$antes['fecha']} quedó sin pasajeros y se eliminó."];
        }

        foreach ($antes['servicios'] as $id => $sv) {
            $ahora = $despues['servicios'][$id] ?? null;
            if ($ahora && $sv['proveedor'] && $ahora['cantidad'] !== $sv['cantidad']) {
                $avisos[] = "{$sv['proveedor']} ({$sv['nombre']}): ahora son {$ahora['cantidad']}, no {$sv['cantidad']}.";
            }
        }

        foreach ($antes['vehiculos'] as $id => $v) {
            $ahora = $despues['vehiculos'][$id] ?? null;
            if (! $ahora || $ahora['pasajeros'] === $v['pasajeros']) {
                continue;
            }
            $quien = $v['proveedor'] ? "{$v['proveedor']} ({$v['nombre']})" : $v['nombre'];
            $avisos[] = $ahora['pasajeros'] === 0
                ? "{$quien}: queda vacío."
                : "{$quien}: ahora lleva {$ahora['pasajeros']} pasajero(s), no {$v['pasajeros']}.";
        }

        $minimo = $despues['minimo'];
        if ($minimo !== null && $despues['pax'] < $minimo && $antes['pax'] >= $minimo) {
            $avisos[] = "Quedan {$despues['pax']} pasajero(s): la salida queda por debajo del mínimo ({$minimo}).";
        }

        return $avisos;
    }

    /**
     * Órdenes de servicio de la salida, una por proveedor (D14).
     *
     * @return array<int, array<string, mixed>>
     */
    public function ordenes(SalidaOperativa $salida): array
    {
        $salida->load(self::RELACIONES);
        $reservas = $this->reservasActivas($salida);
        $pasajeros = $reservas->flatMap(fn ($r) => $r->pasajeros);
        $paxQueCuentan = $this->ocupan($pasajeros);
        $resumenPax = $this->resumenPasajeros($pasajeros);
        $alimentacion = $pasajeros->pluck('alimentacion_especial')->map(fn ($a) => trim((string) $a))->filter()
            ->countBy()->all();

        $proveedores = $salida->servicios->pluck('proveedor')
            ->merge($salida->vehiculos->pluck('proveedor'))
            ->filter()->unique('id')->values();

        return $proveedores->map(function (Proveedor $proveedor) use ($salida, $reservas, $paxQueCuentan, $resumenPax, $alimentacion) {
            $servicios = $salida->servicios->where('proveedor_id', $proveedor->id)->map(fn (SalidaServicio $sv) => [
                'nombre' => $sv->nombre,
                'cantidad' => $sv->cantidad_manual ?? $paxQueCuentan,
                'estado' => $sv->estado,
                'notas' => $sv->notas,
            ])->values()->all();

            $vehiculos = $salida->vehiculos->where('proveedor_id', $proveedor->id)->values()->map(function (SalidaVehiculo $v) use ($reservas) {
                $suyas = $reservas->whereIn('id', $v->reservas->pluck('id'));

                return [
                    'descripcion' => $v->descripcion ?: 'Vehículo',
                    'chofer' => $v->chofer_nombre,
                    'capacidad' => $v->capacidad,
                    'reservas' => $suyas->map(fn ($r) => [
                        'codigo' => $r->alternativa?->cotizacion?->codigo ?? "#{$r->id}",
                        'titular' => $r->alternativa?->cotizacion?->cliente?->full_name,
                        'pasajeros' => $this->ocupan($r->pasajeros),
                        'infantes' => $r->pasajeros->where('tipo_pax', 'infante')->count(),
                        'con_discapacidad' => $r->pasajeros->filter(fn ($p) => trim((string) $p->discapacidad) !== '')->count(),
                    ])->values()->all(),
                ];
            })->all();

            $orden = [
                'proveedor_id' => $proveedor->id,
                'proveedor' => $this->nombreProveedor($proveedor),
                'telefono' => $proveedor->whatsapp ?: ($proveedor->celular ?: $proveedor->telefono),
                'servicios' => $servicios,
                'vehiculos' => $vehiculos,
                // El transportista no necesita la alimentación; el resto, sí.
                'pasajeros' => $resumenPax,
                'alimentacion_especial' => $servicios ? $alimentacion : [],
            ];
            $orden['mensaje'] = $this->mensaje($salida, $orden);
            $orden['whatsapp_url'] = $this->enlaceWhatsapp($orden['telefono'], $orden['mensaje']);

            return $orden;
        })->all();
    }

    private function mensaje(SalidaOperativa $salida, array $orden): string
    {
        $empresa = Company::first();
        $agencia = $empresa?->razon_social_comercial ?: ($empresa?->razon_social ?: 'la agencia');
        $hora = $salida->hora ? ' a las '.substr((string) $salida->hora, 0, 5) : '';

        $lineas = [
            "Hola, {$orden['proveedor']}. Le escribe {$agencia}.",
            "Orden de servicio — {$salida->tourOrigen?->nombre}, {$salida->fecha->format('d/m/Y')}{$hora}.",
        ];
        if ($salida->lugar_recojo) {
            $lineas[] = "Recojo: {$salida->lugar_recojo}.";
        }
        if ($salida->estado === SalidaOperativa::ESTADO_PROGRAMADA) {
            $lineas[] = '(Salida todavía por confirmar.)';
        }

        if ($orden['servicios']) {
            $lineas[] = '';
            foreach ($orden['servicios'] as $sv) {
                $lineas[] = "• {$sv['nombre']}: {$sv['cantidad']}".($sv['notas'] ? " ({$sv['notas']})" : '');
            }
            $lineas[] = 'Pasajeros: '.$this->textoPasajeros($orden['pasajeros']).'.';
            if ($orden['alimentacion_especial']) {
                $lineas[] = 'Alimentación especial: '.collect($orden['alimentacion_especial'])
                    ->map(fn ($n, $tipo) => "{$n} {$tipo}")->implode(', ').'.';
            }
        }

        foreach ($orden['vehiculos'] as $v) {
            $lineas[] = '';
            $lineas[] = "Vehículo: {$v['descripcion']}".($v['chofer'] ? " — chofer {$v['chofer']}" : '');
            foreach ($v['reservas'] as $r) {
                $detalle = "{$r['pasajeros']} pax".($r['infantes'] ? " + {$r['infantes']} infante(s)" : '')
                    .($r['con_discapacidad'] ? ", {$r['con_discapacidad']} con necesidades especiales" : '');
                $lineas[] = "• {$r['codigo']}".($r['titular'] ? " ({$r['titular']})" : '').": {$detalle}";
            }
        }

        $lineas[] = '';
        $lineas[] = '¿Nos confirma, por favor? Gracias.';

        return implode("\n", $lineas);
    }

    // wa.me exige el número en formato internacional, solo dígitos. Un
    // celular peruano de 9 dígitos (empieza con 9) lleva el 51 delante.
    private function enlaceWhatsapp(?string $telefono, string $mensaje): ?string
    {
        $digitos = preg_replace('/\D+/', '', (string) $telefono);
        if ($digitos === '') {
            return null;
        }
        if (strlen($digitos) === 9 && str_starts_with($digitos, '9')) {
            $digitos = '51'.$digitos;
        }

        return 'https://wa.me/'.$digitos.'?text='.rawurlencode($mensaje);
    }

    private function reservasActivas(SalidaOperativa $salida): Collection
    {
        return $salida->reservaItems->pluck('reserva')->filter()
            ->reject(fn ($r) => $r->estado === 'cancelada')
            ->unique('id')->values();
    }

    // Adultos y niños: el infante no ocupa lugar ni cuenta para cantidades.
    private function ocupan(Collection $pasajeros): int
    {
        return $pasajeros->where('tipo_pax', '!=', 'infante')->count();
    }

    private function resumenPasajeros(Collection $pasajeros): array
    {
        $ext = fn ($p) => $p->residencia === CotizacionPasajero::RESIDENCIA_EXTRANJERO;

        return [
            'adultos' => $pasajeros->where('tipo_pax', 'adulto')->count(),
            'adultos_extranjeros' => $pasajeros->where('tipo_pax', 'adulto')->filter($ext)->count(),
            'ninos' => $pasajeros->where('tipo_pax', 'nino')->count(),
            'ninos_extranjeros' => $pasajeros->where('tipo_pax', 'nino')->filter($ext)->count(),
            'infantes' => $pasajeros->where('tipo_pax', 'infante')->count(),
        ];
    }

    private function textoPasajeros(array $p): string
    {
        $partes = [];
        if ($p['adultos']) {
            $partes[] = "{$p['adultos']} adulto(s)".($p['adultos_extranjeros'] ? " ({$p['adultos_extranjeros']} extranjero(s))" : '');
        }
        if ($p['ninos']) {
            $partes[] = "{$p['ninos']} niño(s)".($p['ninos_extranjeros'] ? " ({$p['ninos_extranjeros']} extranjero(s))" : '');
        }
        if ($p['infantes']) {
            $partes[] = "{$p['infantes']} infante(s)";
        }

        return $partes ? implode(', ', $partes) : 'ninguno';
    }

    private function nombreProveedor(?Proveedor $proveedor): ?string
    {
        return $proveedor ? ($proveedor->nombre_comercial ?: $proveedor->razon_social) : null;
    }
}
