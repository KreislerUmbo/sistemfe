<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Guia;
use App\Models\AgenciaViajes\GuiaTarifa;
use App\Models\AgenciaViajes\OpcionHotel;
use App\Models\AgenciaViajes\OpcionHotelTarifa;
use App\Models\AgenciaViajes\OpcionMayorista;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ReservaItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Sesión M2 — brief PEGAR-EN-CLAUDE-CODE-matriz-hoteles-m2-trazabilidad.md
// (plan-matriz-hoteles-cotizador.md Ronda 5). Mismo patrón de
// infraestructura que el resto de la suite: Postgres real
// (sistemafe_test_migrations), transacción por test revertida.
class SesionM2TrazabilidadHotelReservaTest extends TestCase
{
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
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function crearAlternativa(string $estado = 'borrador'): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '66778899', 'full_name' => 'Cliente Test M2',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-M2-' . random_int(1000, 9999), 'cliente_id' => $clienteId,
            'destino' => 'Panamá', 'fecha_viaje_desde' => '2026-11-01', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa M2', 'estado' => $estado,
            'moneda_cotizacion' => 'USD', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
    }

    // ── §1 — crearItemMayorista() escribe opcion_hotel_tarifa_id ────────

    public function test_crear_item_mayorista_escribe_opcion_hotel_tarifa_id(): void
    {
        $alternativa = $this->crearAlternativa();
        $proveedor = Proveedor::create(['razon_social' => 'Mayorista Test M2 SAC', 'estado' => true]);
        $opcion = OpcionMayorista::create(['alternativa_id' => $alternativa->id, 'proveedor_id' => $proveedor->id, 'moneda' => 'USD', 'estado' => 'elegida']);
        $hotel = OpcionHotel::create(['opcion_mayorista_id' => $opcion->id, 'nombre_hotel' => 'Hotel Test M2', 'moneda' => 'USD']);
        $tarifa = OpcionHotelTarifa::create(['opcion_hotel_id' => $hotel->id, 'tipo_habitacion' => 'doble', 'precio_costo' => 100, 'precio_venta' => 150]);

        $response = app(AlternativaItemController::class)->store(new Request([
            'origen_tipo' => 'mayorista', 'opcion_mayorista_id' => $opcion->id, 'opcion_hotel_tarifa_id' => $tarifa->id,
        ]), (string) $alternativa->id);

        $this->assertSame(200, $response->getStatusCode());
        $item = AlternativaItem::findOrFail($response->getData(true)['alternativa_item']['id']);
        $this->assertSame($tarifa->id, $item->opcion_hotel_tarifa_id);
    }

    // ── §2 — crearReservaDesdeAlternativa() filtra opciones rechazadas ──

    public function test_aceptar_reserva_regresion_sin_ningun_grupo(): void
    {
        $alternativa = $this->crearAlternativa();
        AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => AlternativaItem::ORIGEN_MANUAL, 'descripcion_manual' => 'Ítem 1',
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'USD', 'costo_snapshot' => 10, 'precio_venta_snapshot' => 15, 'precio_convertido' => 15,
        ]);
        AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => AlternativaItem::ORIGEN_MANUAL, 'descripcion_manual' => 'Ítem 2',
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'USD', 'costo_snapshot' => 10, 'precio_venta_snapshot' => 15, 'precio_convertido' => 15,
        ]);

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        $this->assertSame(2, ReservaItem::where('reserva_id', $reserva->id)->count());
    }

    public function test_crear_reserva_genera_un_solo_item_para_grupo_resuelto_no_uno_por_opcion(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $elegida = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => AlternativaItem::ORIGEN_MANUAL, 'descripcion_manual' => 'Hotel A',
            'grupo_opcion_id' => $grupo, 'opcion_elegida' => true,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'USD', 'costo_snapshot' => 100, 'precio_venta_snapshot' => 150, 'precio_convertido' => 150,
        ]);
        AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => AlternativaItem::ORIGEN_MANUAL, 'descripcion_manual' => 'Hotel B',
            'grupo_opcion_id' => $grupo, 'opcion_elegida' => false,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'USD', 'costo_snapshot' => 120, 'precio_venta_snapshot' => 180, 'precio_convertido' => 180,
        ]);
        AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => AlternativaItem::ORIGEN_MANUAL, 'descripcion_manual' => 'Hotel C',
            'grupo_opcion_id' => $grupo, 'opcion_elegida' => false,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'USD', 'costo_snapshot' => 90, 'precio_venta_snapshot' => 140, 'precio_convertido' => 140,
        ]);

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        $items = ReservaItem::where('reserva_id', $reserva->id)->get();
        $this->assertCount(1, $items, 'debe crear 1 solo ReservaItem para el grupo, no 3');
        $this->assertSame($elegida->id, $items->first()->alternativa_item_id);
    }

    // ── §3 — reserva_items.opcion_hotel_tarifa_id copiado ───────────────

    public function test_opcion_hotel_tarifa_id_se_copia_a_la_reserva(): void
    {
        $alternativa = $this->crearAlternativa();
        $proveedor = Proveedor::create(['razon_social' => 'Mayorista Test M2 SAC', 'estado' => true]);
        $opcion = OpcionMayorista::create(['alternativa_id' => $alternativa->id, 'proveedor_id' => $proveedor->id, 'moneda' => 'USD', 'estado' => 'elegida']);
        $hotel = OpcionHotel::create(['opcion_mayorista_id' => $opcion->id, 'nombre_hotel' => 'Hotel Test M2', 'moneda' => 'USD']);
        $tarifa = OpcionHotelTarifa::create(['opcion_hotel_id' => $hotel->id, 'tipo_habitacion' => 'doble', 'precio_costo' => 100, 'precio_venta' => 150]);

        $item = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => AlternativaItem::ORIGEN_MAYORISTA,
            'opcion_mayorista_id' => $opcion->id, 'opcion_hotel_tarifa_id' => $tarifa->id,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'USD', 'costo_snapshot' => 100, 'precio_venta_snapshot' => 150, 'precio_convertido' => 150,
        ]);

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        $reservaItem = ReservaItem::where('reserva_id', $reserva->id)->where('alternativa_item_id', $item->id)->first();
        $this->assertSame($tarifa->id, $reservaItem->opcion_hotel_tarifa_id);
    }

    // ── §4 — resolver de nombre centralizado ────────────────────────────

    public function test_resolver_mayorista_audiencia_interno_muestra_proveedor_real(): void
    {
        $alternativa = $this->crearAlternativa();
        $proveedor = Proveedor::create(['razon_social' => 'Mayorista Real SAC', 'estado' => true]);
        $opcion = OpcionMayorista::create(['alternativa_id' => $alternativa->id, 'proveedor_id' => $proveedor->id, 'moneda' => 'USD', 'estado' => 'elegida', 'descripcion_publica' => 'Paquete público']);
        $item = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => AlternativaItem::ORIGEN_MAYORISTA, 'opcion_mayorista_id' => $opcion->id,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'USD', 'costo_snapshot' => 100, 'precio_venta_snapshot' => 150, 'precio_convertido' => 150,
        ]);

        $nombreInterno = ReservaController::resolverNombreItem($item->fresh('opcionMayorista.proveedor'));
        $nombreCliente = ReservaController::resolverNombreItem($item->fresh('opcionMayorista.proveedor'), null, 'cliente');

        $this->assertSame('Mayorista Real SAC', $nombreInterno);
        $this->assertSame('Paquete público', $nombreCliente);
    }

    public function test_resolver_incorpora_rama_guia_que_antes_faltaba(): void
    {
        $alternativa = $this->crearAlternativa();
        $destino = \App\Models\AgenciaViajes\DestinoAtractivo::first() ?? \App\Models\AgenciaViajes\DestinoAtractivo::create(['nombre' => 'Panamá Test', 'tipo' => 'zona']);
        $guia = Guia::create(['nombre' => 'Juan Pérez', 'documento' => '12345678', 'telefono' => '999888777', 'estado' => true]);
        $guiaTarifa = GuiaTarifa::create([
            'guia_id' => $guia->id, 'destino_id' => $destino->id, 'modalidad' => 'dia_local', 'costo_diario' => 50,
            'tipo_margen' => 'fijo', 'margen_valor' => 10, 'moneda' => 'USD', 'vigente_desde' => '2026-01-01',
        ]);
        $item = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => AlternativaItem::ORIGEN_GUIA, 'guia_tarifa_id' => $guiaTarifa->id,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'USD', 'costo_snapshot' => 50, 'precio_venta_snapshot' => 60, 'precio_convertido' => 60,
        ]);

        $nombre = ReservaController::resolverNombreItem($item->fresh('guiaTarifa.guia'));

        $this->assertSame('Guía de turismo — Juan Pérez', $nombre);
    }

    public function test_resolver_incorpora_fallback_opcion_hotel_tarifa(): void
    {
        $alternativa = $this->crearAlternativa();
        $proveedor = Proveedor::create(['razon_social' => 'Mayorista Test M2 SAC', 'estado' => true]);
        $opcion = OpcionMayorista::create(['alternativa_id' => $alternativa->id, 'proveedor_id' => $proveedor->id, 'moneda' => 'USD', 'estado' => 'elegida']);
        $hotel = OpcionHotel::create(['opcion_mayorista_id' => $opcion->id, 'nombre_hotel' => 'Hotel Fallback Test', 'moneda' => 'USD']);
        $tarifa = OpcionHotelTarifa::create(['opcion_hotel_id' => $hotel->id, 'tipo_habitacion' => 'doble', 'precio_costo' => 100, 'precio_venta' => 150]);

        // origen_tipo=proveedor sin proveedor_tarifa_id (caso ad-hoc futuro
        // de M3) — solo opcion_hotel_tarifa_id. La rama nueva debe
        // resolverlo antes de caer al genérico 'Servicio'.
        $item = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => AlternativaItem::ORIGEN_PROVEEDOR, 'opcion_hotel_tarifa_id' => $tarifa->id,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'USD', 'costo_snapshot' => 100, 'precio_venta_snapshot' => 150, 'precio_convertido' => 150,
        ]);

        $nombre = ReservaController::resolverNombreItem($item->fresh('opcionHotelTarifa.opcionHotel'));

        $this->assertSame('Hotel Fallback Test · doble', $nombre);
    }

    // Regresión — ítem 100% sin grupo/mayorista/guia/hotel-ad-hoc se
    // comporta exactamente igual que antes de M2 (manual, servicio
    // genérico).
    public function test_resolver_regresion_items_normales_sin_cambios(): void
    {
        $alternativa = $this->crearAlternativa();
        $itemManual = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => AlternativaItem::ORIGEN_MANUAL, 'descripcion_manual' => 'Traslado suelto',
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'USD', 'costo_snapshot' => 10, 'precio_venta_snapshot' => 15, 'precio_convertido' => 15,
        ]);

        $this->assertSame('Traslado suelto', ReservaController::resolverNombreItem($itemManual));
    }
}
