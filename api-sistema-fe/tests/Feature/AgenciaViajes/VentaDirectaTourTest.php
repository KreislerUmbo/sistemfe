<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Http\Controllers\AgenciaViajes\ReservaFacturacionController;
use App\Http\Controllers\AgenciaViajes\VentaDirectaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaDestino;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use App\Models\AgenciaViajes\PaquetePlantillaPrecio;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorServicio;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\SalidaOperativa;
use App\Models\AgenciaViajes\Temporada;
use App\Models\AgenciaViajes\TemporadaOcurrencia;
use App\Services\AgenciaViajes\TemporadaResolverService;
use App\Services\HoraPeru;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AgenciaViajes\Concerns\SalidasFixtures;
use Tests\TestCase;

// T-047 — Venta directa vende un tour del catálogo (origen_tipo='tour'),
// enganchado a la salida de su día. Postgres real, transacción por test revertida.
class VentaDirectaTourTest extends TestCase
{
    use SalidasFixtures;

    private const INICIO = '2026-11-15';

    private function crearCliente(): int
    {
        return DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10000000, 99999999), 'full_name' => 'Cliente Tour',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function lineaTour(PaquetePlantilla $tour, int $dia = 1): array
    {
        return ['origen_tipo' => 'tour', 'dia' => $dia, 'paquete_plantilla_id' => $tour->id];
    }

    private function datos(array $lineas, array $pax, array $extra = []): array
    {
        return $extra + [
            'cliente_id' => $this->crearCliente(), 'destino' => 'Tarapoto', 'fecha_servicio' => self::INICIO,
            'pax' => $pax, 'lineas' => $lineas,
        ];
    }

    private function crear(array $lineas, array $pax, array $extra = []): array
    {
        return app(VentaDirectaController::class)->store(new Request($this->datos($lineas, $pax, $extra)))->getData(true);
    }

    private function vistaPrevia(array $lineas, array $pax, array $extra = []): array
    {
        return app(VentaDirectaController::class)->vistaPrevia(new Request($extra + [
            'fecha_servicio' => self::INICIO, 'pax' => $pax, 'lineas' => $lineas,
        ]))->getData(true);
    }

    private function totalCreado(array $body): float
    {
        return round((float) AlternativaItem::where('alternativa_id', $body['alternativa']['id'])->get()->sum('total_convertido'), 2);
    }

    // Temporadas en memoria (el catálogo de temporadas es central y no existe en la base de pruebas).
    private function temporadas(array $definiciones): void
    {
        $ocurrencias = [];
        foreach ($definiciones as $k => [$temporadaId, $nombre, $desde, $hasta]) {
            $o = new TemporadaOcurrencia(['temporada_id' => $temporadaId, 'anio' => 2026, 'fecha_desde' => $desde, 'fecha_hasta' => $hasta]);
            $o->id = 1000 + $k;
            $o->setRelation('temporada', (new Temporada)->forceFill(['id' => $temporadaId, 'nombre' => $nombre]));
            $ocurrencias[] = $o;
        }

        app()->instance(TemporadaResolverService::class, new class($ocurrencias) extends TemporadaResolverService
        {
            public function __construct(private array $ocurrencias)
            {
            }

            public function resolver(string $fecha, ?array $temporadaIds = null): ?TemporadaOcurrencia
            {
                if ($temporadaIds === []) {
                    return null;
                }

                return $this->elegir(array_filter(
                    $this->ocurrencias,
                    fn (TemporadaOcurrencia $o) => $temporadaIds === null || in_array($o->temporada_id, $temporadaIds, true)
                ), $fecha);
            }
        });
    }

    private function tarifaCompartida(float $costo, float $venta): ProveedorTarifa
    {
        $proveedor = Proveedor::create(['razon_social' => 'Proveedor Test '.uniqid(), 'estado' => true]);
        $servicio = \App\Models\AgenciaViajes\Servicio::create(['nombre' => 'Transporte '.uniqid()]);
        $destinoServicio = \App\Models\AgenciaViajes\DestinoServicio::create(['destino_atractivo_id' => $this->destino->id, 'servicio_id' => $servicio->id]);
        $proveedorServicio = ProveedorServicio::create(['proveedor_id' => $proveedor->id, 'destino_servicio_id' => $destinoServicio->id]);

        return ProveedorTarifa::create([
            'proveedor_servicio_id' => $proveedorServicio->id, 'tipo_tarifa' => 'publica', 'modalidad' => 'compartido',
            'moneda' => 'PEN', 'precio_costo' => $costo, 'margen_tipo' => 'porcentaje', 'margen_valor' => 20,
            'precio_venta_adulto' => $venta, 'precio_venta_nino' => $venta / 2, 'vigente_desde' => HoraPeru::hoyTexto(),
            'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
        ]);
    }

