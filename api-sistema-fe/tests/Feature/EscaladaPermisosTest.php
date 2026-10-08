<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Role\RoleController;
use App\Http\Controllers\User\UserController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Creditos\CreditosTestCase;

/**
 * Auditoría de seguridad 08-oct-2026, hallazgo 5 — nadie otorga ni toma el control de
 * permisos que él mismo no tiene. Antes, quien tenía edit_user podía asignarse el rol
 * "Administrador" (o cambiarle la contraseña a un Administrador), y quien tenía
 * edit_role podía darle a su propio rol cualquier permiso. Ver EscaladaPermisos.
 */
final class EscaladaPermisosTest extends CreditosTestCase
{
    private const GERENTE = ['register_user', 'edit_user', 'delete_user', 'list_user', 'register_role', 'edit_role'];
    private const SOLO_ADMIN = 'cash.approve_expenses';

    private Role $rolAdmin;
    private Role $rolCajero;
    private User $gerente;
    private User $admin;
    private User $cajero;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rolAdmin = $this->rol('Administrador de prueba', [...self::GERENTE, self::SOLO_ADMIN]);
        $this->rolCajero = $this->rol('Cajero de prueba', ['list_user']);
        $this->admin = $this->conRol(User::factory()->create(), $this->rolAdmin);
        $this->cajero = $this->conRol(User::factory()->create(), $this->rolCajero);
        $this->gerente = $this->usuario(self::GERENTE);
    }

    private function rol(string $nombre, array $permisos): Role
    {
        $rol = Role::firstOrCreate(['name' => $nombre, 'guard_name' => 'api']);
        foreach ($permisos as $permiso) {
            $rol->givePermissionTo(Permission::firstOrCreate(['guard_name' => 'api', 'name' => $permiso]));
        }

        return $rol;
    }

    private function conRol(User $usuario, Role $rol): User
    {
        $usuario->forceFill(['role_id' => $rol->id])->save();
        $usuario->syncRoles([$rol]);

        return $usuario->fresh();
    }

    private function usuarios(User $actor): UserController
    {
        Auth::guard('api')->setUser($actor->fresh());

        return app(UserController::class);
    }

    private function roles(User $actor): RoleController
    {
        Auth::guard('api')->setUser($actor->fresh());

        return app(RoleController::class);
    }

    /** @return array<string, mixed> */
    private function formUsuario(User|string $usuario, int $rolId, array $extra = []): array
    {
        $email = $usuario instanceof User ? $usuario->email : $usuario;

        return ['name' => 'Nombre', 'surname' => 'Apellido', 'email' => $email, 'role_id' => $rolId, 'state' => 1] + $extra;
    }

    public function test_no_puede_asignarse_a_si_mismo_un_rol_con_mas_permisos(): void
    {
        $respuesta = $this->usuarios($this->gerente)
            ->update(new Request($this->formUsuario($this->gerente, $this->rolAdmin->id)), (string) $this->gerente->id);

        $this->assertSame(403, $respuesta->getStatusCode());
        $this->assertStringContainsString(self::SOLO_ADMIN, $respuesta->getData(true)['message']);
        $this->assertFalse($this->gerente->fresh()->hasRole($this->rolAdmin->name));
    }

    public function test_no_puede_crear_un_usuario_con_un_rol_con_mas_permisos(): void
    {
        $respuesta = $this->usuarios($this->gerente)
            ->store(new Request($this->formUsuario('complice@test.pe', $this->rolAdmin->id, ['password' => 'secreto123'])));

        $this->assertSame(403, $respuesta->getStatusCode());
        $this->assertNull(User::where('email', 'complice@test.pe')->first());

        // Un rol con permisos que sí tiene, sí.
        $ok = $this->usuarios($this->gerente)
            ->store(new Request($this->formUsuario('cajero2@test.pe', $this->rolCajero->id, ['password' => 'secreto123'])));
        $this->assertSame(200, $ok->getData(true)['code']);
    }

    public function test_no_puede_cambiar_la_contrasena_ni_eliminar_a_un_usuario_con_mas_permisos(): void
    {
        $controlador = $this->usuarios($this->gerente);
        $id = (string) $this->admin->id;

        $editar = $controlador->update(new Request($this->formUsuario($this->admin, $this->rolAdmin->id, ['password' => 'tomada123'])), $id);
        $this->assertSame(403, $editar->getStatusCode());
        $this->assertFalse(Hash::check('tomada123', $this->admin->fresh()->password));

        $this->assertSame(403, $controlador->destroy($id)->getStatusCode());
        $this->assertNotNull($this->admin->fresh());

        // Permisos directos: sigue valiendo solo el delta (UserControllerFase2dTest) —
        // no puede AGREGARLE algo que no tiene, aunque sí gestionar lo que ya tenía.
        $this->assertSame(422, $controlador->permisosDirectos(new Request(['permissions' => [self::SOLO_ADMIN]]), $id)->getStatusCode());

        // A un usuario con menos permisos sí lo gestiona.
        $cajero = $controlador->update(new Request($this->formUsuario($this->cajero, $this->rolCajero->id)), (string) $this->cajero->id);
        $this->assertSame(200, $cajero->getData(true)['code']);
    }

    public function test_no_puede_crear_un_rol_con_permisos_que_no_tiene(): void
    {
        $respuesta = $this->roles($this->gerente)
            ->store(new Request(['name' => 'Rol inflado', 'permissions' => ['list_user', self::SOLO_ADMIN]]));

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertNull(Role::where('name', 'Rol inflado')->first());

        $ok = $this->roles($this->gerente)->store(new Request(['name' => 'Rol acotado', 'permissions' => ['list_user']]));
        $this->assertSame(200, $ok->getData(true)['code']);
    }

    public function test_no_puede_agregar_a_un_rol_permisos_que_no_tiene_pero_si_quitarlos(): void
    {
        $rolPropio = $this->gerente->roles()->first();

        $agregar = $this->roles($this->gerente)->update(
            new Request(['name' => $rolPropio->name, 'permissions' => [...self::GERENTE, self::SOLO_ADMIN]]),
            (string) $rolPropio->id,
        );
        $this->assertSame(422, $agregar->getStatusCode());
        $this->assertFalse($rolPropio->fresh()->hasPermissionTo(self::SOLO_ADMIN));

        // Quitar un permiso que el actor no tiene no es escalar.
        $rolAuditor = $this->rol('Auditor de prueba', ['list_user', self::SOLO_ADMIN]);
        $quitar = $this->roles($this->gerente)->update(
            new Request(['name' => $rolAuditor->name, 'permissions' => ['list_user']]),
            (string) $rolAuditor->id,
        );
        $this->assertSame(200, $quitar->getData(true)['code']);
        $this->assertFalse($rolAuditor->fresh()->hasPermissionTo(self::SOLO_ADMIN));
    }

    public function test_quien_tiene_todos_los_permisos_si_puede(): void
    {
        $respuesta = $this->usuarios($this->admin)
            ->update(new Request($this->formUsuario($this->gerente, $this->rolAdmin->id)), (string) $this->gerente->id);

        $this->assertSame(200, $respuesta->getData(true)['code']);
        $this->assertTrue($this->gerente->fresh()->hasRole($this->rolAdmin->name));
    }
}
