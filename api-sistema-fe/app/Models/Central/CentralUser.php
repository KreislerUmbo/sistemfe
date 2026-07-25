<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

// Bug real encontrado en B.2.5 (plan-panel-superadmin.md) al intentar loguear vía HTTP
// real por primera vez desde que existe el panel: extendía Model en vez de Authenticatable
// (a diferencia de App\Models\User, que sí lo hace) — JWTGuard::hasValidCredentials()
// exige un Illuminate\Contracts\Auth\Authenticatable real, así que auth:central login()
// tiraba 500 para CUALQUIER credencial, válida o no. Nunca se notó porque B.2.1-B.2.4 se
// verificaron con tinker (manipulación directa de modelos), no con el login real.
class CentralUser extends Authenticatable implements JWTSubject
{
    use SoftDeletes;

    // 'central' consolidada (plan-panel-superadmin.md, "B.0.5") — ya no hay una
    // clave separada 'db_tenant_central'; 'central' apunta ahí directo.
    protected $connection = 'central';

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            CentralRole::class,
            'central_role_user',
            'central_user_id',
            'central_role_id'
        );
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Claim 'guard' distingue este token de uno emitido por el guard 'api'/'client'
     * de tenant — el secreto JWT es compartido entre guards, así que sin este claim
     * un id coincidente entre CentralUser y User podría autenticar por accidente en
     * el guard equivocado. Verificado por EnsureTokenIsCentralGuard, mismo patrón
     * que tenant_id en App\Models\User::getJWTCustomClaims().
     */
    public function getJWTCustomClaims()
    {
        return [
            'guard' => 'central',
        ];
    }
}
