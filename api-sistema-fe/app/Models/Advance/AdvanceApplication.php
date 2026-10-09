<?php

namespace App\Models\Advance;

use App\Models\Sale\Sale;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AdvanceApplication extends Model
{
    use SoftDeletes;
    use HasFactory;

    protected $table = "advance_applications";

    protected $fillable = [
        "advance_id",
        "sale_id",         // venta final donde se aplicó el adelanto
        "amount_applied",  // alimenta PrepaidPayment/PaidAmount en el XML de esa venta
    ];

    public function advance()
    {
        return $this->belongsTo(Advance::class, "advance_id");
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class, "sale_id");
    }
}
