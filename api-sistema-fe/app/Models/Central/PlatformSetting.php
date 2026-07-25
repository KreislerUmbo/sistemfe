<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    // 'central' consolidada — ver comentario en CentralUser.
    protected $connection = 'central';

    protected $fillable = [
        'key',
        'value',
        'description',
    ];
}
