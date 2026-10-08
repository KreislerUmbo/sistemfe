<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\User\UserController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;
use Tests\Feature\Creditos\CreditosTestCase;

/**
 * 07-oct-2026 — solo un Super-Admin asigna ese rol o toca a otro Super-Admin. Antes cualquiera con
 * register_user/edit_user podía hacerse Super-Admin (que se salta todos los permisos) o editar y
 * eliminar al Super-Admin de soporte del tenant.
 */
final class UsuariosSuperAdminTest extends CreditosTestCase
{
    private const GESTION_USUARIOS = ['register_user', 'edit_user', 'delete_user', 'list_user'];

    private Role $superRol;
    private Role $rolNormal;
    private User $super;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superRol = Role::firstOrCreate(['name' => 'Super-Admin', 'guard_name' => 'api']);
        $this->rolNormal = Role::firstOrCreate(['name' => 'Administrador de prueba', 'guard_name' => 'api']);
        $this->super = $this->conRol(User::factory()->create(), $this->superRol);
        $this->admin = $this->usuario(self::GESTION_USUARIOS);   // queda autenticado
    }

    private function conRol(User $usuario, Role $rol): User
    {
        $usuario->forceFill(['role_id' => $rol->id])->save();
        $usuario->syncRoles([$rol]);

        return $usuario->fresh();
    }

    private function como(User $usuario): UserController
    {
        Auth::guard('api')->setUser($usuario);

        return app(UserController::class);
    }

    /** @return array<string, mixed> */
    private function usuarioNuevo(int $rolId, string $email): array
    {
        return ['name' => 'Nuevo', 'surname' => 'Usuario', 'email' => $email, 'password' => 'secreto123', 'role_id' => $rolId, 'state' => 1];
    }

    public function test_quien_no_es_super_admin_no_puede_crear_un_super_admin(): void
    {
        $respuesta = $this->como($this->admin)->store(new Request($this->usuarioNuevo($this->superRol->id, 'escalada@test.pe')));

        $this->assertSame(403, $respuesta->getStatusCode());
        $this->assertNull(User::where('email', 'escalada@test.pe')->first());

        // Con un rol normal sí crea.
        $normal = $this->como($this->admin)->store(new Request($this->usuarioNuevo($this->rolNormal->id, 'normal@test.pe')));
        $this->assertSame(200, $normal->getData(true)['code']);
    }

    public function test_no_puede_ascenderse_a_si_mismo_a_super_admin(): void
    {
        $respuesta = $this->como($this->admin)->update(new Request($this->usuarioNuevo($this->superRol->id, $this->admin->email)), (string) $this->admin->id);

        $this->assertSame(403, $respuesta->getStatusCode());
        $this->assertFalse($this->admin->fresh()->hasRole('Super-Admin'));
    }

    public function test_no_puede_editar_eliminar_ni_dar_permisos_al_super_admin(): void
    {
        $controlador = $this->como($this->admin);
        $id = (string) $this->super->id;

        $this->assertSame(403, $controlador->update(new Request($this->usuarioNuevo($this->rolNormal->id, $this->super->email) + ['password' => 'otra-clave']), $id)->getStatusCode());
        $this->assertSame(403, $controlador->destroy($id)->getStatusCode());
        $this->assertSame(403, $controlador->permisosDirectos(new Request(['permissions' => []]), $id)->getStatusCode());
        $this->assertNotNull($this->super->fresh());
        $this->assertTrue($this->super->fresh()->hasRole('Super-Admin'));
    }

    public function test_el_catalogo_de_roles_oculta_super_admin_a_quien_no_lo_es(): void
    {
        $paraAdmin = collect($this->como($this->admin)->index(new Request())->getData(true)['roles'])->pluck('name');
        $paraSuper = collect($this->como($this->super)->index(new Request())->getData(true)['roles'])->pluck('name');

        $this->assertNotContains('Super-Admin', $paraAdmin);
        $this->assertContains('Super-Admin', $paraSuper);
    }

    public function test_un_super_admin_si_puede_asignar_el_rol_y_gestionar_a_otro_super_admin(): void
    {
        $controlador = $this->como($this->super);

        $this->assertSame(200, $controlador->store(new Request($this->usuarioNuevo($this->superRol->id, 'soporte2@test.pe')))->getData(true)['code']);
        $this->assertTrue(User::where('email', 'soporte2@test.pe')->first()->hasRole('Super-Admin'));

        $otro = $this->conRol(User::factory()->create(), $this->superRol);
        $this->assertSame(200, $controlador->update(new Request($this->usuarioNuevo($this->rolNormal->id, $otro->email)), (string) $otro->id)->getData(true)['code']);
        $this->assertFalse($otro->fresh()->hasRole('Super-Admin'));
    }
}
