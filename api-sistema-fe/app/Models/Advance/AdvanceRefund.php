<?php

namespace App\Models\Advance;

use App\Models\Sale\Note;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AdvanceRefund extends Model
{
    use SoftDeletes;
    use HasFactory;

    protected $table = "advance_refunds";

    protected $fillable = [
        "advance_id",
        "note_id",         // Nota de Crédito emitida (App\Models\Sale\Note)
        "amount_refunded",
        "reason",
    ];

    public function advance()
    {
        return $this->belongsTo(Advance::class, "advance_id");
    }

    public function note()
    {
        return $this->belongsTo(Note::class, "note_id");
    }
}
