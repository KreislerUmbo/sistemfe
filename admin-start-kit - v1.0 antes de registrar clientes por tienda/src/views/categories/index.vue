<template>
    <DefaultLayout>
        <b-row class="justify-content-center">
            <b-col cols="12">
                <b-card-header>
                    <b-card-title>Categorías</b-card-title>
                    <b-row class="align-items-center justify-content-between mt-3">
                        <b-col lg="7" class="text-center">
                            <b-form-input type="text" id="search" v-model="search"
                                placeholder="Buscar categoría por nombre" @keyup.enter="list" />
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
                                @click="ModalRegisterCategory = !ModalRegisterCategory">
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
                                <b-th>Imagen</b-th>
                                <b-th>Descripción</b-th>
                                <b-th>Estado</b-th>
                                <b-th>Fecha Registro</b-th>
                                <b-th class="text-end">Action</b-th>
                            </b-tr>
                        </b-thead>
                        <b-tbody>
                            <b-tr v-for="(categorie, index) in categories" :key="index">
                                <b-td>
                                    {{ categories.length - index }}
                                </b-td>
                                <b-td>
                                    <div><img :src="categorie.imagen" style="border-radius: 50%;"
                                            alt="Imagen de la categoría" width="50" /></div>
                                </b-td>
                                <b-td>
                                    {{ categorie.title }}
                                </b-td>
                                <b-td>
                                    <b-badge variant="primary" v-if="categorie.state == 1">Activo</b-badge>
                                    <b-badge variant="danger" v-if="categorie.state == 2">Inactivo</b-badge>
                                </b-td>
                                <b-td>
                                    {{ categorie.created_at }}
                                </b-td>
                                <b-td class="text-end">
                                    <a href="#" @click="editCategory(categorie)"><i
                                            class="las la-pen text-secondary fs-22"></i></a>{{ " " }}
                                    <a href="#" @click="removeCategory(categorie)"><i
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
        <b-modal v-model="ModalRegisterCategory" :title="`${categorie_selected ? 'Edición' : 'Registro'} de Categorías`"
            :header-class="`bg-${themeColor}`" title-class="m-0 text-white" :ok-variant="themeColor" hide-footer
            centered size="lg">

            <b-row>
                <b-col lg="6">

                    <label for="name-categorie" class="col-form-label text-lg-end">Nombre de la categoría: </label>
                    <b-form-input type="text" id="name-categorie" placeholder="Example: Electronics" v-model="title" />

                    <label class="col-form-label text-lg-end mt-2">Estado:</label>
                        
                        <b-form-radio name="state" @click="state = 1" value="1" :checked="state == 1">
                            Activo
                        </b-form-radio>
                        <b-form-radio name="state" @click="state = 2" value="2" :checked="state == 2">
                            Inactivo
                        </b-form-radio>
                    

                </b-col>

                <b-col lg="6">
                    <label for="avatar-categorie" class="col-form-label text-lg-end">Imagen de la categoría: </label>
                    <b-input-group class="mb-3">
                        <b-form-file id="avatar-categorie" @change="loadFile($event)" />
                        <b-input-group-text>Upload</b-input-group-text>
                    </b-input-group>

                    <img v-if="IMAGEN_PREVIZUALIZA" :src="IMAGEN_PREVIZUALIZA" alt="" width="100px"
                        class="rounded d-block mx-auto" />
                </b-col>

                <b-col lg="12" class="mt-3">
                    <div class="modal-footer">
                        <b-button type="button" variant="secondary"
                            @click="ModalRegisterCategory = !ModalRegisterCategory" data-bs-dismiss="modal">
                            Cerrar
                        </b-button>
                        <b-button type="button" variant="primary" @click="store">
                            {{ categorie_selected ? 'Actualizar' : 'Registrar' }}
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
import { ref, onMounted, watch } from 'vue';

import Swal from "sweetalert2/dist/sweetalert2.js";
import type { Categorie, CategorieResponse, Categories } from '@/types/categories';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const ModalRegisterCategory = ref<Boolean>(false);
const search = ref<string | null>(null);
const currentPage = ref<number>(1);
const totalPages = ref<number>(0);
const perPageRows = ref<number>(15);

const themeColor = ref<string>('primary');

const categories = ref<Categorie[]>([]);// arreglo de categorías inicialmente vacío que se llenará con los datos obtenidos del servidor    

const title = ref<string>("");
const FILE_IMAGEN = ref<File | undefined>(undefined);
const IMAGEN_PREVIZUALIZA = ref<string | ArrayBuffer | null>(null);
const state = ref<number>(1); // 1: Activo, 2: Inactivo


const categorie_selected = ref<Categorie | undefined>(undefined);


