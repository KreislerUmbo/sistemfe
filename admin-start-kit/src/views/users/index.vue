<template>
    <DefaultLayout>
        <b-row class="justify-content-center">
            <b-col cols="12">
                <b-card-header>
                    <b-card-title>💁 Usuarios</b-card-title>
                    <b-row class="align-items-center justify-content-between mt-3">
                        <b-col lg="7" class="text-center">
                            <b-form-input type="text" id="search" v-model="search"
                                placeholder="Buscar usuario por nombre | apellidos | nro de documento" />
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
                            <b-button type="button" variant="success" @click="ModalRegisterUser = !ModalRegisterUser">
                                <i class="far fa-plus-square ml-2"></i> Registrar
                            </b-button>
                        </b-col>
                    </b-row>
                </b-card-header>

                <b-card-body class="pt-0 mt-2">
                    <b-table-simple responsive class="mb-0 table-centered">
                        <b-thead class="table-light">
                            <b-tr>
                                <b-td>Nro</b-td>
                                <b-td>Perfil</b-td>
                                <b-th>Nombre y Apellido</b-th>
                                <b-th>Email</b-th>
                                <b-th>Telefono</b-th>
                                <b-th>Role</b-th>
                                <b-th>Estado</b-th>
                                <b-th>Fecha Registro</b-th>
                                <b-th class="text-end">Action</b-th>
                            </b-tr>
                        </b-thead>
                        <b-tbody>
                            <b-tr v-for="(user, index) in users" :key="index">
                                <b-td>
                                    {{ users.length - index }}
                                </b-td>
                                <b-td>
                                    <div><img :src="user.avatar" style="border-radius: 50%;" width="40" height="40"
                                            v-if="user.avatar" />
                                    </div>
                                </b-td>
                                <b-td>
                                    {{ user.full_name }}
                                </b-td>
                                <b-td>
                                    {{ user.email }}
                                </b-td>
                                <b-td>
                                    {{ user.phone }}
                                </b-td>
                                <b-td>
                                    {{ user.role.name }}
                                </b-td>
                                <b-td>
                                    <b-badge variant="primary" v-if="user.state == 1">Activo</b-badge>
                                    <b-badge variant="danger" v-if="user.state == 2">Inactivo</b-badge>
                                </b-td>
                                <b-td>
                                    {{ user.created_at }}
                                </b-td>
                                <b-td class="text-end">
                                    <a href="#" @click="editUser(user)"><i
                                            class="las la-pen text-secondary fs-22"></i></a>{{ " " }}
                                    <a href="#" @click="removeUser(user)"><i
                                            class="las la-trash-alt text-secondary fs-22"></i></a>
                                </b-td>
                            </b-tr>

                        </b-tbody>
                    </b-table-simple>
                    <b-pagination v-model="currentPage" :total-rows="totalPages" :per-page="perPageRows"
                        prev-text="Previous" next-text="Next" />
                </b-card-body>

            </b-col>
        </b-row>

        <!-- Inici L ventana del Modal x Default -->
        <b-modal v-model="ModalRegisterUser" :title="`${user_selected ? 'Edición' : 'Registro'} de Usuario`"
            :header-class="`bg-${themeColor}`" title-class="m-0 text-white" :ok-variant="themeColor" hide-footer
            centered size="lg">

            <b-row>
                <b-col lg="5">
                    <label for="name-user" class="col-form-label text-lg-end">Nombre: </label>
                    <b-form-input type="text" id="name-user" v-model="name" placeholder="Example: Jose" />
                </b-col>
                <b-col lg="5">
                    <label for="surname-user" class="col-form-label text-lg-end">Apellido: </label>
                    <b-form-input type="text" id="surname-user" v-model="surname" placeholder="Example: Jose" />
                </b-col>

                <b-col lg="4">
                    <label for="email-user" class="col-form-label text-lg-end">Email: </label>
                    <b-form-input type="text" id="email-user" v-model="email"
                        placeholder="Example: laravest@gmail.com" />
                </b-col>

                <b-col lg="4">
                    <label for="phone-user" class="col-form-label text-lg-end">Telefono: </label>
                    <b-form-input type="number" id="phone-user" v-model="phone" placeholder="Example: 900 900 900" />
                </b-col>
                <b-col lg="4">
                    <label for="role_list" class="col-form-label text-lg-end">Role: </label>
                    <b-form-select id="role_list" v-model="role_id">
                        <option value="">Selec. Rol</option>
                        <template v-for="(role, index) in roles" :key="index">
                            <option :value="role.id">{{ role.name }}</option>
                        </template>
                    </b-form-select>
                </b-col>

                <b-col lg="4">
                    <label for="type_document_list" class="col-form-label text-lg-end">Tipo de documento: </label>
                    <b-form-select id="type_document_list" v-model="type_document">
                        <option value="DNI">DNI</option>
                        <option value="PASAPORTE">PASAPORTE</option>
                        <option value="CARNET DE EXTRANJERIA">CARNET DE EXTRANJERIA</option>
                        <option value="TARJETA MILITAR">TARJETA MILITAR</option>
                    </b-form-select>
                </b-col>

                <b-col lg="4">
                    <label for="n_document-user" class="col-form-label text-lg-end">N° de Documento: </label>
                    <b-form-input type="number" id="n_document-user" v-model="n_document"
                        placeholder="Example: #######" />
                </b-col>

                <b-col lg="4">
                    <fieldset>
                        <legend class="col-form-label text-lg-end">Género:</legend>

                        <b-form-radio id="gender-m" name="gender" @click="gender = 'M'" value="M"
                            :checked="gender == 'M'">
                            Masculino
                        </b-form-radio>

                        <b-form-radio id="gender-f" name="gender" @click="gender = 'F'" value="F"
                            :checked="gender == 'F'">
                            Femenino
                        </b-form-radio>
                    </fieldset>
                </b-col>

                <b-col lg="3">
                    <fieldset>
                        <legend class="col-form-label text-lg-end">Estado:</legend>

                        <b-form-radio id="state-active" name="state" @click="state = 1" value="1" :checked="state == 1">
                            Activo
                        </b-form-radio>

                        <b-form-radio id="state-inactive" name="state" @click="state = 2" value="2"
                            :checked="state == 2">
                            Inactivo
                        </b-form-radio>
                    </fieldset>
                </b-col>

                <b-col lg="4">
                    <label for="password-user" class="col-form-label text-lg-end">Contraseña: </label>
                    <b-form-input type="password" id="password-user" v-model="password" placeholder="*********" />
                </b-col>

                <b-col lg="5">
                    <label for="avatar-user" class="col-form-label text-lg-end">Avatar de Usuario: </label>
                    <b-input-group class="mb-3">
                        <b-form-file id="avatar-user" @change="loadFile($event)" />
                        <b-input-group-text>Upload</b-input-group-text>
                    </b-input-group>

                    <img v-if="IMAGEN_PREVIZUALIZA" :src="IMAGEN_PREVIZUALIZA" alt="" width="100px"
                        class="rounded d-block mx-auto" />
                </b-col>

                <b-col lg="12" class="mt-3">
                    <div class="modal-footer">
                        <b-button type="button" variant="secondary" @click="ModalRegisterUser = !ModalRegisterUser"
                            data-bs-dismiss="modal">
                            Cerrar
                        </b-button>
                        <b-button type="button" variant="primary" @click="store">
                            Guardar
                        </b-button>
                    </div>
                </b-col>
            </b-row>
        </b-modal>
    </DefaultLayout>
