<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureTenantIsPlataforma;
use App\Services\PlataformaTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// 07-oct-2026 — el catálogo central (sistemas, categorías de sistemas, recursos) solo
// se escribe desde el tenant dueño de la plataforma (config/plataforma.php). Antes
// cualquier Super-Admin de cualquier tenant podía editarlo: permission:X no lo frena,
// Gate::before lo deja pasar.
class CatalogoPlataformaTest extends TestCase
{
    public static function rutasDeEscrituraProvider(): array
    {
        return [
            ['POST', 'api/systems'],
            ['PUT', 'api/systems/{system}'],
            ['DELETE', 'api/systems/{system}'],
            ['POST', 'api/system_categories'],
            ['POST', 'api/system_categories/{id}'],
            ['PUT', 'api/system_categories/{system_category}'],
            ['DELETE', 'api/system_categories/{system_category}'],
            ['POST', 'api/recursos'],
            ['PUT', 'api/recursos/{recurso}'],
            ['DELETE', 'api/recursos/{recurso}'],
        ];
    }

    private function middlewareDe(string $method, string $uri): array
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === $uri && in_array($method, $r->methods(), true)
        );
        $this->assertNotNull($route, "No se encontró la ruta {$method} {$uri}.");

        return $route->gatherMiddleware();
    }

    #[DataProvider('rutasDeEscrituraProvider')]
    public function test_escribir_en_el_catalogo_exige_tenant_plataforma_antes_del_permiso(string $method, string $uri): void
    {
        $middleware = $this->middlewareDe($method, $uri);

        $this->assertContains('tenant.plataforma', $middleware, "{$method} {$uri} no exige tenant.plataforma.");

        $permiso = collect($middleware)->search(fn ($m) => str_starts_with($m, 'permission:'));
        $this->assertNotFalse($permiso, "{$method} {$uri} perdió su permission:X.");
        $this->assertLessThan($permiso, array_search('tenant.plataforma', $middleware, true));
    }

    public function test_leer_el_catalogo_sigue_abierto_a_cualquier_tenant(): void
    {
        // "Manuales y videos" lo usa cualquier cliente — solo lectura.
        $this->assertNotContains('tenant.plataforma', $this->middlewareDe('GET', 'api/recursos'));
        $this->assertNotContains('tenant.plataforma', $this->middlewareDe('GET', 'api/systems'));
    }

    public function test_solo_los_tenants_configurados_son_plataforma(): void
    {
        config(['plataforma.tenants' => ['umbo']]);

        $this->assertTrue(PlataformaTenant::es('umbo'));
        $this->assertFalse(PlataformaTenant::es('market'));
        $this->assertFalse(PlataformaTenant::es(null));

        config(['plataforma.tenants' => []]);
        $this->assertFalse(PlataformaTenant::es('umbo'), 'Sin configurar, nadie es plataforma (falla cerrado).');
    }

    public function test_el_middleware_bloquea_sin_tenant_plataforma(): void
    {
        $this->expectException(HttpException::class);

        (new EnsureTenantIsPlataforma())->handle(new Request(), fn () => 'ok');
    }
}
