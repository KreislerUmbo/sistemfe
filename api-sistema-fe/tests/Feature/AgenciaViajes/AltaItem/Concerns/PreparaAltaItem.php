<?php

namespace Tests\Feature\AgenciaViajes\AltaItem\Concerns;

use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\Guia;
use App\Models\AgenciaViajes\GuiaTarifa;
use App\Models\AgenciaViajes\OpcionHotel;
use App\Models\AgenciaViajes\OpcionHotelTarifa;
use App\Models\AgenciaViajes\OpcionMayorista;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorAlojamientoDetalle;
use App\Models\AgenciaViajes\ProveedorServicio;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\Servicio;
use Illuminate\Support\Facades\DB;

// T-095 — base de los tests de los servicios de alta (Services/AgenciaViajes/AltaItem): Postgres real
// (sistemafe_test_migrations) con una transacción por test que se deshace, y los datos mínimos de cada
// origen. Los servicios se llaman directo, sin pasar por el controller.
trait PreparaAltaItem
{
    protected function prepararBase(): void
    {
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

    protected function deshacerBase(): void
    {
        DB::rollBack();
    }

    /** 2 adultos (30, 28), 1 niño de 8 y 1 infante de 2. Devuelve [alternativa, [ids de pasajeros]]. */
    protected function alternativa(string $moneda = 'PEN', float $tipoCambio = 3.75): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10000000, 99999999), 'full_name' => 'Cliente T095',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-T095-'.uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-09-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $pax = [];
        foreach ([['adulto', 30], ['adulto', 28], ['nino', 8], ['infante', 2]] as [$tipo, $edad]) {
            $pax[] = CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => $tipo, 'edad' => $edad])->id;
        }
        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa T095', 'estado' => 'borrador',
            'moneda_cotizacion' => $moneda, 'tipo_cambio_aplicado' => $tipoCambio, 'tipo_cambio_origen' => 'dia',
        ]);

        return [$alternativa, $pax];
    }

    /** Agrega un destino a la alternativa (para probar el destino por defecto y el explícito). */
    protected function agregarDestino(Alternativa $alternativa, int $orden = 1, string $nombre = 'Destino T095'): int
    {
        return DB::table('alternativa_destinos')->insertGetId([
            'alternativa_id' => $alternativa->id, 'destino_atractivo_id' => DestinoAtractivo::create(['nombre' => $nombre, 'tipo' => 'zona'])->id,
            'orden' => $orden, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Fija el default tributario de la agencia (singleton configuracion_agencia). */
    protected function defaultTributarioDeLaAgencia(string $tipAfeIgv, string $destino): void
    {
        $config = ConfiguracionAgencia::first() ?? ConfiguracionAgencia::create([]);
        $config->update(['tip_afe_igv_default' => $tipAfeIgv, 'destino_tributario_default' => $destino]);
    }

    protected function tarifaProveedor(string $modalidad = 'compartido', bool $conAlojamiento = false, array $extra = []): ProveedorTarifa
    {
        $destino = DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);
        $servicio = Servicio::create(['nombre' => 'Servicio T095']);
        $destinoServicio = DestinoServicio::create(['destino_atractivo_id' => $destino->id, 'servicio_id' => $servicio->id]);
        $proveedor = Proveedor::create(['razon_social' => 'Proveedor T095 SAC', 'estado' => true]);
        if ($conAlojamiento) {
            ProveedorAlojamientoDetalle::create([
                'proveedor_id' => $proveedor->id, 'edad_max_infante_gratis' => 4, 'edad_max_nino_cama_adicional' => 12,
            ]);
        }
        $proveedorServicio = ProveedorServicio::create(['proveedor_id' => $proveedor->id, 'destino_servicio_id' => $destinoServicio->id]);

        return ProveedorTarifa::create($extra + [
            'proveedor_servicio_id' => $proveedorServicio->id,
            'tipo_tarifa' => 'publica',
            'modalidad' => $modalidad,
            'moneda' => 'PEN',
            'precio_costo' => 100,
            'margen_tipo' => 'porcentaje',
            'margen_valor' => 20,
            'precio_venta_adulto' => 150,
            'precio_venta_nino' => 90,
            'precio_venta_infante' => 30,
            'precio_costo_cama_adicional' => $conAlojamiento ? 15 : null,
            'precio_venta_cama_adicional' => $conAlojamiento ? 30 : null,
            'vigente_desde' => '2026-01-01',
            'tip_afe_igv' => '20',
            'destino_tributario' => 'amazonia',
        ]);
    }

    protected function mayorista(Alternativa $alternativa, string $estado = 'elegida'): OpcionMayorista
    {
        $proveedor = Proveedor::create(['razon_social' => 'Mayorista T095 SAC']);

        return OpcionMayorista::create([
            'alternativa_id' => $alternativa->id, 'proveedor_id' => $proveedor->id, 'moneda' => 'USD', 'estado' => $estado,
        ]);
    }

    protected function tarifaHotel(?OpcionMayorista $opcion, string $moneda = 'USD'): OpcionHotelTarifa
    {
        $hotel = OpcionHotel::create(['opcion_mayorista_id' => $opcion?->id, 'nombre_hotel' => 'Hotel T095', 'moneda' => $moneda]);

        return OpcionHotelTarifa::create([
            'opcion_hotel_id' => $hotel->id, 'tipo_habitacion' => 'doble', 'precio_costo' => 80, 'precio_venta' => 120,
            'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
        ]);
    }

    protected function tarifaGuia(array $extra = []): GuiaTarifa
    {
        $destino = DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);
        $guia = Guia::create(['nombre' => 'Guía T095', 'documento' => '12345678', 'telefono' => '999999999', 'activo' => true]);

        return GuiaTarifa::create($extra + [
            'guia_id' => $guia->id, 'destino_id' => $destino->id, 'modalidad' => 'dia_local', 'costo_diario' => 100,
            'tipo_margen' => 'porcentaje', 'margen_valor' => 20, 'moneda' => 'PEN', 'vigente_desde' => '2026-01-01',
        ]);
    }
}
