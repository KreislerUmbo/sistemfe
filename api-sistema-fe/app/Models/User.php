<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

     use HasRoles;
    use SoftDeletes;
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'surname',
        'role_id',
        // Módulo de series de comprobantes — sucursal fija del usuario,
        // determina qué series ve en register.vue/edit.vue salvo que tenga
        // el permiso can_switch_branch.
        'branch_id',
        'phone',
        'avatar',
        'type_document',
        'n_document',
        'gender',
        'address',
        'state',
        'formato_impresion_default', // 'a4' o 'ticket80mm' — usado por defecto al imprimir comprobantes
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }



    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims()
    {
        return [
            'tenant_id' => tenant('id'),
        ];
    }


    //esta funcion es para la relacion con roles
    public function role(){
        return $this->belongsTo(Role::class,"role_id");//relacion de muchos a uno osea muchos usuarios tienen un rol
    }
}
