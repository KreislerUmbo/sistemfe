<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Roles\CuentaSoporte;
use App\Services\Roles\RolPropietario;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * T-081 — pasa al dueño de un tenant ya existente de Super-Admin a Propietario y crea la cuenta de
 * soporte si falta. No adivina quién es el dueño: lo dice quien lo corre. Idempotente; con
 * --simular solo dice qué haría.
 */
class AsignarRolPropietario extends Command
{
    protected $signature = 'roles:asignar-propietario
        {tenant : id del tenant}
        {email : correo del dueño}
        {--simular : no escribe nada, solo dice qué haría}';

    protected $description = 'Hace Propietario al dueño de un tenant (le quita Super-Admin) y crea la cuenta de soporte si falta.';

    public function handle(): int
    {
        $tenant = Tenant::find($this->argument('tenant'));
        if (! $tenant) {
            $this->error("No existe el tenant «{$this->argument('tenant')}».");

            return self::FAILURE;
        }

        return $tenant->run(fn () => $this->procesar((string) $this->argument('email'), (bool) $this->option('simular')));
    }

    private function procesar(string $email, bool $simular): int
    {
        $usuario = User::withTrashed()->where('email', $email)->first();
        if (! $usuario || $usuario->trashed()) {
            $this->error($usuario ? "El usuario {$email} está eliminado." : "No existe el usuario {$email} en este tenant.");

            return self::FAILURE;
        }
        if ($email === CuentaSoporte::EMAIL) {
            $this->error('Esa es la cuenta de soporte: no puede ser Propietario.');

            return self::FAILURE;
        }

        // Rol que muestra la pantalla Usuarios (role_id) y Super-Admin: se reemplazan por
        // Propietario, para que el usuario quede con un solo rol, como lo maneja esa pantalla.
        $aQuitar = $usuario->roles
            ->filter(fn (Role $r): bool => $r->name !== RolPropietario::NOMBRE
                && ($r->name === CuentaSoporte::ROL || (int) $r->id === (int) $usuario->role_id))
            ->pluck('name')
            ->values();
        $darPropietario = ! RolPropietario::es($usuario);
        $crearSoporte = ! User::withTrashed()->where('email', CuentaSoporte::EMAIL)->exists();

        $acciones = array_filter([
            $aQuitar->isNotEmpty() ? "quitar a {$email} el rol " . $aQuitar->implode(', ') : null,
            $darPropietario ? "dar a {$email} el rol Propietario" : null,
            $crearSoporte ? 'crear la cuenta de soporte ' . CuentaSoporte::EMAIL . ' (Super-Admin)' : null,
        ]);

        if ($acciones === []) {
            $this->info("Nada que hacer: {$email} ya es Propietario y la cuenta de soporte existe.");

            return self::SUCCESS;
        }

        $this->line(($simular ? '[simulación] Haría:' : 'Hecho:'));
        foreach ($acciones as $accion) {
            $this->line("  - {$accion}");
        }
        if ($simular) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($usuario, $aQuitar) {
            RolPropietario::sincronizar();
            $propietario = Role::where('name', RolPropietario::NOMBRE)->where('guard_name', 'api')->first();

            foreach ($aQuitar as $rol) {
                $usuario->removeRole($rol);
            }
            $usuario->assignRole($propietario);
            $usuario->forceFill(['role_id' => $propietario->id])->save();

            CuentaSoporte::asegurar();
        });

        return self::SUCCESS;
    }
}
