<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Preferencias de avisos de un usuario (Fase 5). Sin fila = valores por defecto: recibe ambos
 * canales, pero sin correo verificado no se le envía correo.
 */
class CreditoAvisoPreferencia extends Model
{
    protected $table = 'credito_aviso_preferencias';

    protected $fillable = ['user_id', 'correo', 'correo_verificado_en', 'recibir_correo', 'recibir_push'];

    protected $attributes = ['recibir_correo' => true, 'recibir_push' => true];

    protected function casts(): array
    {
        return [
            'correo_verificado_en' => 'datetime',
            'recibir_correo' => 'boolean',
            'recibir_push' => 'boolean',
        ];
    }

    public static function de(User $usuario): self
    {
        return static::firstOrNew(['user_id' => $usuario->id]);
    }

    /** Correo al que se puede enviar: verificado y con el canal encendido. */
    public function correoUtilizable(): ?string
    {
        return $this->recibir_correo && $this->correo && $this->correo_verificado_en ? $this->correo : null;
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