</template>

<script setup lang="ts">
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import httpClient from '@/helpers/http-client';
import type { AxiosResponse } from 'axios';
import type { RoleUser, User, UserResponse, Users } from '@/types/users';
import { onMounted, ref, watch } from 'vue';

import Swal from "sweetalert2/dist/sweetalert2.js";
type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const users = ref<User[]>([]);
const user_selected = ref<User | undefined>(undefined);
const themeColor = ref<string>('primary');

const name = ref<string>('');
const surname = ref<string>('');
const email = ref<string>('');
const phone = ref<number | undefined>(undefined);
const roles = ref<RoleUser[]>([]);
const role_id = ref<string | undefined>(undefined);
const type_document = ref<string>('DNI');
const n_document = ref<string>('');
const gender = ref<string>('M');
const state = ref<number>(1); // 1: Activo, 2: Inactivo
const password = ref<string>('');
const FILE_AVATAR = ref<File | undefined>(undefined);
const IMAGEN_PREVIZUALIZA = ref<string | ArrayBuffer | null>(null);


const search = ref<string | null>(null);
const ModalRegisterUser = ref<Boolean>(false);

const currentPage = ref<number>(1);
const totalPages = ref<number>(0);
const perPageRows = ref<number>(10);

// Función para cargar y previsualizar la imagen seleccionada
const loadFile = ($event: any) => {
    if ($event.target.files[0].type.indexOf("image") < 0) {// validar que sea una imagen
        IMAGEN_PREVIZUALIZA.value = null;
        (Swal as TVueSwalInstance).fire(// Usar Swal para mostrar alerta
            'Upss!',
            'El archivo seleccionado no es una imagen.',
            'error');
        return;
    }
    FILE_AVATAR.value = $event.target.files[0];// obtener el archivo y lo asigna a la variable reactiva FILE_AVATAR
    let reader = new FileReader();// crear un objeto FileReader para leer el archivo como una URL de datos
    if (FILE_AVATAR.value) {
        reader.readAsDataURL(FILE_AVATAR.value); // leer el archivo como una URL de datos
        reader.onloadend = () => IMAGEN_PREVIZUALIZA.value = reader.result;// asignar la URL de datos a la variable reactiva IMAGEN_PREVIZUALIZA para previsualizar la imagen
    }
}


