<?php

namespace App\Models\CommercialQuote;

use App\Models\Advance\Advance;
use Illuminate\Database\Eloquent\Model;

// Etiqueta un Advance (core) contra una cotización comercial específica,
// antes de que exista el Sale final — mismo patrón que ReservaAnticipo
// (App\Models\AgenciaViajes\ReservaAnticipo). commercial_quote_id lleva
// belongsTo real; advance_id apunta al core (tabla 'advances').
class CommercialQuoteAnticipo extends Model
{
    protected $table = 'commercial_quote_anticipos';

    protected $fillable = [
        'commercial_quote_id',
        'advance_id',
        'monto_asignado',
        'fecha_asignacion',
    ];

    protected $casts = [
        'monto_asignado' => 'decimal:2',
        'fecha_asignacion' => 'date',
    ];

    public function commercialQuote()
    {
        return $this->belongsTo(CommercialQuote::class, 'commercial_quote_id');
    }

    public function advance()
    {
        return $this->belongsTo(Advance::class, 'advance_id');
    }
}
