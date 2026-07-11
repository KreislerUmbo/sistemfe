<?php

namespace App\Http\Resources\Client;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            "name" => $this->name,
            "surname" => $this->surname,
            "full_name" => $this->full_name ? $this->full_name : ($this->name . " " . $this->surname),
            "name_comerc" => $this->name_comerc ?? null,
            "email" => $this->email,
            "phone" => $this->phone,
            "type_client" => $this->type_client, //1 es cliente normal y 2 es empresa 
            "type_document" => $this->type_document,
            "n_document" => $this->n_document,
            "gender" => $this->gender,
            "birth_date"      => $this->birth_date ? Carbon::parse($this->resource->birth_date)->format("Y-m-d") : null,
            "user_id" => $this->user ? [
                "id" => $this->user->id,
                "full_name" => $this->user->name . " " . $this->user->surname,
            ] : null,
            "address" => $this->address,
            "ubigeo_distrito" => $this->ubigeo_distrito,
            "ubigeo_provincia" => $this->ubigeo_provincia,
            "ubigeo_region" => $this->ubigeo_region,
            "distrito" => $this->distrito,
            "provincia" => $this->provincia,
            "region" => $this->region,
            "state" => $this->state, //1=activo 0=inactivo 
            // ── Campos SUNAT (agregados en el módulo de Clientes) ────
            "es_amazonia"        => (bool) ($this->es_amazonia ?? false),
            "cod_tipo_doc_sunat" => $this->cod_tipo_doc_sunat ?? '0',
            "regimen_tributario" => $this->regimen_tributario ?? 'SIN REGIMEN',
            "es_agente_retencion" => (bool) $this->es_agente_retencion,
            'created_at' => $this->resource->created_at?->format("Y-m-d h:i A") ?? null,
        ];
    }
}
