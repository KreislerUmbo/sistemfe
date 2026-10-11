<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un dispositivo con los avisos push activados (Web Push). */
class PushSuscripcion extends Model
{
    protected $table = 'push_suscripciones';

    protected $fillable = ['user_id', 'endpoint', 'endpoint_hash', 'clave_p256dh', 'clave_auth', 'codificacion', 'agente', 'ultimo_envio_en'];

    protected $hidden = ['clave_p256dh', 'clave_auth'];

    protected function casts(): array
    {
        return ['ultimo_envio_en' => 'datetime'];
    }

    public static function hash(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