    // CA1 — precio nacional 100 / extranjero 150; 2 nacionales + 1 extranjero → 350 y una línea de tour.
    public function test_ca1_modo_nuevo_precio_por_residencia_y_una_linea_de_tour(): void
    {
        $tour = $this->crearTour(['precio_venta_final' => 100, 'precio_venta_adulto_ext' => 150, 'costo_estimado_persona' => 60]);
        $pax = [['edad' => 30], ['edad' => 40], ['edad' => 35, 'residencia' => 'extranjero']];

        $previa = $this->vistaPrevia([$this->lineaTour($tour)], $pax);
        $this->assertSame(200, $previa['code'], json_encode($previa));
        $this->assertEqualsWithDelta(350.0, $previa['total'], 0.001);
        $this->assertEqualsWithDelta(350.0, $previa['lineas'][0]['total'], 0.001);
        $this->assertSame([['grupo' => 'adulto_nac', 'cantidad' => 2, 'precio' => 100], ['grupo' => 'adulto_ext', 'cantidad' => 1, 'precio' => 150]], $previa['lineas'][0]['tour']['detalle']);
        $this->assertTrue($previa['facturable']['ok']);

        $body = $this->crear([$this->lineaTour($tour)], $pax);

        $this->assertSame(200, $body['code'], $body['message'] ?? '');
        $lineas = AlternativaItem::where('alternativa_id', $body['alternativa']['id'])->where('origen_tipo', 'tour')->get();
        $this->assertCount(1, $lineas);
        $this->assertEqualsWithDelta(350.0, $this->totalCreado($body), 0.001);
        $this->assertEqualsWithDelta($previa['total'], $this->totalCreado($body), 0.001); // lo que se ve es lo que se crea
        $this->assertSame(1, ReservaItem::where('reserva_id', $body['reserva']['id'])->count());
        $this->assertSame([], $body['avisos_salida_cancelada']);
    }

    // CA2 — la temporada sale de la FECHA de la línea (inicio + día − 1), no de la de inicio.
    public function test_ca2_la_temporada_sale_de_la_fecha_de_la_linea(): void
    {
        $this->temporadas([[77, 'Temporada Alta', '2026-11-16', '2026-11-30']]);
        $tour = $this->crearTour(['precio_venta_final' => 100]);
        PaquetePlantillaPrecio::create(['paquete_plantilla_id' => $tour->id, 'temporada_id' => 77, 'precio_venta_adulto' => 200]);
        $pax = [['edad' => 30]];

        // Día 1 = 15-nov (fuera de la temporada) → 100; día 2 = 16-nov (dentro) → 200.
        $this->assertEqualsWithDelta(100.0, $this->vistaPrevia([$this->lineaTour($tour, 1)], $pax)['total'], 0.001);
        $previaAlta = $this->vistaPrevia([$this->lineaTour($tour, 2)], $pax);
        $this->assertEqualsWithDelta(200.0, $previaAlta['total'], 0.001);
        $this->assertSame('Temporada Alta', $previaAlta['lineas'][0]['tour']['temporada']);

        $body = $this->crear([$this->lineaTour($tour, 2)], $pax);
        $this->assertSame(200, $body['code'], $body['message'] ?? '');
        $this->assertEqualsWithDelta(200.0, $this->totalCreado($body), 0.001);
        $this->assertSame(77, AlternativaItem::where('alternativa_id', $body['alternativa']['id'])->value('temporada_id'));
    }

    // CA3 — la reserva queda en la salida del tour y de SU fecha; otra venta del mismo día se suma a esa salida.
    public function test_ca3_la_reserva_se_engancha_a_la_salida_de_su_fecha_y_se_suma_a_una_existente(): void
    {
        $tour = $this->crearTour();
        $pax = [['edad' => 30], ['edad' => 40]];

        $primera = $this->crear([$this->lineaTour($tour, 3)], $pax); // 17-nov
        $segunda = $this->crear([$this->lineaTour($tour, 3)], [['edad' => 25]]);
        $otroDia = $this->crear([$this->lineaTour($tour, 4)], $pax); // 18-nov

        foreach ([$primera, $segunda, $otroDia] as $body) {
            $this->assertSame(200, $body['code'], $body['message'] ?? '');
        }
        $salidaDe = fn (array $body) => SalidaOperativa::find(ReservaItem::where('reserva_id', $body['reserva']['id'])->value('salida_operativa_id'));

        $salida = $salidaDe($primera);
        $this->assertNotNull($salida);
        $this->assertSame($tour->id, $salida->tour_origen_id);
        $this->assertSame('2026-11-17', $salida->fecha->toDateString());
        $this->assertSame($salida->id, $salidaDe($segunda)->id); // misma salida: se suma
        $this->assertSame(2, $salida->reservaItems()->count());
        $this->assertSame('2026-11-18', $salidaDe($otroDia)->fecha->toDateString());
        $this->assertNotSame($salida->id, $salidaDe($otroDia)->id);
    }

