<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\SalidaOperativaController;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorServicio;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\SalidaOperativa;
use App\Models\AgenciaViajes\SalidaVehiculo;
use App\Models\AgenciaViajes\Servicio;
use App\Models\AgenciaViajes\TemporadaOcurrencia;
use App\Models\User;
use App\Services\AgenciaViajes\TemporadaResolverService;
use App\Services\AgenciaViajes\TourCostoEstimadoService;
use App\Services\Dashboard\DashboardService;
use App\Services\HoraPeru;
use App\Services\Tenancy\GiroActual;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\AgenciaViajes\Concerns\SalidasFixtures;
use Tests\TestCase;

// T-089 — ganancia ESTIMADA de una salida (P8 a). Escenario base de la tarea:
// movilidad privada 300, guía 150 por día (1 día), entrada 20 + almuerzo 25 por
// persona, precio 205 por persona. Fixtures en Concerns\SalidasFixtures
// (Postgres real, transacción por test).
class GananciaSalidaTest extends TestCase
{
    use SalidasFixtures;

    private PaquetePlantilla $tour;

    private ProveedorTarifa $movilidad;

    private function escenarioBase(): void
    {
        $this->tour = $this->crearTour(['precio_venta_final' => 205, 'costo_estimado_persona' => null, 'pax_minimo_salida' => 4]);
        $this->movilidad = $this->tarifaPrivada('Movilidad privada', 300);

        PaquetePlantillaItem::create([
            'paquete_plantilla_id' => $this->tour->id,
            'proveedor_tarifa_id' => $this->movilidad->id,
            'destino_servicio_id' => $this->movilidad->proveedorServicio->destino_servicio_id,
            'costo_estimado_persona' => 75, 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional', 'orden' => 1,
        ]);
        $guia = $this->guiaTarifa();
        $guia->update(['costo_diario' => 150]);
        PaquetePlantillaItem::create([
            'paquete_plantilla_id' => $this->tour->id, 'guia_tarifa_id' => $guia->id,
            'tip_afe_igv' => '10', 'destino_tributario' => 'nacional', 'orden' => 2,
        ]);
        $this->incluye($this->tour, 20, nombre: 'Entrada');
        $this->incluye($this->tour, 25, nombre: 'Almuerzo');
    }

    private function tarifaPrivada(string $nombre, float $costo, array $extra = []): ProveedorTarifa
    {
        $servicio = Servicio::create(['nombre' => $nombre.' '.uniqid()]);
        $destinoServicio = DestinoServicio::create(['destino_atractivo_id' => $this->destino->id, 'servicio_id' => $servicio->id]);
        $proveedor = Proveedor::create(['razon_social' => 'Proveedor '.uniqid(), 'estado' => true]);
        $proveedorServicio = ProveedorServicio::create(['proveedor_id' => $proveedor->id, 'destino_servicio_id' => $destinoServicio->id]);

        return ProveedorTarifa::create($extra + [
            'proveedor_servicio_id' => $proveedorServicio->id, 'tipo_tarifa' => 'publica', 'modalidad' => 'privado',
            'moneda' => 'PEN', 'precio_costo' => $costo, 'margen_tipo' => 'porcentaje', 'margen_valor' => 20,
            'precio_venta_adulto' => $costo * 1.2, 'vigente_desde' => HoraPeru::hoyTexto(),
            'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
        ]);
    }

    private function adultos(int $n): array
    {
        return array_map(fn ($i) => ['adulto', 30 + $i], range(0, $n - 1));
    }

    // Dos reservas de 2 adultos cada una en la misma salida (15-nov-2026).
    private function salidaConCuatro(): array
    {
        $this->escenarioBase();
        $a = $this->reservaConTour($this->tour, $this->adultos(2));
        $b = $this->reservaConTour($this->tour, $this->adultos(2));
        $salida = $this->salidaDe($a);
        $this->assertSame($salida->id, $this->salidaDe($b)->id);

        return [$salida, $a, $b];
    }

    private function estimar(SalidaOperativa $s): array
    {
        return app(TourCostoEstimadoService::class)->estimarSalida(SalidaOperativa::findOrFail($s->id));
    }

    // CA1
    public function test_salida_con_cuatro_pasajeros(): void
    {
        [$salida] = $this->salidaConCuatro();

        $g = $this->estimar($salida);

        $this->assertEqualsWithDelta(820, $g['cobrado'], 0.001);
        $this->assertEqualsWithDelta(450, $g['costo_fijo'], 0.001);
        $this->assertEqualsWithDelta(180, $g['costo_variable'], 0.001);
        $this->assertEqualsWithDelta(45, $g['costo_por_persona'], 0.001);
        $this->assertEqualsWithDelta(190, $g['ganancia'], 0.001);
        $this->assertSame(4, $g['pasajeros']);
        $this->assertTrue($g['completo']);
        $this->assertSame([], $g['avisos']);
        // 190 ÷ 630 de costo y 190 ÷ 820 de venta.
        $this->assertEqualsWithDelta(30.16, $g['markup_pct'], 0.01);
        $this->assertEqualsWithDelta(23.17, $g['margen_pct'], 0.01);
    }