// Listar usuarios
const list = async () => {
    try {
        const res: AxiosResponse<Users> = await httpClient.get(
            `users?page=${currentPage.value}&search=${search.value ?? ''}`);

        console.log(res);
        users.value = res.data.users.data;
        totalPages.value = res.data.total;   // total de filas
        perPageRows.value = res.data.paginate; // registros por página
        roles.value = res.data.roles;

    } catch (error) {
        console.log(error);
    }
};
const reset = () => {
    search.value = '';
    currentPage.value = 1;
    list(); // <-- faltaba
};

const clearFields = () => {
    name.value = '';
    surname.value = '';
    n_document.value = '';
    password.value = '';
    FILE_AVATAR.value = undefined;
    IMAGEN_PREVIZUALIZA.value = null;
    role_id.value = '';
    email.value = '';
    phone.value = 0;
};


const store = async () => {
    try {

        if (name.value.trim() === '') { //trim() elimina espacios en blanco al inicio y al final
            const swal = Swal as TVueSwalInstance;
            await swal.fire('Error', 'El nombre del usuario es obligatorio.', 'error');
            return;
        }

        if (!surname.value.trim()) { // Verifica si el apellido está vacío
            const swal = Swal as TVueSwalInstance;
            await swal.fire('Error', 'El apellido del usuario es obligatorio.', 'error');
            return;
        }
        if (!role_id.value) {
            const swal = Swal as TVueSwalInstance;
            await swal.fire('Error', 'El rol del usuario es obligatorio.', 'error');
            return;
        }
        if (!email.value.trim()) { // Verifica si el apellido está vacío
            const swal = Swal as TVueSwalInstance;
            await swal.fire('Error', 'El correo electrónico del usuario es obligatorio.', 'error');
            return;
        }
        if (!n_document.value) { // Verifica si el apellido está vacío
            const swal = Swal as TVueSwalInstance;
            await swal.fire('Error', 'El número de documento del usuario es obligatorio.', 'error');
            return;
        }
        if (!user_selected.value) { // Si no hay usuario seleccionado, es un nuevo registro
            if (!password.value) { // 
                const swal = Swal as TVueSwalInstance;
                await swal.fire('Error', 'La contraseña del usuario es obligatoria.', 'error');
                return;
            }
        }


        //NOTA se usa FormData para enviar los datos del formulario que contengan archivo de imagen, video si se seleccionó
        //en caso de que no se seleccione un archivo, no seria necesario usar FormData 
        let formData = new FormData();
        formData.append('name', name.value);
        formData.append('surname', surname.value);
        formData.append('email', email.value);
        formData.append('phone', phone.value + "");
        formData.append('role_id', role_id.value + "");
        formData.append('type_document', type_document.value);
        formData.append('n_document', n_document.value);
        formData.append('gender', gender.value);
        formData.append('password', password.value);
        formData.append('state', state.value + "");
        if (FILE_AVATAR.value) {
            formData.append('imagen', FILE_AVATAR.value);
        }

        const res: AxiosResponse<UserResponse> =
            !user_selected.value ?//si no hay usuario seleccionado es un nuevo registro
                await httpClient.post(
                    "users", formData)
                ://si hay useuario seleccionado es una actualización
                await httpClient.post(
                    "users/" + user_selected.value?.id, formData);


        console.log(res);

        if (res.data.code == 405) {
            (Swal as TVueSwalInstance).fire(
                "Upps!",
                res.data.message,
                "warning",
            );
        } else {

            ModalRegisterUser.value = false; // Cerrar el modal

            //esta parte si no hay usuario seleccionado es un nuevo registro de lo contrario es una actualización 
            //pero es para actualizar la lista de usuarios
            if (!user_selected.value) {

                if (res.data.user) {
                    users.value.unshift(res.data.user); //unshift agrega al inicio de la lista
                }
            } else {
                //actualizar la lista
                const INDEX = users.value.findIndex((usr) => usr.id == user_selected.value?.id);//buscar el índice del usuario actualizado
                if (INDEX != -1) {
                    if (res.data.user) {
                        users.value[INDEX] = res.data.user;//actualizar el usuario en la lista
                    }

                }
            }

            (Swal as TVueSwalInstance).fire(
                "Felicitaciones!",
                res.data.message,
                "success",
            );

            reset();

        }

    } catch (error: any) {
        console.log(error);
        if (error.response?.data?.message) {// Verifica si el error tiene un mensaje de respuesta del servidor o backend
            (Swal as TVueSwalInstance).fire(
                'Error',
                error.response.data.message,
                'error');
        }
    }

};