const list = async () => {
    try {
        const res: AxiosResponse<Categories> = await httpClient.get(
            `categories?page=${currentPage.value}&search=${search.value ?? ''}`);

        console.log(res);
        categories.value = res.data.categories;
        totalPages.value = res.data.total;   // total de filas
        perPageRows.value = res.data.paginate; // registros por página

    } catch (error) {
        console.log(error);
    }
};
const reset = () => {
    search.value = '';
    currentPage.value = 1;
    list();
};

const clearFields = () => {
    title.value = "";
    FILE_IMAGEN.value = undefined;
    IMAGEN_PREVIZUALIZA.value = null;
    state.value = 1;
};


const store = async () => {
    try {

        if (title.value.trim() === '') { //trim() elimina espacios en blanco al inicio y al final
            const swal = Swal as TVueSwalInstance;
            await swal.fire('Error', 'El nombre de la categoría es obligatorio.', 'error');
            return;
        }



        //NOTA se usa FormData para enviar los datos del formulario que contengan archivo de imagen, video si se seleccionó
        //en caso de que no se seleccione un archivo, no seria necesario usar FormData 
        let formData = new FormData();
        formData.append('title', title.value);

        formData.append('state', state.value + "");

        if (FILE_IMAGEN.value) {
            formData.append('image', FILE_IMAGEN.value);
        }

        const res: AxiosResponse<CategorieResponse> =
            !categorie_selected.value ?//si no hay categoría seleccionada es un nuevo registro
                await httpClient.post(
                    "categories", formData)
                ://si hay useuario seleccionado es una actualización
                await httpClient.post(
                    "categories/" + categorie_selected.value?.id, formData);


        console.log(res);

        if (res.data.code == 405) {
            (Swal as TVueSwalInstance).fire(
                "Upps!",
                res.data.message,
                "warning",
            );
        } else {

            ModalRegisterCategory.value = false; // Cerrar el modal

            //esta parte si no hay usuario seleccionado es un nuevo registro de lo contrario es una actualización 
            //pero es para actualizar la lista de usuarios
            if (!categorie_selected.value) {
                if (res.data.categorie) {
                    categories.value.unshift(res.data.categorie); //unshift agrega al inicio de la lista
                }
            } else {
                //actualizar la lista
                const INDEX = categories.value.findIndex((cat) => cat.id == categorie_selected.value?.id);//buscar el índice de la categoría actualizada
                if (INDEX != -1) {
                    if (res.data.categorie) {
                        categories.value[INDEX] = res.data.categorie;//actualizar la categoría en la lista
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
}


const editCategory = (categorie: Categorie) => { //abrir el modal de edición con los datos de la categoría seleccionada
    ModalRegisterCategory.value = true; //abrir el modal

    categorie_selected.value = categorie;
    title.value = categorie.title;
    state.value = categorie.state;
    IMAGEN_PREVIZUALIZA.value = categorie.imagen ?? ''; //mostrar la imagen actual de la categoría
    FILE_IMAGEN.value = undefined;

};


const removeCategory = (categorie: any) => {
    try {
        (Swal as TVueSwalInstance)
            .fire({ //fire muestra una alerta de confirmación
                title: "Confirmar la eliminación",
                text: `¿Estas seguro de eliminar esta categoría '${categorie.title}' ?`,
                icon: "warning",
                showCancelButton: true,
                confirmButtonColor: "#3085d6",
                cancelButtonColor: "#d33",
                confirmButtonText: "Si, eliminalo!",
            })
            .then(async (result: any) => { //then maneja la respuesta de la alerta
                if (result.isConfirmed) {
                    const res: AxiosResponse<Categorie> = await httpClient.delete(
                        "categories/" + categorie.id
                    );
                    console.log(res);

                    (Swal as TVueSwalInstance).fire(
                        "Felicitaciones!",
                        "La categoría [" + categorie.title + "] ha sido eliminada con éxito.",
                        "success",
                    );
                    let INDEX = categories.value.findIndex((cat) => cat.id == categorie.id); //buscar el índice de la categoría eliminada
                    if (INDEX != -1) { //si se encuentra la categoría en la lista
                        categories.value.splice(INDEX, 1); //eliminar la categoría de la lista
                    }

                }
            });

    } catch (error) {
        console.log(error);
    }
};



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
    FILE_IMAGEN.value = $event.target.files[0];// obtener el archivo y lo asigna a la variable reactiva FILE_IMAGEN
    let reader = new FileReader();// crear un objeto FileReader para leer el archivo como una URL de datos
    if (FILE_IMAGEN.value) {
        reader.readAsDataURL(FILE_IMAGEN.value); // leer el archivo como una URL de datos
        reader.onloadend = () => IMAGEN_PREVIZUALIZA.value = reader.result;// asignar la URL de datos a la variable reactiva IMAGEN_PREVIZUALIZA para previsualizar la imagen
    }
}

onMounted(() => {
    list();
});

watch(ModalRegisterCategory, (value) => {
    if (!value)//cuando se cierra el modal
    {
        categorie_selected.value = undefined;
        clearFields();
    }
});

watch(currentPage, () => list());
</script>