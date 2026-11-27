<?php

namespace Tests\Feature\AgenciaViajes;

use Illuminate\Routing\Route as RutaLaravel;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

// T-093 — Barrido de permisos de las rutas de Agencia.
//
// Hoy casi todos los tests de Agencia llaman al controller directo, así que
// una ruta nueva sin ->middleware('permission:…') (o con un permiso más débil)
// no rompería nada. Este test lee la lista REAL de rutas registradas y:
//   1. falla si una ruta de App\Http\Controllers\AgenciaViajes\* no exige permiso
//      (salvo la lista explícita de excepciones, abajo, con su motivo);
//   2. fija el permiso exacto de los grupos sensibles (PERMISO_POR_GRUPO).
//
// No usa base de datos ni el stack de tenancy: solo inspecciona el registro de
// rutas, igual que GateBucketARoutesTest.
class RutasAgenciaPermisosTest extends TestCase
{
    private const NAMESPACE_AGENCIA = 'App\\Http\\Controllers\\AgenciaViajes\\';

    // URI => motivo. Estas rutas NO llevan permission: pero deben llevar `signed`.
    private const EXCEPCIONES_FIRMADAS = [
        'api/reporte-operativo-pdf' => 'URL firmada y con vencimiento, generada por reporte-operativo/pdf-url (que sí exige permiso); se abre en una pestaña nueva sin token.',
    ];

    // [métodos|null (todos), patrón de URI (fnmatch), permisos exactos (OR del middleware)].
    // La primera regla que coincide con la ruta es la que vale: lo específico va primero.
    private const PERMISO_POR_GRUPO = [
        // Configuración de la agencia
        [null, 'api/configuracion-*', ['agencia.configuracion']],
        [['POST', 'PUT'], 'api/crm-motivos-resultado*', ['agencia.configuracion']],
        [['GET'], 'api/crm-motivos-resultado', ['agencia.configuracion', 'cotizaciones.editar']],
        // Catálogos
        [null, 'api/proveedores*', ['agencia.proveedores']],
        [null, 'api/proveedor-servicios/*', ['agencia.proveedores']],
        [null, 'api/proveedor-tipos*', ['agencia.proveedores']],
        [null, 'api/aerolineas*', ['agencia.paquetes', 'cotizaciones.editar', 'reservas.editar']],
        [['PUT', 'PATCH', 'DELETE'], 'api/proveedor-tarifas/*', ['agencia.proveedores']],
        [null, 'api/guias*', ['agencia.guias']],
        [null, 'api/guia-tarifas/*', ['agencia.guias']],
        [null, 'api/destinos-atractivos*', ['agencia.destinos']],
        [null, 'api/destino-servicio/*', ['agencia.destinos']],
        [null, 'api/servicios*', ['agencia.destinos']],
        [null, 'api/temporadas*', ['agencia.temporadas']],
        [null, 'api/paquetes-plantilla*', ['agencia.paquetes']],
        [null, 'api/paquete-plantilla-items/*', ['agencia.paquetes']],
        [null, 'api/tour-itinerario-items/*', ['agencia.paquetes']],
        [null, 'api/tours-conversion*', ['agencia.paquetes']],
        // Traspaso de trabajo entre usuarios
        [null, 'api/users/*/traspasar-trabajo', ['edit_user']],
        [null, 'api/users/*/trabajo-abierto', ['edit_user']],
        // Reservas: facturación, anticipos y modificación
        [null, 'api/reservas/*/modificacion*', ['reservas.modificar']],
        [['POST'], 'api/reservas/*/facturar', ['reservas.editar']],
        [['GET'], 'api/reservas/*/preparar-factura', ['reservas.ver', 'reservas.ver_todas']],
        [['PUT'], 'api/reservas/*/facturacion-externa', ['reservas.editar']],
        [['POST'], 'api/reservas/*/override-tratamiento-tributario', ['reservas.editar']],
        [['POST'], 'api/reservas/*/anticipos', ['reservas.crear']],
        [['DELETE'], 'api/reserva-anticipos/*', ['reservas.editar']],
        // Reservas: cambiar o cancelar
        [['PUT'], 'api/reservas/*/cancelar', ['reservas.editar']],
        [['POST'], 'api/reservas/*/reprogramar', ['reservas.editar']],
        [['POST'], 'api/reservas/*/reasignar-*', ['reservas.editar']],
        [['POST'], 'api/reservas/*/sincronizar-items', ['reservas.editar']],
        [['PUT', 'DELETE'], 'api/reserva-pasajeros/*', ['reservas.editar']],
        [['PUT', 'DELETE'], 'api/reserva-items/*', ['reservas.editar']],
        [['POST'], 'api/alternativas/*/aceptar', ['reservas.crear']],
        [['POST'], 'api/venta-directa*', ['reservas.crear']],
        // Cotizaciones: enviar por correo, editar y borrar
        [null, 'api/alternativas/*/enviar-correo', ['cotizaciones.enviar_correo']],
        [null, 'api/alternativas/*/envio-correo', ['cotizaciones.enviar_correo']],
        [['PUT', 'DELETE'], 'api/cotizaciones/*', ['cotizaciones.editar']],
        [['PUT', 'DELETE'], 'api/alternativas/*', ['cotizaciones.editar']],
        [['PUT', 'DELETE'], 'api/alternativa-items/*', ['cotizaciones.editar']],
    ];

