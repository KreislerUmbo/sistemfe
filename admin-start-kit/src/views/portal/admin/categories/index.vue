<template>
    <DefaultLayout>
        <b-row class="justify-content-center">
            <b-col cols="12">
                <b-card-header>
                    <b-card-title>Categorías de Sistemas</b-card-title>
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
                                <b-th class="text-end">Acción</b-th>
                            </b-tr>
                        </b-thead>
                        <b-tbody>
                            <b-tr v-for="(categorie, index) in categories" :key="index">
                                <b-td>
                                    {{ categories.length - index }}
                                </b-td>
                                <b-td>
                                    <div><img :src="categorie.imagen || defaultImage" style="border-radius: 50%;"
                                            alt="Imagen de la categoría" width="50" /></div>
                                </b-td>
                                <b-td>
                                    {{ categorie.nombre }}
                                </b-td>
                                <b-td>
                                    <b-badge variant="primary" v-if="categorie.state == 1">Activo</b-badge>
                                    <b-badge variant="danger" v-if="categorie.state == 2">Inactivo</b-badge>
                                </b-td>
                                <b-td>
                                    {{ categorie.created_at || null }}
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
                        prev-text="Anterior" next-text="Siguiente" />
                </b-card-body>
            </b-col>
        </b-row>

        <!-- Modal -->
        <b-modal v-model="ModalRegisterCategory"
            :title="`${categorie_selected ? 'Edición' : 'Registro'} de Categoría de Sistemas`"
            :header-class="`bg-${themeColor}`" title-class="m-0 text-white" :ok-variant="themeColor" hide-footer
            centered size="lg">
            <b-row>
                <b-col lg="6">
                    <b-row>
                        <b-col lg="6">
                            <label for="description-categorie" class="col-form-label text-lg-end mt-2">Icono de la
                                categoría: </label>
                            <b-form-input id="icon" v-model="icon" placeholder="Ej: 🛒" maxlength="10" />
                        </b-col>
                        <b-col lg="6">
                            <label for="display_order" class="col-form-label text-lg-end mt-2">Orden de visualización:
                            </label>
                            <b-form-input id="display_order" v-model="display_order" type="number" min="0"
                                placeholder="0" />

                        </b-col>
                    </b-row>


                    <label for="name-categorie" class="col-form-label text-lg-end">Nombre de la categoría: </label>
                    <b-form-input type="text" id="name-categorie" placeholder="Ej: Comercio" v-model="nombre" />


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
                        <b-input-group-text>Subir</b-input-group-text>
                    </b-input-group>
                    <img v-if="IMAGEN_PREVIZUALIZA && typeof IMAGEN_PREVIZUALIZA === 'string'"
                        :src="IMAGEN_PREVIZUALIZA || defaultImage" alt="" width="100px"
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
import { systemCategoryService } from '@/services/portal/systemCategoryService';
import type { AxiosResponse } from 'axios';
import { ref, onMounted, watch } from 'vue';
import Swal from 'sweetalert2';
import type { SystemCategory, SystemCategoriesResponse, SystemCategoryResponse } from '@/types/system_categories';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;
type ThemeColor = 'primary' | 'secondary' | 'success' | 'danger' | 'warning' | 'info' | 'dark' | 'light'

const themeColor: ThemeColor = 'primary'
const ModalRegisterCategory = ref<boolean>(false);
const search = ref<string | null>(null);
const currentPage = ref<number>(1);
const totalPages = ref<number>(0);
const perPageRows = ref<number>(15);

const defaultImage = ref<string>('http://localhost:8000/storage/img/default.jpg');// ajusta si tienes una imagen por defecto

const categories = ref<SystemCategory[]>([]);
const nombre = ref<string>('');
const display_order = ref<number>(0);
const icon = ref<string>('');
const FILE_IMAGEN = ref<File | undefined>(undefined);
const IMAGEN_PREVIZUALIZA = ref<string | ArrayBuffer | null>(null);
const state = ref<number>(1);
const categorie_selected = ref<SystemCategory | undefined>(undefined);

