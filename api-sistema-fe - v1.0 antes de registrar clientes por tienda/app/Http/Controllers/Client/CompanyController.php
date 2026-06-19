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
//

    public function store(Request $request)
    {
        $company = Company::first();
        if ($company) {
            $company->update($request->all());
        } else {
           $company = Company::create($request->all());
        }

        return response()->json([
            "code" => 200,
            "message" => "Se actualizo la informacion de empresa con éxito",
            "company" => $company,
        ]);
    }
}
