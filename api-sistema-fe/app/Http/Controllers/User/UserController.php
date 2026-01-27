<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\User\UserCollection;
use App\Http\Resources\User\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {

        $search = $request->get("search");

        $users = User::whereRaw("(COALESCE(users.name,'') || ' ' || COALESCE(users.surname,'') || ' ' || COALESCE(users.n_document,'')) ILIKE ?", ["%{$search}%"])
            ->orderBy("id", "desc")
            ->paginate(10);
            
        $roles = Role::all();
        
        return response()->json([
            "total" => $users->total(),
            "paginate" => 10,
            "users" => UserCollection::make($users),
            "roles" => $roles->map(function ($role) {
                return [
                    "id" => $role->id,
                    "name" => $role->name,
                ];
            }),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $is_user_exists = User::where("email", $request->email)->first();
        if ($is_user_exists) {
            return response()->json([
                "code" => 405,
                "message" => "La cuenta de usuario ya existe",
            ]);
        }

        if ($request->hasFile("imagen")) {
            $path = Storage::putFile("users", $request->file("imagen"));
             $request->merge(["avatar" => $path]);
        }

        if ($request->password) {
            $request->merge(["password" => bcrypt($request->password)]);
        }


        $user = User::create($request->all());

        $role = Role::find($request->role_id);
        $user->assignRole($role);

        return response()->json([
            "code" => 200,
            "message" => "Usuario creado con exito",
            "user" => UserResource::make($user),
        ]);
    }
    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $is_user_exists = User::where("id", "<>", $id)->where("email", $request->email)->first();

        if ($is_user_exists) {
            return response()->json([
                "code" => 405,
                "message" => "La cuenta de usuario ya existe",
            ]);
        }

        $user = User::findOrFail($id);
        if ($request->hasFile("imagen")) {
            // Si ya existe una imagen de avatar, eliminarla del almacenamiento
            if ($user->avatar && Storage::exists($user->avatar)) {
                Storage::delete($user->avatar);
            }
            // Subir la nueva imagen 
            $path = Storage::putFile("users", $request->file("imagen"));
            //y agregar la ruta de la nueva imagen al request
            $request->merge(["avatar" => $path]);
        }

        if (!$request->password) {
            $request->merge(["password" => bcrypt($request->password)]);
        }

        if ($user->role_id != $request->role_id) {
            $role_current = Role::find($user->role_id);
            $user->removeRole($role_current);

            $role_new = Role::find($request->role_id);
            $user->assignRole($role_new);
        }

        $user->update($request->all());

        return response()->json([
            "code" => 200,
            "message" => "Usuario creado con exito",
            "user" => UserResource::make($user),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $user = User::findOrFail($id);
        if ($user->avatar && Storage::exists($user->avatar)) {
            Storage::delete($user->avatar);
        }
        $user->delete();
        return response()->json([
            "code" => 200,
            "message" => "Usuario eliminado con exito"
        ]);
    }
}