const editUser = (user: User) => {
    ModalRegisterUser.value = true;
    user_selected.value = user;
    name.value = user.name;
    surname.value = user_selected.value.surname;
    email.value = user.email;
    phone.value = user.phone;
    role_id.value = user.role.id;
    type_document.value = user.type_document;
    n_document.value = user.n_document;
    gender.value = user.gender;
    state.value = user.state;
    IMAGEN_PREVIZUALIZA.value = user.avatar ?? '';// ?? es un operador de fusión nula que asigna una cadena vacía si user.avatar es undefined o null
    FILE_AVATAR.value = undefined; // Reiniciar el archivo de avatar


};

const removeUser = (user: User) => {
    try {
        (Swal as TVueSwalInstance)
            .fire({ //fire muestra una alerta de confirmación
                title: "Confirmar la eliminación",
                text: `¿Estas seguro de eliminar este usuario '${user.name}' ?`,
                icon: "warning",
                showCancelButton: true,
                confirmButtonColor: "#3085d6",
                cancelButtonColor: "#d33",
                confirmButtonText: "Si, eliminalo!",
            })
            .then(async (result: any) => { //then maneja la respuesta de la alerta
                if (result.isConfirmed) {
                    const res: AxiosResponse<User> = await httpClient.delete(
                        "users/" + user.id
                    );
                    console.log(res);

                    (Swal as TVueSwalInstance).fire(
                        "Felicitaciones!",
                        "El usuario '" + user.name + "' ha sido eliminado con éxito.",
                        "success",
                    );
                    let INDEX = users.value.findIndex((usr) => usr.id == user.id); //buscar el índice del usuario eliminado
                    if (INDEX != -1) { //si se encuentra el usuario en la lista
                        users.value.splice(INDEX, 1); //eliminar el usuario de la lista
                    }

                }
            });

    } catch (error) {
        console.log(error);
    }

};


onMounted(() => {
    list();
});

watch(ModalRegisterUser, (value) => {
    if (!value)//cuando se cierra el modal
    {
        user_selected.value = undefined;
        clearFields();
    }
});

watch(currentPage, () => list());
</script>