<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $fillable = [
        "razon_social",
        "razon_social_comercial",
        "phone",
        "email",
        "n_document",
        "birth_date",
        "address",
        "urbanizacion",
        "cod_local",
        "ubigeo_distrito",
        "ubigeo_provincia",
        "ubigeo_region",
        "distrito",
        "provincia",
        "region",
        // Módulo Caja — Fase 1 (plan-modulo-caja.md §10). Nunca se habían
        // agregado a $fillable — Company::update() los descartaba en
        // silencio por protección de asignación masiva (bug real, hallado
        // en Fase 4 al intentar configurar require_expense_approval por
        // primera vez desde el modelo; hasta ahora estos campos solo se
        // habían LEÍDO, nunca escrito vía Eloquent).
        "blind_close_default",
        "allow_multiple_registers_per_branch",
        "difference_tolerance",
        "require_expense_concept",
        "require_expense_approval",
        "max_expense_without_approval",
    ];


}
