<script setup lang="ts">
import httpClient from '@/helpers/http-client';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import { PERMISOS, type Role, type RolePermiso, type Roles, type RolesResponse } from '@/types/roles';
import type { AxiosResponse } from 'axios';
import { onMounted, ref, watch } from 'vue';

import Swal from "sweetalert2/dist/sweetalert2.js";
type TVueSwalInstance = typeof Swal & typeof Swal.fire;

// ``

const roles = ref<Role[]>([]);
const ModalRegisterRole = ref<Boolean>(false);
const themeColor = ref<string>('primary');
const name = ref<string | null>(null);

const permission_selected = ref<string[]>([]);
const role_selected = ref<Role | undefined>(undefined);
const search = ref<string | null>(null);


const currentPage = ref<number>(1);
const totalPages = ref<number>(1);
const perPageRows = ref<number>(3);

const list = async () => {
    try {
        const res: AxiosResponse<Roles> = await httpClient.get(
            `roles?page=${currentPage.value}&search=${search.value ?? ''}`);
        console.log(res);

        roles.value = res.data.roles;
        totalPages.value = res.data.total;
        perPageRows.value = res.data.paginate;

    } catch (error) {
        console.log(error);
    }
}

const reset = () => {
    search.value = null;
    list();
}

const addPermission = (permiso: RolePermiso) => {

    let INDEX = permission_selected.value.findIndex((prm) => prm == permiso.permiso);
    if (INDEX != -1) {
        permission_selected.value.splice(INDEX, 1);
    } else {
        permission_selected.value.push(permiso.permiso);
    }


    console.log(permission_selected.value);

}

const store = async () => {
    if (!name.value) {
        (Swal as TVueSwalInstance).fire(
            "Upps!",
            "El nombre del rol es obligatorio",
            "warning",
        );
        return;
    }
    if (permission_selected.value.length == 0) {
        (Swal as TVueSwalInstance).fire(
            "Upps!",
            "Debe seleccionar al menos un permiso",
            "warning",
        );
        return;
    }

    try {

        const res: AxiosResponse<RolesResponse> =
            !role_selected.value ?//si no hay rol seleccionado es un nuevo registro
                await httpClient.post(
                    "roles",
                    {
                        name: name.value,
                        permissions: permission_selected.value
                    })
                ://si hay rol seleccionado es una actualización
                await httpClient.put(
                    "roles/" + role_selected.value?.id,
                    {
                        name: name.value,
                        permissions: permission_selected.value
                    }
                );


        console.log(res);

        if (res.data.code == 405) {
            (Swal as TVueSwalInstance).fire(
                "Upps!",
                res.data.message,
                "warning",
            );
        } else {
            (Swal as TVueSwalInstance).fire(
                "Felicitaciones!",
                res.data.message,
                "success",
            );
            ModalRegisterRole.value = false;

            if (!role_selected.value) {//si no hay rol seleccionado es un nuevo registro

                if (res.data.role) {
                    roles.value.unshift(res.data.role);//unshift agrega al inicio de la lista
                }
            } else {
                //actualizar la lista
                const INDEX = roles.value.findIndex((rl) => rl.id == role_selected.value?.id);//buscar el índice del rol actualizado
                if (INDEX != -1) {
                    if (res.data.role) {
                        roles.value[INDEX] = res.data.role;
                    }

                }
            }


            name.value = null;
            permission_selected.value = [];

            //list();
        }
    } catch (error) {
        console.log(error);
    }

}

const editRole = (role: Role) => {
    ModalRegisterRole.value = true;
    role_selected.value = role;
    name.value = role.name;
    permission_selected.value = role.permissions_pluck;
}


const removeRole = (role: Role) => {
    try {
        (Swal as TVueSwalInstance)
            .fire({
                title: "Confirmar la eliminación",
                text: `¿Estas seguro de eliminar este rol '${role.name}' ?`,
                icon: "warning",
                showCancelButton: true,
                confirmButtonColor: "#3085d6",
                cancelButtonColor: "#d33",
                confirmButtonText: "Si, eliminalo!",
            })
            .then(async (result: any) => {
                if (result.isConfirmed) {
                    const res: AxiosResponse<Roles> = await httpClient.delete(
                        "roles/" + role.id
                    );
                    console.log(res);

                    (Swal as TVueSwalInstance).fire(
                        "Felicitaciones!",
                        "El rol '" + role.name + "' ha sido eliminado con éxito.",
                        "success",
                    );
                    let INDEX = roles.value.findIndex((rl) => rl.id == role.id);
                    if (INDEX != -1) {
                        roles.value.splice(INDEX, 1);
                    }

                }
            });

    } catch (error) {
        console.log(error);
    }
}


watch(ModalRegisterRole, (value) => {
    if (!value)//cuando se cierra el modal
    {
        role_selected.value = undefined;
        name.value = null;
        permission_selected.value = [];
    }
});

let timer: ReturnType<typeof setTimeout> | null = null

