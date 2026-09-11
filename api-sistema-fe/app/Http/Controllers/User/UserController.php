<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\User\UserCollection;
use App\Http\Resources\User\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {

        $search = $request->get("search");

        // ->with('permissions') — Fase 2d agregó direct_permissions a
        // UserResource (getDirectPermissions(), que lee $this->permissions);
        // sin eager load acá, cada fila del listado dispara su propia query
        // (N+1, hasta 10 extra por página).
        $users = User::with('permissions')
            ->whereRaw("(COALESCE(users.name,'') || ' ' || COALESCE(users.surname,'') || ' ' || COALESCE(users.n_document,'')) ILIKE ?", ["%{$search}%"])
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
            // Fase 2d (plan-modulo-menus-y-roles.md §5) — catálogo REAL de
            // permisos del tenant, mismo criterio que
            // RoleController::index() (Fase 2c): el checklist de "Permisos
            // directos" de un usuario cruza esto contra PERMISOS (catálogo
            // curado del frontend) para que ningún permiso real quede
            // invisible/inasignable.
            "permisos_disponibles" => Permission::where("guard_name", "api")
                ->orderBy("name")
                ->pluck("name"),
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
            $path = Storage::disk('public')->putFile("users", $request->file("imagen"));
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
            if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }
            // Subir la nueva imagen 
            $path = Storage::disk('public')->putFile("users", $request->file("imagen"));
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
     * Fase 2d (plan-modulo-menus-y-roles.md §5) — permisos asignados
     * DIRECTO al usuario, además de (o por encima de) los que ya le da su
     * rol. Spatie ya soporta esto de fábrica (model_has_permissions,
     * independiente de role_has_permissions) y el proyecto ya lo usa hoy
     * a mano vía tinker (cash.close_others_session/cash.approve_expenses
     * en Caja) — esto lo expone desde la UI. syncPermissions() en un User
     * (no un Role) solo toca sus permisos directos, nunca los del rol.
     *
     * Guard anti-escalación de privilegios (hallazgo real de revisión
     * posterior): este endpoint solo exige 'edit_user' — un permiso mucho
     * más común que 'edit_role'/'delete_role'. Sin este guard, cualquier
     * rol con edit_user (aunque no administre roles) podría otorgarse a
     * sí mismo o a otro usuario CUALQUIER permiso existente, incluidos
     * delete_role/roles.administrar. Solo se valida el DELTA (lo que se
     * agrega respecto a lo que el usuario ya tenía directo) — remover o
     * conservar un permiso que el actor no posee no es una escalación.
     * $actor->can() (no getDirectPermissions()) porque Super-Admin no
     * tiene permisos explícitos asignados — bypasea vía Gate::before(),
     * y can() sí pasa por ese Gate (confirmado en tinker).
     */
    public function permisosDirectos(Request $request, string $id)
    {
        $user = User::findOrFail($id);
        $solicitados = $request->permissions ?? [];

        $actor = auth('api')->user();
        $actuales = $user->getDirectPermissions()->pluck('name')->all();
        $nuevos = array_diff($solicitados, $actuales);
        $noAutorizados = array_filter($nuevos, fn ($permiso) => ! $actor?->can($permiso));

        if (! empty($noAutorizados)) {
            return response()->json([
                "code" => 422,
                "message" => "No podés otorgar permisos que vos mismo no tenés: " . implode(', ', $noAutorizados),
            ], 422);
        }

        $user->syncPermissions($solicitados);

        return response()->json([
            "code" => 200,
            "message" => "Permisos directos actualizados con éxito",
            "direct_permissions" => $user->getDirectPermissions()->pluck("name"),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $user = User::findOrFail($id);
        if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
            Storage::disk('public')->delete($user->avatar);
        }
        $user->delete();
        return response()->json([
            "code" => 200,
            "message" => "Usuario eliminado con exito"
        ]);
    }
}
