<?php

namespace App\Models\Client;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use SoftDeletes;
    //use HasFactory; //esto sirve para usar el factory de la base de datos osea para crear registros en la base de datos
    protected $fillable = [
        "name",
        "surname",
        "full_name",
        "name_comerc",
        "email",
        "phone",
        "type_client", //1 es cliente normal y 2 es empresa 
        "type_document",
        "n_document",
        "gender",
        "birth_date", //fecha de cumpleaños
        "user_id",
        "address",
        "ubigeo_distrito",
        "ubigeo_provincia",
        "ubigeo_region",
        "distrito",
        "provincia",
        "region",
        "state", //1=activo 0=inactivo
    ];

    public function setCreatedAtAttribute($value)
    {
        date_default_timezone_set('America/Lima');
        $this->attributes["created_at"] = Carbon::now();
    }

    public function setUpdatedAtAttribute($value)
    {
        date_default_timezone_set("America/Lima");
        $this->attributes["updated_at"] = Carbon::now();
    }


    public function user()
    {
        return $this->belongsTo(User::class,"user_id");
    }
}