watch(search, val => {//buscar con debounce automatico
    if (timer !== null) clearTimeout(timer);
    timer = setTimeout(() => {
        list()
    }, 300)
})

watch(currentPage, val => {
    list();
})

onMounted(() => {
    list();
});


</script>
<template>
    <DefaultLayout>
        <b-row class="justify-content-center">
            <b-col cols="12">
                <b-card no-body>
                    <b-card-header>
                        <b-card-title>🔐 Roles y Permisos</b-card-title>
                        <b-row class="align-items-center justify-content-between mt-3">
                            <b-col lg="7" class="text-center">
                                <b-form-input type="text" id="search" v-model="search"
                                    placeholder="Buscar Rol o Permiso..." />
                            </b-col>
                            <b-col lg="3" md="3">
                                <b-button type="button" @click="list" variant="success">
                                    <i class="fas fa-search"></i>
                                </b-button>
                                <b-button type="button" @click="reset" variant="dark" class="mx-2">
                                    <i class="fas fa-sync"></i>
                                </b-button>
                            </b-col>
                            <b-col lg="2">
                                <b-button type="button" variant="success"
                                    @click="ModalRegisterRole = !ModalRegisterRole">
                                    <i class="far fa-plus-square ml-3"></i> Registrar
                                </b-button>
                            </b-col>
                        </b-row>

                    </b-card-header>
                    <b-card-body class="pt-0">
                        <b-table-simple responsive class="mb-0 table-centered">
                            <b-thead class="table-light">
                                <b-tr>
                                    <b-td>Nro</b-td>
                                    <b-th>Role</b-th>
                                    <b-th>Permisos</b-th>
                                    <b-th>Fecha Registro</b-th>
                                    <b-th class="text-end">Action</b-th>
                                </b-tr>
                            </b-thead>
                            <b-tbody>
                                <b-tr v-for="(role, index) in roles" :key="index">
                                    <b-td>
                                        {{ roles.length - index }}
                                    </b-td>
                                    <b-td>
                                        {{ role.name }}
                                    </b-td>

                                    <b-td>
                                        <ul>
                                            <li v-for="(item, index2) in role.permissions_pluck" :key="index2">
                                                {{ item }}
                                            </li>
                                        </ul>
                                    </b-td>
                                    <b-td>
                                        {{ role.created_at }}
                                    </b-td>
                                    <b-td class="text-end" v-if="role.id != '1'">
                                        <a href="#" @click="editRole(role)"><i
                                                class="las la-pen text-secondary fs-22"></i></a>{{ " " }}
                                        <a href="#" @click="removeRole(role)"><i
                                                class="las la-trash-alt text-secondary fs-22"></i></a>
                                    </b-td>
                                </b-tr>

                            </b-tbody>
                        </b-table-simple>
                        <b-pagination v-model="currentPage" :total-rows="totalPages" :per-page="perPageRows"
                            prev-text="Previous" next-text="Next" />
                    </b-card-body>
                </b-card>

            </b-col>

        </b-row>

        <!-- Modal x Default -->
        <b-modal v-model="ModalRegisterRole" :title="`${role_selected ? 'Edición' : 'Registro'} de Roles y Permisos`"
            :header-class="`bg-${themeColor}`" title-class="m-0 text-white" :ok-variant="themeColor" hide-footer
            centered>

            <b-row>
                <b-col lg="12" class="text-center align-self-center">
                    <b-form-input type="text" id="name" v-model="name" placeholder="Nombre del Rol" />
                </b-col>
                <b-col cols="12" class="mt-2">
                    <b-table-simple responsive class="mb-0 table-centered">
                        <b-thead class="table-light">
                            <b-tr>
                                <b-th>Modulo</b-th>
                                <b-th>Permisos</b-th>
                            </b-tr>
                        </b-thead>
                        <b-tbody>
                            <b-tr v-for="(PERMISO, index) in PERMISOS" :key="index">
                                <b-td>
                                    {{ PERMISO.name }}
                                </b-td>
                                <ul>
                                    <li v-for="(permiso, index2) in PERMISO.permisos" :key="index">
                                        <b-form-checkbox :checked="permission_selected.includes(permiso.permiso)"
                                            @click="addPermission(permiso)" name="checkbox-1">
                                            {{ permiso.name }}
                                        </b-form-checkbox>
                                    </li>
                                </ul>
                            </b-tr>
                        </b-tbody>
                    </b-table-simple>
                </b-col>
                <b-col lg="12" class="mt-3">
                    <div class="modal-footer">
                        <b-button type="button" variant="secondary" @click="ModalRegisterRole = !ModalRegisterRole">
                            Cerrar
                        </b-button>
                        <b-button type="button" variant="primary" @click="store">
                            {{ role_selected ? 'Actualizar' : 'Guardar' }}
                        </b-button>
                    </div>
                </b-col>
            </b-row>
        </b-modal>
    </DefaultLayout>
</template>
