<template>
    <DefaultLayout>
        <b-row class="justify-content-center">
            <b-col cols="12">
                <b-card>
                    <b-card-header>
                        <b-card-title>Sistemas Empresariales</b-card-title>
                        <b-row class="align-items-center justify-content-between mt-3">
                            <b-col lg="7" class="text-center">
                                <b-form-input type="text" id="search" v-model="search"
                                    placeholder="Buscar sistema por nombre" @keyup.enter="list" />
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
                                <b-button type="button" variant="success" @click="goToCreate">
                                    <i class="far fa-plus-square ml-2"></i> Registrar
                                </b-button>
                            </b-col>
                        </b-row>
                    </b-card-header>

                    <b-card-body class="pt-0 mt-2">
                        <b-table-simple responsive class="mb-0 table-centered">
                            <b-thead class="table-light">
                                <b-tr>
                                    <b-td>#</b-td>
                                    <b-th>Icono</b-th>
                                    <b-th>Nombre</b-th>
                                    <b-th>Categoría</b-th>
                                    <b-th>Badge</b-th>
                                    <b-th>Rating</b-th>
                                    <b-th>Destacado</b-th>
                                    <b-th>Estado</b-th>
                                    <b-th class="text-end">Acciones</b-th>
                                </b-tr>
                            </b-thead>
                            <b-tbody>
                                <b-tr v-for="(system, index) in systems" :key="system.id">
                                    <b-td>{{ systems.length - index }}</b-td>
                                    <b-td>
                                        <span style="font-size: 24px;">{{ system.icon_emoji || '📦' }}</span>
                                    </b-td>
                                    <b-td>{{ system.name }}</b-td>
                                    <b-td>{{ system.category || 'Sin categoría' }}</b-td>
                                    <b-td>
                                        <span v-if="system.badge" class="badge bg-info">{{ system.badge }}</span>
                                        <span v-else class="text-muted">—</span>
                                    </b-td>
                                    <b-td>{{ system.rating_avg }} ({{ system.rating_count }})</b-td>
                                    <b-td>
                                        <b-badge :variant="system.is_featured ? 'warning' : 'secondary'">
                                            {{ system.is_featured ? 'Sí' : 'No' }}
                                        </b-badge>
                                    </b-td>
                                    <b-td>
                                        <b-badge :variant="system.is_active ? 'success' : 'danger'">
                                            {{ system.is_active ? 'Activo' : 'Inactivo' }}
                                        </b-badge>
                                    </b-td>
                                    <b-td class="text-end">
                                        <a href="#" @click="editSystem(system.id)">
                                            <i class="las la-pen text-secondary fs-22"></i>
                                        </a>
                                        <a href="#" @click="removeSystem(system)">
                                            <i class="las la-trash-alt text-secondary fs-22"></i>
                                        </a>
                                    </b-td>
                                </b-tr>
                            </b-tbody>
                        </b-table-simple>
                        <b-pagination v-model="currentPage" :total-rows="totalRows" :per-page="perPage"
                            prev-text="Previous" next-text="Next" />
                    </b-card-body>
                </b-card>
            </b-col>
        </b-row>
    </DefaultLayout>
</template>

<script setup lang="ts">
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import { systemService } from '@/services/portal/systemService';
import type { System } from '@/types/systems';
import { ref, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import Swal from 'sweetalert2';
import type { AxiosResponse } from 'axios';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const router = useRouter();

const search = ref<string>('');
const currentPage = ref<number>(1);
const totalRows = ref<number>(0);
const perPage = ref<number>(15);
const systems = ref<System[]>([]);

const list = async () => {
    try {
        const res: AxiosResponse<any> = await systemService.list(currentPage.value, search.value);
        systems.value = res.data.systems;
        totalRows.value = res.data.total;
        perPage.value = res.data.paginate;
    } catch (error) {
        console.log(error);
    }
};

const reset = () => {
    search.value = '';
    currentPage.value = 1;
    list();
};

const goToCreate = () => {
    router.push({ name: 'systems.create' });
};

const editSystem = (id: number) => {
    router.push({ name: 'systems.edit', params: { id } });
};

const removeSystem = (system: System) => {
    (Swal as TVueSwalInstance)
        .fire({
            title: 'Confirmar eliminación',
            text: `¿Estás seguro de eliminar el sistema "${system.name}"?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Sí, eliminar',
        })
        .then(async (result: any) => {
            if (result.isConfirmed) {
                try {
                    await systemService.delete(system.id);
                    (Swal as TVueSwalInstance).fire(
                        'Eliminado',
                        `El sistema "${system.name}" ha sido eliminado.`,
                        'success'
                    );
                    // Remover de la lista
                    const index = systems.value.findIndex(s => s.id === system.id);
                    if (index !== -1) {
                        systems.value.splice(index, 1);
                        totalRows.value--;
                    }
                } catch (error) {
                    console.log(error);
                    (Swal as TVueSwalInstance).fire('Error', 'No se pudo eliminar el sistema.', 'error');
                }
            }
        });
};

onMounted(() => {
    list();
});

watch(currentPage, () => list());
</script>