    /** @return array<int, RutaLaravel> */
    private function rutasDeAgencia(): array
    {
        $rutas = [];
        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            $controller = $ruta->getControllerClass();
            if ($controller && str_starts_with($controller, self::NAMESPACE_AGENCIA)) {
                $rutas[] = $ruta;
            }
        }

        return $rutas;
    }

    private function etiqueta(RutaLaravel $ruta): string
    {
        return implode('|', array_diff($ruta->methods(), ['HEAD'])).' '.$ruta->uri().' ('.$ruta->getActionName().')';
    }

    /**
     * Permisos que exige la ruta vía PermissionMiddleware, ordenados; [] si no tiene.
     *
     * @return array<int, string>
     */
    private function permisosDe(RutaLaravel $ruta): array
    {
        $permisos = [];
        foreach ($ruta->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }
            if (preg_match('/^(?:permission|Spatie\\\\Permission\\\\Middleware\\\\PermissionMiddleware):(.+)$/', $middleware, $m)) {
                array_push($permisos, ...explode('|', $m[1]));
            }
        }
        sort($permisos);

        return $permisos;
    }

    private function esFirmada(RutaLaravel $ruta): bool
    {
        foreach ($ruta->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && ($middleware === 'signed' || str_contains($middleware, 'ValidateSignature'))) {
                return true;
            }
        }

        return false;
    }

    public function test_hay_rutas_de_agencia_para_revisar(): void
    {
        // Si cambia el namespace o el registro de rutas, que no pase en vacío.
        $this->assertGreaterThan(200, count($this->rutasDeAgencia()));
    }

    // CA1
    public function test_toda_ruta_de_agencia_exige_un_permiso(): void
    {
        $sinPermiso = [];
        foreach ($this->rutasDeAgencia() as $ruta) {
            if (isset(self::EXCEPCIONES_FIRMADAS[$ruta->uri()])) {
                continue;
            }
            if ($this->permisosDe($ruta) === []) {
                $sinPermiso[] = $this->etiqueta($ruta);
            }
        }

        $this->assertSame([], $sinPermiso, "Rutas de Agencia sin ->middleware('permission:…'):\n".implode("\n", $sinPermiso));
    }

    public function test_las_excepciones_son_rutas_firmadas_que_existen(): void
    {
        $porUri = [];
        foreach ($this->rutasDeAgencia() as $ruta) {
            $porUri[$ruta->uri()] = $ruta;
        }

        foreach (array_keys(self::EXCEPCIONES_FIRMADAS) as $uri) {
            $this->assertArrayHasKey($uri, $porUri, "La excepción $uri ya no existe: quítala de la lista.");
            $this->assertTrue($this->esFirmada($porUri[$uri]), "La excepción $uri debe llevar el middleware 'signed'.");
            $this->assertSame([], $this->permisosDe($porUri[$uri]), "$uri ya exige permiso: quítala de las excepciones.");
        }
    }

    // CA2
    public function test_los_grupos_sensibles_exigen_su_permiso_exacto(): void
    {
        $errores = [];
        $usadas = [];

        foreach ($this->rutasDeAgencia() as $ruta) {
            $metodos = array_diff($ruta->methods(), ['HEAD']);
            foreach (self::PERMISO_POR_GRUPO as $i => [$verbos, $patron, $esperado]) {
                if (! fnmatch($patron, $ruta->uri())) {
                    continue;
                }
                if ($verbos !== null && array_intersect($verbos, $metodos) === []) {
                    continue;
                }
                $usadas[$i] = true;
                sort($esperado);
                $real = $this->permisosDe($ruta);
                if ($real !== $esperado) {
                    $errores[] = $this->etiqueta($ruta).' exige ['.implode(', ', $real).'], se esperaba ['.implode(', ', $esperado).']';
                }
                break; // primera regla que coincide
            }
        }

        $reglasSinRutas = [];
        foreach (self::PERMISO_POR_GRUPO as $i => [, $patron]) {
            if (! isset($usadas[$i])) {
                $reglasSinRutas[] = $patron;
            }
        }

        $this->assertSame([], $errores, "Permiso distinto del esperado:\n".implode("\n", $errores));
        $this->assertSame([], $reglasSinRutas, "Reglas que ya no coinciden con ninguna ruta (¿se renombró?):\n".implode("\n", $reglasSinRutas));
    }
}