    public function test_el_aviso_de_salida_cancelada_viaja_en_la_respuesta(): void
    {
        $tour = $this->crearTour();
        $primera = $this->crear([$this->lineaTour($tour)], [['edad' => 30]]);
        SalidaOperativa::findOrFail(ReservaItem::where('reserva_id', $primera['reserva']['id'])->value('salida_operativa_id'))
            ->update(['estado' => SalidaOperativa::ESTADO_CANCELADA]);

        $segunda = $this->crear([$this->lineaTour($tour)], [['edad' => 30]]);

        $this->assertSame(200, $segunda['code'], $segunda['message'] ?? '');
        $this->assertCount(1, $segunda['avisos_salida_cancelada']);
    }

    // CA4 — modo clásico: mismos ítems y mismo total que agregar el tour en el cotizador.
    public function test_ca4_modo_clasico_da_los_mismos_items_que_el_cotizador(): void
    {
        ConfiguracionAgencia::first()->update(['modo_tours' => 'clasico']);
        $tour = $this->crearTour();
        $tarifa = $this->tarifaCompartida(20, 50);
        PaquetePlantillaItem::create(['paquete_plantilla_id' => $tour->id, 'proveedor_tarifa_id' => $tarifa->id, 'orden' => 1]);
        $pax = [['edad' => 30], ['edad' => 40], ['edad' => 8]];

        $previa = $this->vistaPrevia([$this->lineaTour($tour)], $pax);
        $body = $this->crear([$this->lineaTour($tour)], $pax);
        $this->assertSame(200, $body['code'], $body['message'] ?? '');

        // El cotizador: misma cotización de 2 adultos + 1 niño, tour cargado con desdePlantilla().
        $clienteId = $this->crearCliente();
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-T047-'.uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => self::INICIO, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([['adulto', 30], ['adulto', 40], ['nino', 8]] as [$tipo, $edad]) {
            CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => $tipo, 'edad' => $edad, 'residencia' => 'nacional']);
        }
        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'agencia',
        ]);
        AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_atractivo_id' => $this->destino->id, 'orden' => 1]);
        app(AlternativaItemController::class)->desdePlantilla(new Request(['paquete_plantilla_id' => $tour->id, 'dia_referencial' => 1]), (string) $alternativa->id);

        $deVenta = AlternativaItem::where('alternativa_id', $body['alternativa']['id'])->orderBy('id')->get();
        $deCotizador = AlternativaItem::where('alternativa_id', $alternativa->id)->orderBy('id')->get();
        $this->assertSame($deCotizador->pluck('origen_tipo')->all(), $deVenta->pluck('origen_tipo')->all());
        $this->assertSame($deCotizador->pluck('proveedor_tarifa_id')->all(), $deVenta->pluck('proveedor_tarifa_id')->all());
        $this->assertEqualsWithDelta($deCotizador->sum('total_convertido'), $deVenta->sum('total_convertido'), 0.001);
        $this->assertEqualsWithDelta($deVenta->sum('total_convertido'), $previa['total'], 0.001); // la vista previa coincide
        $this->assertNotNull(ReservaItem::where('reserva_id', $body['reserva']['id'])->value('salida_operativa_id'));
    }

    // CA5 — un combo no se vende por Venta directa.
    public function test_ca5_un_combo_responde_422(): void
    {
        $tour = $this->crearTour();
        $combo = PaquetePlantilla::create([
            'categoria' => 'local', 'tipo' => PaquetePlantilla::TIPO_PAQUETE_COMBO, 'nombre' => 'Combo San Martín',
            'destino_atractivo_id' => $this->destino->id, 'duracion_horas' => 30,
        ]);
        PaquetePlantillaItem::create(['paquete_plantilla_id' => $combo->id, 'paquete_plantilla_hijo_id' => $tour->id, 'orden' => 1]);

        $body = $this->crear([$this->lineaTour($combo)], [['edad' => 30]]);

        $this->assertSame(422, $body['code']);
        $this->assertSame('Los combos se venden desde el cotizador.', $body['message']);
        $this->assertSame(0, Reserva::count());
        $this->assertSame(422, $this->vistaPrevia([$this->lineaTour($combo)], [['edad' => 30]])['code']);
    }

    // CA6 — la reserva se factura como una del cotizador: línea de tour por grupo de pasajeros.
    public function test_ca6_la_reserva_se_prepara_para_facturar_como_una_del_cotizador(): void
    {
        $tour = $this->crearTour(['precio_venta_final' => 100, 'precio_venta_adulto_ext' => 150]);
        $body = $this->crear([$this->lineaTour($tour)], [['edad' => 30], ['edad' => 35, 'residencia' => 'extranjero']]);
        $this->assertSame(200, $body['code'], $body['message'] ?? '');

        $tenant = new \App\Models\Tenant;
        $tenant->id = 'test-tenant-'.uniqid();
        $tenant->facturacion_habilitada = true;
        app(\Stancl\Tenancy\Tenancy::class)->tenant = $tenant;

        $reserva = Reserva::findOrFail($body['reserva']['id']);
        $factura = app(ReservaFacturacionController::class)->prepararFactura(new Request([
            'pasajero_ids' => $reserva->pasajeros()->pluck('id')->all(),
        ]), (string) $reserva->id)->getData(true);

        app(\Stancl\Tenancy\Tenancy::class)->tenant = null;
        $this->assertFalse($factura['bloqueado_tributario'] ?? false, json_encode($factura));
        $this->assertSame(200, $factura['code'] ?? null, json_encode($factura));
        $this->assertEqualsWithDelta(250.0, (float) $factura['total'], 0.001, json_encode($factura));
        $this->assertNotEmpty($factura['partes_tour'], 'la línea de tour sale por grupo de pasajeros');
    }

    public function test_un_tour_de_igv_mezclado_son_varias_lineas_de_la_misma_carga_y_se_puede_facturar(): void
    {
        $tour = $this->crearTour(['precio_venta_final' => 100]);
        $this->incluye($tour, 30, '20', 'amazonia');
        $this->incluye($tour, 30, '10', 'nacional');

        $previa = $this->vistaPrevia([$this->lineaTour($tour)], [['edad' => 30]]);

        $this->assertSame(200, $previa['code'], json_encode($previa));
        $this->assertCount(2, $previa['lineas'][0]['afectaciones']);
        $this->assertTrue($previa['facturable']['ok'], json_encode($previa['facturable']));

        $body = $this->crear([$this->lineaTour($tour)], [['edad' => 30]]);
        $this->assertSame(2, AlternativaItem::where('alternativa_id', $body['alternativa']['id'])->where('origen_tipo', 'tour')->count());
        $this->assertEqualsWithDelta(100.0, $this->totalCreado($body), 0.001);
    }

    public function test_un_tour_en_otra_moneda_no_se_mezcla(): void
    {
        $tour = $this->crearTour(['moneda' => 'USD', 'precio_venta_final' => 50]);

        $body = $this->crear([$this->lineaTour($tour)], [['edad' => 30]], ['moneda' => 'PEN']);

        $this->assertSame(422, $body['code']);
        $this->assertStringContainsString('No se mezclan monedas', $body['message']);
        $this->assertSame(200, $this->crear([$this->lineaTour($tour)], [['edad' => 30]], ['moneda' => 'USD'])['code']);
    }

    public function test_tour_inexistente_desactivado_o_sin_id_no_se_vende(): void
    {
        $tour = $this->crearTour();
        $pax = [['edad' => 30]];

        $this->assertSame(422, $this->crear([['origen_tipo' => 'tour', 'dia' => 1]], $pax)['code']); // sin paquete_plantilla_id
        $this->assertSame(422, $this->crear([['origen_tipo' => 'tour', 'dia' => 1, 'paquete_plantilla_id' => 999999]], $pax)['code']);

        $tour->update(['activo' => false]);
        $body = $this->crear([$this->lineaTour($tour)], $pax);
        $this->assertSame(422, $body['code']);
        $this->assertStringContainsString('desactivado', $body['message']);
    }

    public function test_un_tour_sin_precio_de_venta_responde_422_y_no_deja_nada(): void
    {
        $tour = $this->crearTour(['precio_venta_final' => null]);

        $body = $this->crear([$this->lineaTour($tour)], [['edad' => 30]]);

        $this->assertSame(422, $body['code']);
        $this->assertSame(0, Reserva::count());
    }

    public function test_tour_y_servicio_suelto_juntos_en_una_venta(): void
    {
        $tour = $this->crearTour(['precio_venta_final' => 100]);

        $body = $this->crear([
            $this->lineaTour($tour),
            ['origen_tipo' => 'manual', 'dia' => 2, 'descripcion_manual' => 'Cena show', 'precio_venta_snapshot' => 80, 'costo_snapshot' => 0, 'cantidad' => 1],
        ], [['edad' => 30]]);

        $this->assertSame(200, $body['code'], $body['message'] ?? '');
        $this->assertEqualsWithDelta(180.0, $this->totalCreado($body), 0.001);
        $this->assertSame(2, ReservaItem::where('reserva_id', $body['reserva']['id'])->count());
    }
}
