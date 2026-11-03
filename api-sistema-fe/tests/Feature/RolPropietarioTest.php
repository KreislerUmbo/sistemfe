<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Role\RoleController;
use App\Http\Controllers\User\UserController;
use App\Models\User;
use App\Services\Roles\RolPropietario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Creditos\CreditosTestCase;

/**
 * T-081 — rol Propietario: sincronización de permisos (CA2), protección del Propietario (CA3), el
 * último Propietario activo (CA4) y el rol no editable desde Roles (CA5). Sobre la base de pruebas
 * en una transacción, como UsuariosSuperAdminTest. Alta de tenant, comandos y panel:
 * RolPropietarioTenantTest.
 */
final class RolPropietarioTest extends CreditosTestCase
{
    private const GESTION_USUARIOS = ['register_user', 'edit_user', 'delete_user', 'list_user'];

    private Role $superRol;
    private Role $propietarioRol;
    private Role $rolNormal;
    private User $super;
    private User $duenio;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::GESTION_USUARIOS as $permiso) {
            Permission::firstOrCreate(['guard_name' => 'api', 'name' => $permiso]);
        }
        RolPropietario::sincronizar();
        $this->superRol = Role::firstOrCreate(['name' => 'Super-Admin', 'guard_name' => 'api']);
        $this->propietarioRol = Role::where('name', RolPropietario::NOMBRE)->firstOrFail();
        $this->rolNormal = Role::firstOrCreate(['name' => 'Administrador de prueba', 'guard_name' => 'api']);
        $this->super = $this->conRol(User::factory()->create(), $this->superRol);
        $this->duenio = $this->conRol(User::factory()->create(), $this->propietarioRol);
        // Administrador con TODOS los permisos (por rol), pero sin el rol Propietario.
        $this->admin = $this->usuario(Permission::where('guard_name', 'api')->pluck('name')->all());
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
    private function usuarioPedido(User|string $usuario, int $rolId, int $state = 1): array
    {
        $email = $usuario instanceof User ? $usuario->email : $usuario;

        return ['name' => 'Nombre', 'surname' => 'Apellido', 'email' => $email, 'role_id' => $rolId, 'state' => $state];
    }

    // CA2
    public function test_sincronizar_da_los_permisos_nuevos_solo_al_propietario_y_es_idempotente(): void
    {
        $this->rolNormal->givePermissionTo('list_user');
        $antesNormal = $this->rolNormal->permissions()->pluck('name')->all();

        Permission::create(['guard_name' => 'api', 'name' => 't081.funcion_nueva']);
        $cambios = RolPropietario::sincronizar();

        $this->assertSame(['t081.funcion_nueva'], $cambios['agregados']);
        $this->assertTrue($this->propietarioRol->fresh()->hasPermissionTo('t081.funcion_nueva'));
        $this->assertSame(Permission::where('guard_name', 'api')->count(), $this->propietarioRol->fresh()->permissions()->count());
        $this->assertSame($antesNormal, $this->rolNormal->fresh()->permissions()->pluck('name')->all(), 'Los demás roles no cambian.');
        $this->assertSame(['agregados' => [], 'quitados' => []], RolPropietario::sincronizar(), 'Dos veces no cambia nada.');
    }

    // CA2
    public function test_sincronizar_le_quita_los_permisos_de_solo_soporte(): void
    {
        Permission::create(['guard_name' => 'api', 'name' => 't081.solo_soporte']);
        RolPropietario::sincronizar();
        $this->assertTrue($this->propietarioRol->fresh()->hasPermissionTo('t081.solo_soporte'));

        $cambios = RolPropietario::sincronizar(['t081.solo_soporte']);

        $this->assertSame(['t081.solo_soporte'], $cambios['quitados']);
        $this->assertFalse($this->propietarioRol->fresh()->hasPermissionTo('t081.solo_soporte'));
    }

    // CA3
    public function test_un_administrador_no_toca_al_propietario_ni_asigna_ese_rol(): void
    {
        $controlador = $this->como($this->admin);
        $id = (string) $this->duenio->id;

        $this->assertSame(403, $controlador->update(new Request($this->usuarioPedido($this->duenio, $this->propietarioRol->id)), $id)->getStatusCode(), 'editar');
        $this->assertSame(403, $controlador->update(new Request($this->usuarioPedido($this->duenio, $this->propietarioRol->id, User::STATE_INACTIVO)), $id)->getStatusCode(), 'desactivar');
        $this->assertSame(403, $controlador->destroy($id)->getStatusCode(), 'eliminar');
        $this->assertSame(403, $controlador->permisosDirectos(new Request(['permissions' => []]), $id)->getStatusCode(), 'permisos directos');
        $this->assertSame(403, $controlador->store(new Request($this->usuarioPedido('nuevo-prop@test.pe', $this->propietarioRol->id) + ['password' => 'secreto123']))->getStatusCode(), 'asignar al crear');
        $this->assertSame(403, $controlador->update(new Request($this->usuarioPedido($this->admin, $this->propietarioRol->id)), (string) $this->admin->id)->getStatusCode(), 'asignárselo a sí mismo');

        $this->assertNull(User::where('email', 'nuevo-prop@test.pe')->first());
        $this->assertFalse($this->admin->fresh()->hasRole(RolPropietario::NOMBRE));
        $this->assertNotNull($this->duenio->fresh());
        $this->assertNotSame(User::STATE_INACTIVO, (int) $this->duenio->fresh()->state);

        $catalogo = collect($controlador->index(new Request())->getData(true)['roles'])->pluck('name');
        $this->assertNotContains(RolPropietario::NOMBRE, $catalogo);
    }

    // CA3
    public function test_un_propietario_si_nombra_a_otro_propietario(): void
    {
        $controlador = $this->como($this->duenio);

        $this->assertContains(RolPropietario::NOMBRE, collect($controlador->index(new Request())->getData(true)['roles'])->pluck('name'));

        $respuesta = $controlador->update(new Request($this->usuarioPedido($this->admin, $this->propietarioRol->id)), (string) $this->admin->id);

        $this->assertSame(200, $respuesta->getData(true)['code']);
        $this->assertTrue($this->admin->fresh()->hasRole(RolPropietario::NOMBRE));

        // Y un Propietario no toca al Super-Admin (soporte).
        $this->assertSame(403, $controlador->destroy((string) $this->super->id)->getStatusCode());
    }

    // CA4
    public function test_al_ultimo_propietario_activo_no_se_le_quita_ni_siendo_super_admin_ni_el_mismo(): void
    {
        foreach ([$this->super, $this->duenio] as $actor) {
            $controlador = $this->como($actor);
            $id = (string) $this->duenio->id;

            $cambioRol = $controlador->update(new Request($this->usuarioPedido($this->duenio, $this->rolNormal->id)), $id);
            $inactivo = $controlador->update(new Request($this->usuarioPedido($this->duenio, $this->propietarioRol->id, User::STATE_INACTIVO)), $id);
            $eliminar = $controlador->destroy($id);

            foreach ([$cambioRol, $inactivo, $eliminar] as $respuesta) {
                $this->assertSame(422, $respuesta->getStatusCode());
                $this->assertSame('Es el único propietario: nombra a otro antes.', $respuesta->getData(true)['message']);
            }
        }

        $duenio = $this->duenio->fresh();
        $this->assertNotNull($duenio);
        $this->assertTrue($duenio->hasRole(RolPropietario::NOMBRE));
        $this->assertSame((int) $this->propietarioRol->id, (int) $duenio->role_id);
        $this->assertNotSame(User::STATE_INACTIVO, (int) $duenio->state);

        // Editar sus datos sin quitarle el rol sí se puede.
        $this->assertSame(200, $this->como($this->duenio)->update(new Request($this->usuarioPedido($this->duenio, $this->propietarioRol->id)), (string) $this->duenio->id)->getData(true)['code']);
    }

    // CA4
    public function test_con_dos_propietarios_se_puede_quitar_uno(): void
    {
        $socio = $this->conRol(User::factory()->create(), $this->propietarioRol);
        $controlador = $this->como($this->duenio);

        $this->assertSame(200, $controlador->update(new Request($this->usuarioPedido($socio, $this->rolNormal->id)), (string) $socio->id)->getData(true)['code']);
        $this->assertFalse($socio->fresh()->hasRole(RolPropietario::NOMBRE));

        // Vuelve a ser Propietario y se elimina: también se puede, queda el dueño.
        $this->conRol($socio, $this->propietarioRol);
        $this->assertSame(200, $controlador->destroy((string) $socio->id)->getData(true)['code']);
        $this->assertNull(User::find($socio->id));

        // Un Propietario inactivo no cuenta: el dueño sigue siendo el último activo.
        $inactivo = $this->conRol(User::factory()->create(['state' => User::STATE_INACTIVO]), $this->propietarioRol);
        $this->assertSame(422, $controlador->destroy((string) $this->duenio->id)->getStatusCode());
        $this->assertNotNull($inactivo->fresh());
    }

    // CA5
    public function test_el_rol_propietario_no_se_edita_ni_se_elimina_desde_roles(): void
    {
        Auth::guard('api')->setUser($this->super);
        $controlador = app(RoleController::class);
        $id = (string) $this->propietarioRol->id;

        $editar = $controlador->update(new Request(['name' => 'Dueño', 'permissions' => []]), $id);
        $eliminar = $controlador->destroy($id);

        $this->assertSame(422, $editar->getStatusCode());
        $this->assertSame(422, $eliminar->getStatusCode());
        $rol = Role::find($id);
        $this->assertSame(RolPropietario::NOMBRE, $rol?->name);
        $this->assertSame(Permission::where('guard_name', 'api')->count(), $rol->permissions()->count());
    }
}
