<?php

namespace App\Services\Roles;

use App\Models\User;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * T-081 — cuenta de soporte de UmboSystem en cada tenant (cada tenant tiene su propia base, así
 * que el correo no choca). Es la única Super-Admin que crea el sistema. La contraseña es aleatoria
 * y no se muestra ni se guarda: para entrar, Umbo la restablece desde el Panel Superadmin.
 */
class CuentaSoporte
{
    public const EMAIL = 'soporte@umbosystem.com';
    public const NOMBRE = 'Soporte UmboSystem';
    public const ROL = 'Super-Admin';

    /** Crea la cuenta si falta (si ya existe, aunque esté eliminada, no se toca). True si la creó. */
    public static function asegurar(): bool
    {
        if (User::withTrashed()->where('email', self::EMAIL)->exists()) {
            return false;
        }

        $rol = Role::firstOrCreate(['name' => self::ROL, 'guard_name' => 'api']);

        // role_id explícito: la columna tiene default(1) y la pantalla Usuarios lee el rol de ahí.
        $soporte = User::create([
            'name' => self::NOMBRE,
            'email' => self::EMAIL,
            'password' => Str::password(40),
            'role_id' => $rol->id,
        ]);
        $soporte->assignRole($rol);

        return true;
    }
}
