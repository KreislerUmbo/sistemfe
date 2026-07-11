<?php

namespace App\Models\Client;


use Illuminate\Foundation\Auth\User as Authenticatable;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class Client extends Authenticatable implements JWTSubject
{
    use  SoftDeletes;
    //use HasFactory; //esto sirve para usar el factory de la base de datos osea para crear registros en la base de datos
    protected $table = 'clients';
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
        "cod_tipo_doc_sunat",
        "es_amazonia",
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
        "password", //para los clientes que vienen del portal
        "email_verified_at",//para los clientes que vienen del portal
        "last_login_at",//para los clientes que vienen del portal
        "remember_token",//para los clientes que vienen del portal

        "regimen_tributario",
        "es_agente_retencion",

    ];
    protected $hidden = ['password', 'remember_token']; //ocultar la contraseña

      protected $casts = [
        'es_amazonia' => 'boolean', // garantiza true/false, nunca "0"/"1" como string
        'es_agente_retencion' => 'boolean',
    ]; 

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }
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
        return $this->belongsTo(User::class, "user_id");
    }
    public function setPasswordAttribute($value) //para encriptar la contraseña
    {
        $this->attributes['password'] = bcrypt($value);
    }
}