    // CA2
    public function test_salida_bajo_minimo_pierde(): void
    {
        $this->escenarioBase();
        $reserva = $this->reservaConTour($this->tour, $this->adultos(2));

        $g = $this->estimar($this->salidaDe($reserva));

        $this->assertEqualsWithDelta(410, $g['cobrado'], 0.001);
        $this->assertEqualsWithDelta(-130, $g['ganancia'], 0.001);
    }

    // CA3
    public function test_dos_vehiculos(): void
    {
        [$salida] = $this->salidaConCuatro();
        foreach (['Van 1', 'Van 2'] as $i => $nombre) {
            SalidaVehiculo::create(['salida_operativa_id' => $salida->id, 'descripcion' => $nombre, 'capacidad' => 10, 'orden' => $i]);
        }

        $g = $this->estimar($salida);

        // Movilidad 300 × 2 + guía 150 (el guía es uno por salida).
        $this->assertEqualsWithDelta(750, $g['costo_fijo'], 0.001);
        $this->assertEqualsWithDelta(-110, $g['ganancia'], 0.001);
    }

    // CA4
    public function test_infante_y_cancelada(): void
    {
        $this->escenarioBase();
        $a = $this->reservaConTour($this->tour, $this->adultos(2));
        $conInfante = $this->reservaConTour($this->tour, [['adulto', 40], ['infante', 1]]);
        $cancelada = $this->reservaConTour($this->tour, $this->adultos(2));
        $salida = $this->salidaDe($a);
        Reserva::whereKey($cancelada->id)->update(['estado' => 'cancelada']);

        $g = $this->estimar($salida);

        // 2 + 1 que ocupan asiento (el infante no cuenta); la cancelada ni cobra ni cuenta.
        $this->assertSame(3, $g['pasajeros']);
        $this->assertEqualsWithDelta(135, $g['costo_variable'], 0.001);
        $this->assertEqualsWithDelta(410 + 205, $g['cobrado'], 0.001);
    }

    // CA5
    public function test_tarifa_de_temporada(): void
    {
        [$salida] = $this->salidaConCuatro();
        $this->tarifaPrivada('Movilidad alta', 400, [
            'proveedor_servicio_id' => $this->movilidad->proveedor_servicio_id,
            'temporada_id' => 9001,
        ]);
        $resolver = Mockery::mock(TemporadaResolverService::class);
        $resolver->shouldReceive('resolver')->andReturn(new TemporadaOcurrencia(['temporada_id' => 9001]));
        $this->app->instance(TemporadaResolverService::class, $resolver);

        $g = $this->estimar($salida);

        // Movilidad de temporada 400 + guía 150.
        $this->assertEqualsWithDelta(550, $g['costo_fijo'], 0.001);
        $this->assertEqualsWithDelta(90, $g['ganancia'], 0.001);
    }

    // CA6
    public function test_servicio_sin_costo(): void
    {
        [$salida] = $this->salidaConCuatro();
        $item = $this->incluye($this->tour, 0, nombre: 'Bote');
        $item->update(['costo_estimado_persona' => null]);

        $g = $this->estimar($salida);

        $this->assertFalse($g['completo']);
        $this->assertCount(1, $g['avisos']);
        $this->assertStringContainsString($item->destinoServicio->servicio->nombre, $g['avisos'][0]);
    }

    // CA7
    public function test_otra_moneda(): void
    {
        [$salida, , $b] = $this->salidaConCuatro();
        $b->alternativa->update(['moneda_cotizacion' => 'USD']);

        $g = $this->estimar($salida);

        $this->assertEqualsWithDelta(410, $g['cobrado'], 0.001);
        $this->assertSame(['1 reserva en USD no está sumada.'], $g['avisos']);
    }

    // CA8
    public function test_sin_permiso_no_ve_montos(): void
    {
        [$salida] = $this->salidaConCuatro();
        $conPermiso = $this->verTodasLasReservas();

        $this->assertEqualsWithDelta(190, $this->show($salida)['ganancia']['ganancia'], 0.001);
        $this->assertEqualsWithDelta(190, $this->salidasDeInicio($conPermiso)[0]['ganancia']['ganancia'], 0.001);

        $sin = User::factory()->create([
            'role_id' => Role::create(['name' => 'rol-sin-ver-todas-'.uniqid(), 'guard_name' => 'api'])->id,
        ]);
        $sin->givePermissionTo(Permission::firstOrCreate(['name' => 'reservas.ver', 'guard_name' => 'api']));
        Auth::guard('api')->setUser($sin->fresh());

        $this->assertArrayNotHasKey('ganancia', $this->show($salida));
        $this->assertArrayNotHasKey('ganancia', $this->salidasDeInicio($sin->fresh())[0]);
    }

    private function show(SalidaOperativa $salida): array
    {
        return app(SalidaOperativaController::class)->show((string) $salida->id)->getData(true);
    }

    private function salidasDeInicio(User $usuario): array
    {
        $this->app->instance(GiroActual::class, new GiroActual('agencia_viajes'));
        $inicio = app(DashboardService::class)->inicio($usuario, CarbonImmutable::parse('2026-11-12', 'America/Lima'));

        return $inicio['bloques']['proximos']['salidas'];
    }
}
