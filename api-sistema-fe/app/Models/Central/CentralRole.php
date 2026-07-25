<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CentralRole extends Model
{
    // 'central' consolidada — ver comentario en CentralUser.
    protected $connection = 'central';

    protected $fillable = [
        'name',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            CentralUser::class,
            'central_role_user',
            'central_role_id',
            'central_user_id'
        );
    }
}
