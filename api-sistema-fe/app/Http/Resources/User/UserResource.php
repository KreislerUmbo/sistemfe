<?php

namespace App\Http\Resources\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'surname' => $this->resource->surname,
            'full_name' => $this->resource->name . ' ' . $this->resource->surname,
            'email' => $this->resource->email,
            'role_id' => $this->resource->role_id,
            'branch_id' => $this->resource->branch_id,
            'state' => $this->resource->state,
            'role' => [
                'id' => $this->resource->role->id,
                'name' => $this->resource->role->name,
            ],
            'phone' => $this->resource->phone,
            'avatar' => $this->resource->avatar ? env("APP_URL") . "storage/" . $this->resource->avatar : null,
            'type_document' => $this->resource->type_document,
            'n_document' => $this->resource->n_document,
            'gender' => $this->resource->gender,
            'formato_impresion_default' => $this->resource->formato_impresion_default,
            'created_at' => $this->resource->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
