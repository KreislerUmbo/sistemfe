<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\Client\CompanyResource;
use App\Models\Company;
use Illuminate\Http\Request;

class CompanyController extends Controller
{

    public function index()
    {
        $company = Company::first();
        return response()->json([
            "company" => $company ? CompanyResource::make($company) : null,
        ]);
    }

    // Endpoint público (sin auth:api, mismo criterio que /auth/login — ver
    // EnsureTokenBelongsToTenant) para el logo del tenant en pantallas sin
    // sesión (login) o compartidas por toda la app (sidebar). Expone solo
    // lo mínimo (nombre comercial + URLs de logo), nunca RUC/dirección/
    // teléfono como sí hace CompanyResource para el panel autenticado.
    public function branding()
    {
        $company = Company::first();
        return response()->json([
            "razon_social_comercial" => $company?->razon_social_comercial,
            "logo_vertical" => $company?->logo_vertical ? \App\Services\StorageUrl::resolve($company->logo_vertical) : null,
            "logo_horizontal" => $company?->logo_horizontal ? \App\Services\StorageUrl::resolve($company->logo_horizontal) : null,
        ]);
    }
//

    public function store(Request $request)
    {
        $company = Company::first();

        $datos = $request->except(['logo_vertical', 'logo_horizontal']);

        if ($request->hasFile('logo_vertical')) {
            if ($company?->logo_vertical && \Illuminate\Support\Facades\Storage::disk('public')->exists($company->logo_vertical)) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($company->logo_vertical);
            }
            $datos['logo_vertical'] = \Illuminate\Support\Facades\Storage::disk('public')->putFile('company', $request->file('logo_vertical'));
        }

        if ($request->hasFile('logo_horizontal')) {
            if ($company?->logo_horizontal && \Illuminate\Support\Facades\Storage::disk('public')->exists($company->logo_horizontal)) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($company->logo_horizontal);
            }
            $datos['logo_horizontal'] = \Illuminate\Support\Facades\Storage::disk('public')->putFile('company', $request->file('logo_horizontal'));
        }

        if ($company) {
            $company->update($datos);
        } else {
            $company = Company::create($datos);
        }

        return response()->json([
            "code" => 200,
            "message" => "Se actualizo la informacion de empresa con éxito",
            "company" => CompanyResource::make($company->fresh()),
        ]);
    }
}
