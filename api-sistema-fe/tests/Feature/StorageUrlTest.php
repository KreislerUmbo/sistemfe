<?php

namespace Tests\Feature;

use App\Services\StorageUrl;
use Illuminate\Http\Request;
use Tests\TestCase;

// Cubre el bug real: URLs de storage armadas con env('APP_URL') fijo
// mostraban el host de OTRO tenant (ver CLAUDE.md), y además /storage/
// directo (symlink estático, no tenant-aware) da 403 para cualquier tenant
// salvo el legado "umbo". resolve() usa tenant_asset() (ruta
// /tenancy/assets/{path} de stancl/tenancy), que sí refleja siempre el host
// de la petición ACTUAL y resuelve el archivo del tenant correcto — no toca
// BD, no necesita el setup de Postgres real del resto de tests del vertical.
class StorageUrlTest extends TestCase
{
    private function simularPeticionDesde(string $baseUrl): void
    {
        $this->app->instance('request', Request::create($baseUrl.'/cualquier-ruta'));
    }

    public function test_resolve_usa_el_host_de_la_peticion_actual_no_uno_fijo(): void
    {
        $this->simularPeticionDesde('http://umbo.sistemafe.test:8000');
        $this->assertSame(
            'http://umbo.sistemafe.test:8000/tenancy/assets/products/foto.jpg',
            StorageUrl::resolve('products/foto.jpg')
        );

        // Mismo path, otro tenant en la MISMA corrida — la URL debe cambiar
        // con el host, no quedar pegada al primer valor resuelto.
        $this->simularPeticionDesde('http://agencia-demo.sistemafe.test:8000');
        $this->assertSame(
            'http://agencia-demo.sistemafe.test:8000/tenancy/assets/products/foto.jpg',
            StorageUrl::resolve('products/foto.jpg')
        );
    }

    public function test_resolve_quita_el_slash_inicial_del_path(): void
    {
        $this->simularPeticionDesde('http://umbo.sistemafe.test:8000');

        $this->assertSame(
            'http://umbo.sistemafe.test:8000/tenancy/assets/products/foto.jpg',
            StorageUrl::resolve('/products/foto.jpg')
        );
    }

    public function test_resolve_devuelve_null_si_el_path_es_vacio_o_null(): void
    {
        $this->simularPeticionDesde('http://umbo.sistemafe.test:8000');

        $this->assertNull(StorageUrl::resolve(null));
        $this->assertNull(StorageUrl::resolve(''));
    }

    public function test_resolve_muchas_aplica_resolve_a_cada_elemento_del_array(): void
    {
        $this->simularPeticionDesde('http://agencia-demo.sistemafe.test:8000');

        $this->assertSame(
            [
                'http://agencia-demo.sistemafe.test:8000/tenancy/assets/a.jpg',
                'http://agencia-demo.sistemafe.test:8000/tenancy/assets/b.jpg',
            ],
            StorageUrl::resolveMuchas(['a.jpg', 'b.jpg'])
        );
    }

    public function test_relativo_recupera_el_path_guardado_en_bd_desde_una_url_ya_resuelta(): void
    {
        $this->assertSame(
            'destinos-atractivos/foto.jpg',
            StorageUrl::relativo('http://umbo.sistemafe.test:8000/tenancy/assets/destinos-atractivos/foto.jpg')
        );
    }

    public function test_relativo_es_idempotente_si_ya_recibe_un_path_relativo(): void
    {
        $this->assertSame(
            'destinos-atractivos/foto.jpg',
            StorageUrl::relativo('destinos-atractivos/foto.jpg')
        );
    }
}