const list = async () => {
    try {
        const res: AxiosResponse<SystemCategoriesResponse> = await systemCategoryService.list(
            currentPage.value,
            search.value ?? ''
        );
        console.log(res);
        categories.value = res.data.categories;
        totalPages.value = res.data.total;
        perPageRows.value = res.data.paginate;
    } catch (error) {
        console.error(error);
    }
};

const reset = () => {
    search.value = '';
    currentPage.value = 1;
    list();
};

const clearFields = () => {
    nombre.value = '';
    icon.value = '';
    display_order.value = 0;
    FILE_IMAGEN.value = undefined;
    IMAGEN_PREVIZUALIZA.value = null;
    state.value = 1;
    categorie_selected.value = undefined;
};

const store = async () => {
    try {
        if (nombre.value.trim() === '') {
            (Swal as TVueSwalInstance).fire('Error', 'El nombre de la categoría es obligatorio.', 'error');
            return;
        }

        const formData = new FormData();
        formData.append('nombre', nombre.value);
        formData.append('icon', icon.value);
        formData.append('display_order', String(display_order.value));
        formData.append('state', state.value + "");
        if (FILE_IMAGEN.value) {
            formData.append('image', FILE_IMAGEN.value);
        }

        let res: AxiosResponse<SystemCategoryResponse>;
        if (!categorie_selected.value) {
            res = await systemCategoryService.store(formData);
        } else {
            res = await systemCategoryService.update(categorie_selected.value.id, formData);
        }

        if (res.data.code === 405) {
            (Swal as TVueSwalInstance).fire('Upps!', res.data.message, 'warning');
            return;
        }

        ModalRegisterCategory.value = false;

        if (!categorie_selected.value && res.data.categorie) {
            categories.value.unshift(res.data.categorie);
        } else if (categorie_selected.value && res.data.categorie) {
            const index = categories.value.findIndex(cat => cat.id === categorie_selected.value?.id);
            if (index !== -1) {
                categories.value[index] = res.data.categorie;
            }
        }

        (Swal as TVueSwalInstance).fire('Felicitaciones!', res.data.message, 'success');
        reset();
        clearFields();
    } catch (error: any) {
        console.error(error);
        const msg = error.response?.data?.message || 'Ocurrió un error';
        (Swal as TVueSwalInstance).fire('Error', msg, 'error');
    }
};

const editCategory = (categorie: SystemCategory) => {
    ModalRegisterCategory.value = true;
    categorie_selected.value = categorie;
    nombre.value = categorie.nombre;
    icon.value = categorie.icon;
    display_order.value = categorie.display_order;
    state.value = categorie.state;
    IMAGEN_PREVIZUALIZA.value = categorie.imagen ?? null;
    FILE_IMAGEN.value = undefined;
};

const removeCategory = (categorie: SystemCategory) => {
    (Swal as TVueSwalInstance)
        .fire({
            title: 'Confirmar eliminación',
            text: `¿Estás seguro de eliminar esta categoría '${categorie.nombre}' ?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Sí, eliminar!',
        })
        .then(async (result) => {
            if (result.isConfirmed) {
                await systemCategoryService.delete(categorie.id);
                (Swal as TVueSwalInstance).fire('Eliminado!', `Categoría "${categorie.nombre}" eliminada.`, 'success');
                const index = categories.value.findIndex(cat => cat.id === categorie.id);
                if (index !== -1) categories.value.splice(index, 1);
            }
        });
};

const loadFile = ($event: any) => {
    const file = $event.target.files[0];
    if (!file || file.type.indexOf('image') < 0) {
        IMAGEN_PREVIZUALIZA.value = null;
        (Swal as TVueSwalInstance).fire('Upss!', 'El archivo seleccionado no es una imagen.', 'error');
        return;
    }
    FILE_IMAGEN.value = file;
    const reader = new FileReader();
    reader.readAsDataURL(file);
    reader.onloadend = () => {
        IMAGEN_PREVIZUALIZA.value = reader.result;
    };
};

onMounted(() => {
    list();
});

watch(ModalRegisterCategory, (val) => {
    if (!val) {
        clearFields();
    }
});

watch(currentPage, () => list());
</script>