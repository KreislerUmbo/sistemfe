<?php

namespace App\Http\Controllers\Role;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $search = $request->get("search");

        $roles = Role::where("name", "ilike", "%" . $search . "%")
            ->orderBy("id", "DESC")
            ->paginate(3);

        return response()->json([
            "total" => $roles->total(),
            "paginate" => 3,
            "roles" => $roles->map(function ($role) {
                return [
                    "id" => $role->id,
                    "name" => $role->name,
                    "created_at" => $role->created_at->format("Y-m-d h:i:A"),
                    "permissions" => $role->permissions->map(function ($permission) {
                        return [
                            "id" => $permission->id,
                            "name" => $permission->name,
                        ];
                    }),
                    "permissions_pluck" => $role->permissions->pluck("name"),
                ];
            }),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $is_exist_role = Role::where("name", $request->name)->first();

        if ($is_exist_role) {
            return response()->json([
                "code" => 405,
                "message" => "El rol ya existe"
            ]);
        }

        $role =  Role::create([
            "name" => $request->name,
            "guard_name" => "api"
        ]);

        $permissions = $request->permissions;

        foreach ($permissions as $permission) {
            $role->givePermissionTo($permission);
        }


        return response()->json([
            "code" => 200,
            "message" => "Rol creado con exito",
            "role" => [
                "id" => $role->id,
                "name" => $role->name,
                "created_at" => $role->created_at->format("Y-m-d h:i:A"),
                "permissions" => $role->permissions->map(function ($permission) {
                    return [
                        "id" => $permission->id,
                        "name" => $permission->name,
                    ];
                }),
                "permissions_pluck" => $role->permissions->pluck("name"),
            ],
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
        $role =  Role::findOrFail($id);

        // Fase 2a (plan-modulo-menus-y-roles.md §5) — Super-Admin es un rol
        // técnico protegido: no se puede renombrar ni reconfigurar sus
        // permisos vía este endpoint, sin excepción.
        if ($role->name === 'Super-Admin') {
            return response()->json([
                "code" => 422,
                "message" => "El rol Super-Admin no se puede editar.",
            ], 422);
        }

        $is_exist_role = Role::where("id", "<>", $id)->where("name", $request->name)->first();

        if ($is_exist_role) {
            return response()->json([
                "code" => 405,
                "message" => "El rol ya existe"
            ]);
        }

        $role->update([
            "name" => $request->name,
        ]);

        $permissions = $request->permissions;
        $role->syncPermissions($permissions);


        return response()->json([
            "code" => 200,
            "message" => "Rol actualizado con exito",
            "role" => [
                "id" => $role->id,
                "name" => $role->name,
                "created_at" => $role->created_at->format("Y-m-d h:i:A"),
                "permissions" => $role->permissions->map(function ($permission) {
                    return [
                        "id" => $permission->id,
                        "name" => $permission->name,
                    ];
                }),
                "permissions_pluck" => $role->permissions->pluck("name"),
            ],
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $role = Role::findOrFail($id);

        // Fase 2a (plan-modulo-menus-y-roles.md §5) — Super-Admin es un rol
        // técnico protegido: no se puede eliminar.
        if ($role->name === 'Super-Admin') {
            return response()->json([
                "code" => 422,
                "message" => "El rol Super-Admin no se puede eliminar.",
            ], 422);
        }

        // Fase 2a (§9.5, guard anti-lockout) — bloquear eliminar un rol con
        // usuarios activos asignados sin reasignación previa explícita: hoy
        // no hay una UI de reasignación masiva, así que el guard exige
        // reasignar (o dar de baja) a cada usuario antes de poder borrar el
        // rol, en vez de dejarlos huérfanos silenciosamente.
        $usuariosAsignados = $role->users()->count();
        if ($usuariosAsignados > 0) {
            return response()->json([
                "code" => 422,
                "message" => "No se puede eliminar el rol: tiene {$usuariosAsignados} usuario(s) asignado(s). Reasigná esos usuarios a otro rol antes de eliminarlo.",
            ], 422);
        }

        $role->delete();

        return response()->json([
            "code" => 200,
            "message" => "Rol eliminado con exito"
        ]);
    }
}
