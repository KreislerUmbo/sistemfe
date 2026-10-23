<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Http\Controllers\AgenciaViajes\ConversionToursController;
use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\ReservaFacturacionController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaDestino;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorServicio;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaPasajero;
use App\Models\AgenciaViajes\Servicio;
use App\Models\AgenciaViajes\TourItinerarioItem;
use App\Models\Cash\Branch;
use App\Models\Client\Client;
use App\Models\Sale\Sale;
use App\Models\Sale\SerieComprobante;
use App\Models\User;
use App\Services\AgenciaViajes\TourLineaService;
use App\Services\HoraPeru;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

// Fila 13, fase 1c — facturar la línea de tour (brief
// PEGAR-EN-CLAUDE-CODE-13-fase1c-facturacion-conversion.md §4). Mismo patrón que
// Fila13Fase1bTourCotizacionTest: Postgres real (sistemafe_test_migrations),
// transacción por test revertida.
class Fila13Fase1cFacturacionTest extends TestCase
{
    private DestinoAtractivo $destino;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => env('DB_HOST', '127.0.0.1'),
            'database.connections.pgsql.port' => env('DB_PORT', '5432'),
            'database.connections.pgsql.database' => 'sistemafe_test_migrations',
            'database.connections.pgsql.username' => env('DB_USERNAME', 'root'),
            'database.connections.pgsql.password' => env('DB_PASSWORD', ''),
        ]);
        DB::purge('pgsql');
        DB::beginTransaction();

        $this->destino = DestinoAtractivo::create(['nombre' => 'Lamas '.uniqid(), 'tipo' => 'zona']);
        ConfiguracionAgencia::first()->update([
            'modo_tours' => ConfiguracionAgencia::MODO_TOURS_NUEVO,
            'tip_afe_igv_default' => '20',
            'destino_tributario_default' => 'amazonia',
        ]);

        $tenant = new \App\Models\Tenant();
        $tenant->id = 'test-tenant-'.uniqid();
        $tenant->facturacion_habilitada = true;
        app(\Stancl\Tenancy\Tenancy::class)->tenant = $tenant;
        $this->usuarioQueFactura();
    }

    protected function tearDown(): void
    {
        app(\Stancl\Tenancy\Tenancy::class)->tenant = null;
        DB::rollBack();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════
    // Factura con IGV mezclado (D1, D4, D5)
    // ═══════════════════════════════════════════════════════════════

    public function test_tour_con_igv_mezclado_sale_en_un_comprobante_con_una_linea_por_igv(): void
    {
        $alternativa = $this->crearAlternativa([['adulto', 30], ['adulto', 28]]);
        $this->cargar($alternativa, $this->tourMezclado());
        $reserva = $this->aceptar($alternativa);
        $this->confirmarAmazonia($reserva);

        $preview = $this->preview($reserva);

        $this->assertFalse($preview['bloqueado_tributario'], json_encode($preview));
        $grupos = collect($preview['grupos_propuestos']);
        $this->assertCount(2, $grupos);
        $this->assertSame(['TOUR', 'TOUR'], $grupos->pluck('categoria')->all());
        // 2 adultos × 100: 75 % exonerado (costo 60 de 80) y 25 % gravado.
        $this->assertEqualsWithDelta(200.0, (float) $preview['total'], 0.001);

        $respuesta = $this->facturar($reserva)->getData(true);
        $this->assertSame(200, $respuesta['code'], json_encode($respuesta));

        $venta = Sale::find($respuesta['sale_id']);
        $this->assertEqualsWithDelta(150.0, (float) $venta->mto_oper_exoneradas, 0.001);
        $this->assertEqualsWithDelta(42.37, (float) $venta->mto_oper_gravadas, 0.001);
        $this->assertEqualsWithDelta(7.63, (float) $venta->igv, 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $venta->total, 0.001);
        // La leyenda de la Ley 27037 sale porque hay monto exonerado.
        $this->assertSame('amazonia', $venta->destino);
    }

    public function test_tour_mezclado_sigue_exigiendo_confirmar_la_linea_amazonia(): void
    {
        $alternativa = $this->crearAlternativa([['adulto', 30]]);
        $this->cargar($alternativa, $this->tourMezclado());
        $reserva = $this->aceptar($alternativa);

        $preview = $this->preview($reserva);

        $this->assertTrue($preview['bloqueado_tributario']);
        $this->assertStringContainsString('Amazonía', $preview['motivo']);
        // F1: aunque esté bloqueado, el detalle muestra el tour (una fila con
        // sus dos líneas de IGV) y su monto.
        $this->assertCount(1, $preview['detalle']);
        $this->assertEqualsWithDelta(100.0, $preview['detalle'][0]['monto'], 0.001);
        $this->assertCount(2, $preview['detalle'][0]['reserva_item_ids']);
        // Solo la línea Amazonía pide confirmación; la gravada nacional no.
        $amazonia = ReservaItem::where('reserva_id', $reserva->id)->where('destino_tributario', 'amazonia')->pluck('id')->all();
        $this->assertSame($amazonia, $preview['items_sin_confirmar_ids']);
    }

    public function test_tour_mezclado_junto_a_un_servicio_suelto_nacional_se_permite(): void
    {
        $alternativa = $this->crearAlternativa([['adulto', 30]]);
        $this->cargar($alternativa, $this->tourMezclado());
        $this->servicioSuelto($alternativa, 'nacional', '10', 50);
        $reserva = $this->aceptar($alternativa);
        $this->confirmarAmazonia($reserva);

        $preview = $this->preview($reserva);

        $this->assertFalse($preview['bloqueado_tributario'], json_encode($preview));
        $this->assertEqualsWithDelta(150.0, (float) $preview['total'], 0.001);
    }

    public function test_servicios_sueltos_de_distinto_destino_siguen_bloqueados(): void
    {
        $alternativa = $this->crearAlternativa([['adulto', 30]]);
        $this->servicioSuelto($alternativa, 'amazonia', '20', 50);
        $this->servicioSuelto($alternativa, 'nacional', '10', 50);
        $reserva = $this->aceptar($alternativa);
        $this->confirmarAmazonia($reserva);

        $preview = $this->preview($reserva);

        $this->assertTrue($preview['bloqueado_tributario']);
        $this->assertStringContainsString('Solo un mismo tour', $preview['motivo']);
        $this->assertSame(422, $this->facturar($reserva)->getStatusCode());
    }

    public function test_tour_mezclado_con_sueltos_de_los_dos_destinos_sigue_bloqueado(): void
    {
        $alternativa = $this->crearAlternativa([['adulto', 30]]);
        $this->cargar($alternativa, $this->tourMezclado());
        $this->servicioSuelto($alternativa, 'amazonia', '20', 50);
        $this->servicioSuelto($alternativa, 'nacional', '10', 50);
        $reserva = $this->aceptar($alternativa);
        $this->confirmarAmazonia($reserva);

        $this->assertBloqueadoPorMezcla($reserva);
    }

    public function test_dos_cargas_mezcladas_en_un_comprobante_siguen_bloqueadas(): void
    {
        $alternativa = $this->crearAlternativa([['adulto', 30]]);
        $this->cargar($alternativa, $this->tourMezclado());
        $this->cargar($alternativa, $this->tourMezclado('Cascadas de Ahuashiyacu'), 2);
        $reserva = $this->aceptar($alternativa);
        $this->confirmarAmazonia($reserva);

        $this->assertBloqueadoPorMezcla($reserva);
    }

    public function test_combo_de_un_tour_exonerado_y_otro_gravado_no_cuenta_como_una_carga(): void
    {
        // D5: cada tour del combo es su propia carga_tour, aunque compartan carga_combo.
        $exonerado = $this->crearTour(['nombre' => 'Laguna Azul', 'tip_afe_igv' => '20', 'destino_tributario' => 'amazonia']);
        $gravado = $this->crearTour(['nombre' => 'City tour', 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional']);
        $alternativa = $this->crearAlternativa([['adulto', 30]]);
        $this->cargar($alternativa, $this->crearCombo([$exonerado, $gravado]));
        $reserva = $this->aceptar($alternativa);
        $this->confirmarAmazonia($reserva);

        $items = ReservaItem::where('reserva_id', $reserva->id)->get();
        $this->assertCount(1, $items->pluck('carga_combo')->unique());
        $this->assertCount(2, $items->pluck('carga_tour')->unique());
        $this->assertBloqueadoPorMezcla($reserva);
    }

    // ═══════════════════════════════════════════════════════════════
    // Facturación por grupo de pasajeros (C1)
    // ═══════════════════════════════════════════════════════════════

    public function test_al_aceptar_la_reserva_copia_la_residencia_de_cada_pasajero(): void
    {
        $alternativa = $this->crearAlternativa([['adulto', 30], ['adulto', 40, 'extranjero'], ['nino', 8]]);
        $reserva = $this->aceptar($alternativa);

        $residencias = ReservaPasajero::where('reserva_id', $reserva->id)->orderBy('id')->pluck('residencia')->all();
        $this->assertSame(['nacional', 'extranjero', 'nacional'], $residencias);
    }

    public function test_precio_de_lista_y_parte_de_cada_familia(): void
    {
        // Laguna Azul: S/ 150 adulto, 130 niño, 180/160 extranjero; vendido con 10 % de descuento.
        $precios = ['adulto' => 150, 'nino' => 130, 'adulto_ext' => 180, 'nino_ext' => 160];
        $rojas = [['tipo_pax' => 'adulto', 'residencia' => 'nacional'], ['tipo_pax' => 'adulto', 'residencia' => 'nacional']];
        $perez = [['tipo_pax' => 'adulto', 'residencia' => 'nacional'], ['tipo_pax' => 'nino', 'residencia' => 'nacional'], ['tipo_pax' => 'infante', 'residencia' => 'nacional']];

        $this->assertSame(300.0, TourLineaService::precioDeLista($precios, $rojas));
        $this->assertSame(280.0, TourLineaService::precioDeLista($precios, $perez)); // el infante paga 0
        $this->assertSame(180.0, TourLineaService::precioDeLista($precios, [['tipo_pax' => 'adulto', 'residencia' => 'extranjero']]));

        $this->assertSame(270.0, TourLineaService::parteDelGrupo(300, 580, 522));
        $this->assertSame(252.0, TourLineaService::parteDelGrupo(280, 580, 522));
        $this->assertSame(0.0, TourLineaService::parteDelGrupo(100, 0, 522)); // tour sin precio: no divide entre cero
    }

    public function test_familias_rojas_y_perez_facturan_aparte_su_parte_del_tour_con_precio_a_mano(): void
    {
        // Laguna Azul S/ 150 adulto, 130 niño. Rojas: 2 adultos; Pérez: adulto + niño.
        // Lista 580; el vendedor lo dejó en 522 (10 % menos).
        $alternativa = $this->crearAlternativa([['adulto', 40], ['adulto', 38], ['adulto', 35], ['nino', 8]]);
        $this->cargar($alternativa, $this->tourGravado(150, 130));
        $this->precioAMano($alternativa, 522);
        $reserva = $this->aceptar($alternativa);
        [$rojas1, $rojas2, $perez, $perezNino] = $this->pasajeros($reserva);

        $previewRojas = $this->preview($reserva, [$rojas1, $rojas2]);
        $this->assertEqualsWithDelta(270.0, (float) $previewRojas['total'], 0.001, json_encode($previewRojas));
        $this->assertFalse($previewRojas['partes_tour'][0]['cierra']);
        // F1: el detalle de la vista previa dice qué parte del tour entra.
        $this->assertCount(1, $previewRojas['detalle']);
        $this->assertEqualsWithDelta(270.0, $previewRojas['detalle'][0]['monto'], 0.001);
        $this->assertEqualsWithDelta(522.0, $previewRojas['detalle'][0]['total_servicio'], 0.001);
        $this->assertTrue($previewRojas['detalle'][0]['parcial']);
        $this->assertSame(2, $previewRojas['detalle'][0]['pasajeros_grupo']);
        $this->assertSame(4, $previewRojas['detalle'][0]['pasajeros_total']);
        $ventaRojas = $this->facturarOk($reserva, [$rojas1, $rojas2]);

        $previewPerez = $this->preview($reserva, [$perez, $perezNino]);
        $this->assertEqualsWithDelta(252.0, (float) $previewPerez['total'], 0.001);
        $this->assertTrue($previewPerez['partes_tour'][0]['cierra']);
        $ventaPerez = $this->facturarOk($reserva, [$perez, $perezNino]);

        // La suma de las dos facturas es exactamente lo vendido.
        $this->assertEqualsWithDelta(522.0, (float) $ventaRojas->total + (float) $ventaPerez->total, 0.001);
        // Nadie se factura dos veces.
        $this->assertSame(422, $this->facturar($reserva, [$rojas1])->getStatusCode());
    }

    public function test_el_ultimo_grupo_se_lleva_los_centavos_del_redondeo(): void
    {
        // 3 adultos de lista 150 c/u, vendido en 100: 33.33 + 33.33 + 33.34.
        $alternativa = $this->crearAlternativa([['adulto', 40], ['adulto', 38], ['adulto', 35]]);
        $this->cargar($alternativa, $this->tourGravado(150, null));
        $this->precioAMano($alternativa, 100);
        $reserva = $this->aceptar($alternativa);
        [$a, $b, $c] = $this->pasajeros($reserva);

        $totales = [
            (float) $this->facturarOk($reserva, [$a])->total,
            (float) $this->facturarOk($reserva, [$b])->total,
            (float) $this->facturarOk($reserva, [$c])->total,
        ];

        $this->assertSame([33.33, 33.33, 33.34], $totales);
    }

    public function test_descuento_del_combo_se_reparte_entre_los_grupos(): void
    {
        // Combo con 10 % de descuento: Lamas 100 y Laguna 200 por adulto, 2 adultos.
        // Cada adulto paga la mitad de cada línea: 90 + 180 = 270.
        $lamas = $this->tourGravado(100, null, 'Lamas');
        $laguna = $this->tourGravado(200, null, 'Laguna Azul');
        $alternativa = $this->crearAlternativa([['adulto', 40], ['adulto', 38]]);
        $this->cargar($alternativa, $this->crearCombo([$lamas, $laguna], ['descuento_tipo' => 'porcentaje', 'descuento_valor' => 10]));
        $reserva = $this->aceptar($alternativa);
        [$a] = $this->pasajeros($reserva);

        $preview = $this->preview($reserva, [$a]);

        $this->assertEqualsWithDelta(270.0, (float) $preview['total'], 0.001);
        $this->assertSame([90.0, 180.0], collect($preview['partes_tour'])->pluck('monto')->map(fn ($m) => (float) $m)->all());
    }

    public function test_infante_no_paga_y_el_extranjero_paga_su_precio(): void
    {
        // Adulto nacional 150, adulto extranjero 180, infante 0. Sin descuento.
        $alternativa = $this->crearAlternativa([['adulto', 40], ['adulto', 38, 'extranjero'], ['infante', 1]]);
        $this->cargar($alternativa, $this->tourGravado(150, null, 'Laguna Azul', 180));
        $reserva = $this->aceptar($alternativa);
        [$nacional, $extranjero, $infante] = $this->pasajeros($reserva);

        $this->assertEqualsWithDelta(180.0, (float) $this->preview($reserva, [$extranjero])['total'], 0.001);
        $this->facturarOk($reserva, [$extranjero]);
        // El infante va con el adulto nacional y no suma.
        $this->assertEqualsWithDelta(150.0, (float) $this->preview($reserva, [$nacional, $infante])['total'], 0.001);
    }

    public function test_tour_con_igv_mezclado_por_grupo_reparte_cada_linea(): void
    {
        // 2 adultos × 100 (75 exonerado + 25 gravado). Un adulto: 75 + 25.
        $alternativa = $this->crearAlternativa([['adulto', 40], ['adulto', 38]]);
        $this->cargar($alternativa, $this->tourMezclado());
        $reserva = $this->aceptar($alternativa);
        $this->confirmarAmazonia($reserva);
        [$a] = $this->pasajeros($reserva);

        $venta = $this->facturarOk($reserva, [$a]);

        $this->assertEqualsWithDelta(75.0, (float) $venta->mto_oper_exoneradas, 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $venta->total, 0.001);
    }

    public function test_reserva_con_un_tour_cobrado_a_medias_queda_parcial(): void
    {
        $alternativa = $this->crearAlternativa([['adulto', 40], ['adulto', 38]]);
        $this->cargar($alternativa, $this->tourGravado(150, null));
        $reserva = $this->aceptar($alternativa);
        [$a, $b] = $this->pasajeros($reserva);
        $estado = fn () => (new \ReflectionMethod(ReservaController::class, 'calcularEstadoFacturacion'))
            ->invoke(null, Reserva::with('ventas.sale')->find($reserva->id), 1);

        $this->facturarOk($reserva, [$a])->update(['n_operacion' => 'TEST-1']); // aceptada por SUNAT
        $this->assertSame('parcial', $estado());

        $this->facturarOk($reserva, [$b])->update(['n_operacion' => 'TEST-2']);
        $this->assertSame('total', $estado());
    }

    // ═══════════════════════════════════════════════════════════════
    // F1 (hoja de ruta): dos comprobantes a clientes distintos por tipo de
    // servicio, con los mismos pasajeros (vuelos a un RUC, el resto a otro)
    // ═══════════════════════════════════════════════════════════════

    public function test_f1_segunda_factura_con_los_mismos_pasajeros_para_el_resto_de_servicios(): void
    {
        $alternativa = $this->crearAlternativa([['adulto', 30], ['adulto', 28]]);
        $vuelo = $this->servicioSuelto($alternativa, 'nacional', '10', 300);
        $traslado = $this->servicioSuelto($alternativa, 'nacional', '10', 50);
        $reserva = $this->aceptar($alternativa);
        $pasajeros = ReservaPasajero::where('reserva_id', $reserva->id)->pluck('id')->all();
        $itemVuelo = ReservaItem::where('reserva_id', $reserva->id)->where('alternativa_item_id', $vuelo->id)->value('id');
        $itemTraslado = ReservaItem::where('reserva_id', $reserva->id)->where('alternativa_item_id', $traslado->id)->value('id');
        $controller = app(ReservaFacturacionController::class);

        // Factura 1: los vuelos, al RUC 20.
        $primera = $controller->store(new Request([
            'pasajero_ids' => $pasajeros, 'reserva_item_ids_manual' => [$itemVuelo],
            'client_id' => Client::factory()->empresa()->create()->id, 'tipo_comprobante_codigo' => '01',
        ]), (string) $reserva->id)->getData(true);
        $this->assertSame(200, $primera['code'], json_encode($primera));

        // La pantalla pide la vista previa SIN ítems marcados para saber qué
        // queda por facturar: tiene que responder 200 y ofrecer el traslado.
        $preview = $controller->prepararFactura(new Request(['pasajero_ids' => $pasajeros]), (string) $reserva->id);
        $this->assertSame(200, $preview->getStatusCode(), json_encode($preview->getData(true)));
        $this->assertSame([$itemTraslado], collect($preview->getData(true)['items_sin_asignar_disponibles'])->pluck('reserva_item_id')->all());

        // Factura 2: el resto, a OTRO cliente (RUC 10), con los mismos pasajeros.
        $otroCliente = Client::factory()->empresa()->create();
        $segunda = $controller->store(new Request([
            'pasajero_ids' => $pasajeros, 'reserva_item_ids_manual' => [$itemTraslado],
            'client_id' => $otroCliente->id, 'tipo_comprobante_codigo' => '01',
        ]), (string) $reserva->id)->getData(true);
        $this->assertSame(200, $segunda['code'], json_encode($segunda));
        $this->assertSame($otroCliente->id, \App\Models\Sale\Sale::find($segunda['sale_id'])->client_id);

        // Sin nada más por facturar, emitir de nuevo se rechaza.
        $tercera = $this->facturarSinCapturar($reserva, $pasajeros, []);
        $this->assertSame(422, $tercera);
    }

    public function test_f1_pasajeros_ya_facturados_mezclados_con_pendientes_siguen_rechazados(): void
    {
        $alternativa = $this->crearAlternativa([['adulto', 30], ['adulto', 28]]);
        $vuelo = $this->servicioSuelto($alternativa, 'nacional', '10', 300);
        $reserva = $this->aceptar($alternativa);
        [$a, $b] = ReservaPasajero::where('reserva_id', $reserva->id)->orderBy('id')->pluck('id')->all();
        $itemVuelo = ReservaItem::where('reserva_id', $reserva->id)->where('alternativa_item_id', $vuelo->id)->value('id');

        app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$a], 'reserva_item_ids_manual' => [$itemVuelo],
            'client_id' => Client::factory()->empresa()->create()->id, 'tipo_comprobante_codigo' => '01',
        ]), (string) $reserva->id);

        // A ya está facturado y B no: mezclarlos sigue siendo un error.
        $preview = app(ReservaFacturacionController::class)->prepararFactura(new Request(['pasajero_ids' => [$a, $b]]), (string) $reserva->id);
        $this->assertSame(422, $preview->getStatusCode());
    }

    private function facturarSinCapturar(Reserva $reserva, array $pasajeroIds, array $manual): int
    {
        try {
            return app(ReservaFacturacionController::class)->store(new Request([
                'pasajero_ids' => $pasajeroIds, 'reserva_item_ids_manual' => $manual,
                'client_id' => Client::factory()->empresa()->create()->id, 'tipo_comprobante_codigo' => '01',
            ]), (string) $reserva->id)->getStatusCode();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $e->getStatusCode();
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Conversión de los tours existentes (§3.11)
    // ═══════════════════════════════════════════════════════════════

    public function test_la_propuesta_no_escribe_nada_y_toma_el_igv_y_el_costo_de_la_tarifa(): void
    {
        // Tour viejo: sin precio, Incluye con una tarifa gravada por persona y sin IGV propio.
        $tour = $this->crearTour(['precio_venta_final' => null, 'costo_estimado_persona' => null]);
        $item = $this->incluyeConTarifa($tour, costo: 30, venta: 45, igv: '10', destino: 'nacional');
        $antes = [$tour->fresh()->toArray(), $item->fresh()->toArray()];

        $respuesta = app(ConversionToursController::class)->show((string) $tour->id)->getData(true);

        $this->assertSame(200, $respuesta['code'], json_encode($respuesta));
        $propuesta = $respuesta['propuesta'];
        $this->assertEqualsWithDelta(45.0, $propuesta['precio_propuesto'], 0.001); // suma de ventas de sus tarifas
        $this->assertSame('tarifas', $propuesta['precio_origen']);
        $this->assertEqualsWithDelta(30.0, $propuesta['items'][0]['costo_propuesto'], 0.001); // calculadora
        $this->assertNull($propuesta['items'][0]['igv_actual']);
        $this->assertSame(['tip_afe_igv' => '10', 'destino_tributario' => 'nacional'], $propuesta['items'][0]['igv_propuesto']);
        // Nada cambió en la base.
        $this->assertSame($antes, [$tour->fresh()->toArray(), $item->fresh()->toArray()]);
    }

    public function test_confirmar_guarda_lo_que_el_usuario_acepto(): void
    {
        $tour = $this->crearTour(['precio_venta_final' => null, 'costo_estimado_persona' => null]);
        $item = $this->incluyeConTarifa($tour, costo: 30, venta: 45, igv: '10', destino: 'nacional');

        $respuesta = app(ConversionToursController::class)->confirmar(new Request([
            'precio_venta_final' => 50,
            'items' => [['id' => $item->id, 'costo_estimado_persona' => 32, 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional']],
        ]), (string) $tour->id)->getData(true);

        $this->assertSame(200, $respuesta['code'], json_encode($respuesta));
        $tour->refresh();
        $item->refresh();
        $this->assertEqualsWithDelta(50.0, (float) $tour->precio_venta_final, 0.001);
        $this->assertEqualsWithDelta(32.0, (float) $item->costo_estimado_persona, 0.001);
        $this->assertSame('10', $item->tip_afe_igv);
        $this->assertNotNull($tour->conversion_confirmada_en);
        $this->assertSame(Auth::guard('api')->id(), $tour->conversion_confirmada_por);

        $fila = collect(app(ConversionToursController::class)->index()->getData(true)['tours'])->firstWhere('id', $tour->id);
        $this->assertNotNull($fila['conversion_confirmada_en']);
    }

    public function test_confirmar_un_tour_mezclado_con_un_servicio_sin_costo_no_guarda_nada(): void
    {
        $tour = $this->crearTour(['precio_venta_final' => null, 'costo_estimado_persona' => null]);
        $bote = $this->incluye($tour, 'Bote', 60, '20', 'amazonia');
        $transporte = $this->incluye($tour, 'Transporte', null);

        $respuesta = app(ConversionToursController::class)->confirmar(new Request([
            'precio_venta_final' => 100,
            'items' => [['id' => $transporte->id, 'costo_estimado_persona' => null, 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional']],
        ]), (string) $tour->id);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertStringContainsString('mezcla tipos de IGV', $respuesta->getData(true)['message']);
        $this->assertNull($tour->fresh()->precio_venta_final);
        $this->assertNull($transporte->fresh()->tip_afe_igv);
        $this->assertNull($tour->fresh()->conversion_confirmada_en);
        $this->assertSame('20', $bote->fresh()->tip_afe_igv);
    }

    public function test_combos_y_tours_solo_itinerario_quedan_fuera(): void
    {
        $soloItinerario = $this->crearTour(['uso' => PaquetePlantilla::USO_SOLO_ITINERARIO]);
        $combo = $this->crearCombo([$this->crearTour()]);

        $listado = app(ConversionToursController::class)->index()->getData(true);
        $ids = collect($listado['tours'])->pluck('id');

        $this->assertNotContains($soloItinerario->id, $ids);
        $this->assertNotContains($combo->id, $ids);
        $this->assertSame(422, app(ConversionToursController::class)->show((string) $soloItinerario->id)->getStatusCode());
        $this->assertSame(422, app(ConversionToursController::class)->confirmar(new Request(['precio_venta_final' => 10]), (string) $combo->id)->getStatusCode());
    }

    // ═══════════════════════════════════════════════════════════════
    // Fixtures
    // ═══════════════════════════════════════════════════════════════

    /**
     * @param  array<int, array{0: string, 1: int, 2?: string}>  $pasajeros  [tipo, edad, residencia]
     */
    private function crearAlternativa(array $pasajeros): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10000000, 99999999), 'full_name' => 'Cliente Test 1c',
            'type_client' => 1, 'cod_tipo_doc_sunat' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-F13C-'.uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-11-15', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($pasajeros as $p) {
            CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => $p[0], 'edad' => $p[1], 'residencia' => $p[2] ?? 'nacional']);
        }

        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 3.75, 'tipo_cambio_origen' => 'dia',
        ]);
        AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_atractivo_id' => $this->destino->id, 'orden' => 1]);

        return $alternativa;
    }

    private function crearTour(array $atributos = []): PaquetePlantilla
    {
        $tour = PaquetePlantilla::create($atributos + [
            'categoria' => 'local',
            'tipo' => PaquetePlantilla::TIPO_TOUR_SIMPLE,
            'nombre' => 'Lamas Full Day',
            'destino_atractivo_id' => $this->destino->id,
            'duracion_horas' => 10,
            'precio_venta_final' => 100,
            'costo_estimado_persona' => 80,
        ]);
        TourItinerarioItem::create([
            'tour_id' => $tour->id, 'dia_relativo' => 1, 'orden' => 1,
            'destino_atractivo_id' => $this->destino->id, 'descripcion' => 'Día 1',
        ]);

        return $tour;
    }

    // S/ 100 por persona; Incluye con costo 60 exonerado (Amazonía) y 20 gravado
    // (nacional): la carga son dos líneas, 75 y 25 por persona.
    private function tourMezclado(string $nombre = 'Lamas Full Day'): PaquetePlantilla
    {
        $tour = $this->crearTour(['nombre' => $nombre, 'costo_estimado_persona' => null]);
        $this->incluye($tour, 'Bote', 60, '20', 'amazonia');
        $this->incluye($tour, 'Transporte', 20, '10', 'nacional');

        return $tour;
    }

    // Tour gravado nacional (sin la confirmación Amazonía de por medio).
    private function tourGravado(float $adulto, ?float $nino, string $nombre = 'Laguna Azul', ?float $adultoExt = null): PaquetePlantilla
    {
        return $this->crearTour([
            'nombre' => $nombre, 'precio_venta_final' => $adulto, 'precio_venta_nino' => $nino,
            'precio_venta_adulto_ext' => $adultoExt, 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
        ]);
    }

    // El vendedor cambia a mano el precio total de la (única) carga de tour.
    private function precioAMano(Alternativa $alternativa, float $precio): void
    {
        $carga = AlternativaItem::where('alternativa_id', $alternativa->id)->whereNotNull('carga_tour')->value('carga_tour');
        $respuesta = app(AlternativaItemController::class)->precioCarga(new Request(['precio' => $precio]), (string) $alternativa->id, $carga);
        $this->assertLessThan(300, $respuesta->getStatusCode(), json_encode($respuesta->getData(true)));
    }

    /** @return array<int, int> ids de los pasajeros de la reserva, en el orden de la cotización */
    private function pasajeros(Reserva $reserva): array
    {
        return ReservaPasajero::where('reserva_id', $reserva->id)->orderBy('id')->pluck('id')->all();
    }

    private function servicio(string $nombre): DestinoServicio
    {
        $servicio = Servicio::create(['nombre' => $nombre.' '.uniqid()]);

        return DestinoServicio::create(['destino_atractivo_id' => $this->destino->id, 'servicio_id' => $servicio->id]);
    }

    private function incluye(PaquetePlantilla $tour, string $servicio, ?float $costo, ?string $igv = null, ?string $destino = null): PaquetePlantillaItem
    {
        return PaquetePlantillaItem::create([
            'paquete_plantilla_id' => $tour->id,
            'destino_servicio_id' => $this->servicio($servicio)->id,
            'costo_estimado_persona' => $costo,
            'tip_afe_igv' => $igv,
            'destino_tributario' => $destino,
            'orden' => PaquetePlantillaItem::where('paquete_plantilla_id', $tour->id)->count() + 1,
        ]);
    }

    // Servicio del Incluye como lo dejaba el modelo viejo: con tarifa de
    // proveedor y sin costo ni IGV propios.
    private function incluyeConTarifa(PaquetePlantilla $tour, float $costo, float $venta, string $igv, string $destino): PaquetePlantillaItem
    {
        $proveedor = Proveedor::create(['razon_social' => 'Proveedor Test '.uniqid(), 'estado' => true]);
        $proveedorServicio = ProveedorServicio::create(['proveedor_id' => $proveedor->id, 'destino_servicio_id' => $this->servicio('Transporte')->id]);
        $tarifa = ProveedorTarifa::create([
            'proveedor_servicio_id' => $proveedorServicio->id, 'tipo_tarifa' => 'publica', 'modalidad' => 'compartido',
            'moneda' => 'PEN', 'precio_costo' => $costo, 'margen_tipo' => 'porcentaje', 'margen_valor' => 20,
            'precio_venta_adulto' => $venta, 'vigente_desde' => HoraPeru::hoyTexto(),
            'tip_afe_igv' => $igv, 'destino_tributario' => $destino,
        ]);

        return PaquetePlantillaItem::create([
            'paquete_plantilla_id' => $tour->id, 'proveedor_tarifa_id' => $tarifa->id, 'proveedor_id' => $proveedor->id,
            'destino_servicio_id' => $proveedorServicio->destino_servicio_id, 'orden' => 1,
        ]);
    }

    private function crearCombo(array $tours, array $atributos = []): PaquetePlantilla
    {
        $combo = PaquetePlantilla::create($atributos + [
            'categoria' => 'local', 'tipo' => PaquetePlantilla::TIPO_PAQUETE_COMBO, 'nombre' => 'Combo San Martín',
            'destino_atractivo_id' => $this->destino->id, 'duracion_horas' => 30,
        ]);
        foreach ($tours as $k => $tour) {
            PaquetePlantillaItem::create(['paquete_plantilla_id' => $combo->id, 'paquete_plantilla_hijo_id' => $tour->id, 'orden' => $k + 1]);
        }

        return $combo;
    }

    private function cargar(Alternativa $alternativa, PaquetePlantilla $paquete, int $dia = 1): void
    {
        $respuesta = app(AlternativaItemController::class)->desdePlantilla(
            new Request(['paquete_plantilla_id' => $paquete->id, 'dia_referencial' => $dia]),
            (string) $alternativa->id
        );
        $this->assertLessThan(300, $respuesta->getStatusCode(), json_encode($respuesta->getData(true)));
    }

    // Servicio suelto (línea de proveedor de siempre), por persona.
    private function servicioSuelto(Alternativa $alternativa, string $destino, string $igv, float $venta): AlternativaItem
    {
        $proveedor = Proveedor::create(['razon_social' => 'Proveedor Test '.uniqid(), 'estado' => true]);
        $proveedorServicio = ProveedorServicio::create(['proveedor_id' => $proveedor->id, 'destino_servicio_id' => $this->servicio('Hotel')->id]);
        $tarifa = ProveedorTarifa::create([
            'proveedor_servicio_id' => $proveedorServicio->id, 'tipo_tarifa' => 'publica', 'modalidad' => 'compartido',
            'moneda' => 'PEN', 'precio_costo' => $venta / 2, 'margen_tipo' => 'porcentaje', 'margen_valor' => 20,
            'precio_venta_adulto' => $venta, 'vigente_desde' => HoraPeru::hoyTexto(),
            'tip_afe_igv' => $igv, 'destino_tributario' => $destino,
        ]);

        return AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'proveedor', 'proveedor_tarifa_id' => $tarifa->id,
            'dia_referencial' => 1, 'modo_precio' => 'por_persona', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => $venta / 2, 'precio_venta_snapshot' => $venta, 'precio_convertido' => $venta,
            'tip_afe_igv' => $igv, 'destino_tributario' => $destino,
        ]);
    }

    private function aceptar(Alternativa $alternativa): Reserva
    {
        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        return $reserva;
    }

    // Lo que hace el vendedor en la reserva antes de facturar un servicio de la
    // Amazonía (ReservaController::overrideTratamientoTributario()).
    private function confirmarAmazonia(Reserva $reserva): void
    {
        ReservaItem::where('reserva_id', $reserva->id)
            ->where('destino_tributario', 'amazonia')
            ->update(['motivo_override_tributario' => 'Confirmado en test: servicio prestado en la Amazonía']);
    }

    // Sin $pasajeroIds: todos. Las líneas de tour entran solas con la parte de
    // esos pasajeros; los servicios sueltos sin pasajero asignado se agregan
    // a mano, como en "Facturar".
    private function pedidoDeFactura(Reserva $reserva, ?array $pasajeroIds = null, array $extra = []): Request
    {
        $sueltos = $pasajeroIds === null
            ? ReservaItem::where('reserva_id', $reserva->id)
                ->whereHas('alternativaItem', fn ($q) => $q->where('origen_tipo', '!=', AlternativaItem::ORIGEN_TOUR))
                ->pluck('id')->all()
            : [];

        return new Request($extra + [
            'pasajero_ids' => $pasajeroIds ?? $this->pasajeros($reserva),
            'reserva_item_ids_manual' => $sueltos,
        ]);
    }

    private function preview(Reserva $reserva, ?array $pasajeroIds = null): array
    {
        $respuesta = app(ReservaFacturacionController::class)->prepararFactura($this->pedidoDeFactura($reserva, $pasajeroIds), (string) $reserva->id);
        $this->assertSame(200, $respuesta->getStatusCode(), json_encode($respuesta->getData(true)));

        return $respuesta->getData(true);
    }

    // Bloqueado por la mezcla de destinos, no por otra regla (por ejemplo, un
    // ítem Amazonía sin confirmar).
    private function assertBloqueadoPorMezcla(Reserva $reserva): void
    {
        $preview = $this->preview($reserva);
        $this->assertTrue($preview['bloqueado_tributario']);
        $this->assertStringContainsString('Solo un mismo tour', $preview['motivo']);
    }

    private function facturarOk(Reserva $reserva, ?array $pasajeroIds = null): Sale
    {
        $respuesta = $this->facturar($reserva, $pasajeroIds)->getData(true);
        $this->assertSame(200, $respuesta['code'], json_encode($respuesta));

        return Sale::find($respuesta['sale_id']);
    }

    private function facturar(Reserva $reserva, ?array $pasajeroIds = null)
    {
        try {
            return app(ReservaFacturacionController::class)->store($this->pedidoDeFactura($reserva, $pasajeroIds, [
                'client_id' => Client::factory()->empresa()->create()->id,
                'tipo_comprobante_codigo' => '01',
            ]), (string) $reserva->id);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json(['code' => $e->getStatusCode(), 'message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    private function usuarioQueFactura(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $branch = Branch::create(['name' => 'Sede Test 1c', 'is_active' => true]);
        SerieComprobante::create([
            'branch_id' => $branch->id, 'tipo_comprobante_codigo' => '01', 'moneda' => 'PEN', 'serie' => 'F1C1',
            'correlativo_actual' => 0, 'correlativo_inicial' => 1, 'fecha_inicio' => HoraPeru::hoyTexto(), 'activo' => true,
        ]);
        $role = Role::create(['name' => 'rol-1c-'.uniqid(), 'guard_name' => 'api']);
        $user = User::factory()->create(['branch_id' => $branch->id, 'role_id' => $role->id]);
        $role->givePermissionTo(Permission::firstOrCreate(['name' => 'emitir_factura', 'guard_name' => 'api']));
        $user->assignRole($role);
        Auth::guard('api')->setUser($user->fresh());
    }
}
