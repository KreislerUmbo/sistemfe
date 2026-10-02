<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\User\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Creditos\CreditosTestCase;

/**
 * Editar un usuario sin escribir contraseña no debe cambiarla. La condición estaba invertida
 * desde la primera versión: la reemplazaba por bcrypt('') y el usuario quedaba sin acceso.
 */
final class UsuarioEdicionPasswordTest extends CreditosTestCase
{
    public function test_editar_sin_contrasena_conserva_la_actual(): void
    {
        $usuario = $this->usuario(['creditos.ver']);
        $usuario->update(['password' => bcrypt('ClaveOriginal1')]);

        app(UserController::class)->update($this->edicion($usuario, ['password' => '', 'phone' => '999888777']), (string) $usuario->id);

        $fresco = $usuario->fresh();
        $this->assertSame('999888777', $fresco->phone);
        $this->assertTrue(Hash::check('ClaveOriginal1', $fresco->password));
        $this->assertFalse(Hash::check('', $fresco->password));
    }

    public function test_editar_con_contrasena_nueva_la_cambia(): void
    {
        $usuario = $this->usuario(['creditos.ver']);
        $usuario->update(['password' => bcrypt('ClaveOriginal1')]);

        app(UserController::class)->update($this->edicion($usuario, ['password' => 'ClaveNueva22']), (string) $usuario->id);

        $this->assertTrue(Hash::check('ClaveNueva22', $usuario->fresh()->password));
    }

    /** Lo que envía users/index.vue al editar (FormData). */
    private function edicion(\App\Models\User $usuario, array $cambios): Request
    {
        return new Request([
            'name' => $usuario->name, 'surname' => 'Prueba', 'email' => $usuario->email, 'phone' => '900000000',
            'role_id' => (string) $usuario->role_id, 'type_document' => 'DNI', 'n_document' => '12345678',
            'gender' => 'M', 'state' => '1', 'formato_impresion_default' => 'a4', ...$cambios,
        ]);
    }
}
