<!-- frontend/src/views/manuales/Register.vue -->
<template>
    <DefaultLayout>
        <b-row class="justify-content-center">
            <b-col cols="12" lg="10">
                <b-card>
                    <b-card-header>
                        <b-card-title>
                            {{ isEdit ? '✏️ Editar Recurso' : '📤 Nuevo Recurso de Capacitación' }}
                        </b-card-title>
                    </b-card-header>
                    <b-card-body>
                        <b-form @submit.prevent="guardar">
                            <b-row>
                                <b-col md="6">
                                    <b-form-group label="Título *" label-for="titulo">
                                        <b-form-input id="titulo" v-model="form.titulo" required
                                            placeholder="Título del recurso" />
                                    </b-form-group>
                                </b-col>
                                <b-col md="6">
                                    <b-form-group label="Categoría" label-for="categoria">
                                        <b-form-input id="categoria" v-model="form.categoria"
                                            placeholder="Ej: Ventas, Inventario, Caja..." />
                                    </b-form-group>
                                </b-col>
                            </b-row>
                            <b-form-group label="Sistema *" label-for="sistema_id">
                                <b-form-select id="sistema_id" v-model="form.sistema_id" required>
                                    <option value="">-- Seleccionar sistema --</option>
                                    <option v-for="sistema in sistemas" :key="sistema.id" :value="sistema.id">
                                        {{ sistema.nombre }}
                                    </option>
                                </b-form-select>
                            </b-form-group>

                            <b-form-group label="Descripción" label-for="descripcion">
                                <b-form-textarea id="descripcion" v-model="form.descripcion" rows="3"
                                    placeholder="Descripción breve del recurso" />
                            </b-form-group>

                            <b-row>
                                <b-col md="4">
                                    <b-form-group label="Tipo *" label-for="tipo">
                                        <b-form-select id="tipo" v-model="form.tipo" required>
                                            <option value="video">🎬 Video</option>
                                            <option value="documento">📄 Documento</option>
                                            <option value="imagen">🖼️ Imagen</option>
                                            <option value="link">🔗 Enlace</option>
                                        </b-form-select>
                                    </b-form-group>
                                </b-col>
                                <b-col md="4">
                                    <b-form-group label="Orden" label-for="orden">
                                        <b-form-input id="orden" v-model.number="form.orden" type="number"
                                            placeholder="0" />
                                    </b-form-group>
                                </b-col>
                                <b-col md="4">
                                    <b-form-group label="Destacado" label-for="destacado">
                                        <b-form-checkbox id="destacado" v-model="form.destacado" switch>
                                            Marcar como destacado
                                        </b-form-checkbox>
                                    </b-form-group>
                                </b-col>
                            </b-row>

                            <b-row>
                                <b-col md="6">
                                    <b-form-group label="URL (para videos o enlaces)" label-for="url">
                                        <b-form-input id="url" v-model="form.url"
                                            placeholder="https://youtube.com/..." />
                                    </b-form-group>
                                </b-col>
                                <b-col md="6">
                                    <b-form-group label="Archivo (subir)" label-for="archivo">
                                        <b-form-file id="archivo" v-model="form.archivo"
                                            accept=".mp4,.pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png,.gif,.webp" />
                                        <div v-if="isEdit && form.archivo_existente" class="mt-1">
                                            <span class="text-muted small">Archivo actual: {{ form.archivo_existente
                                                }}</span>
                                        </div>
                                    </b-form-group>
                                </b-col>
                            </b-row>

                            <b-form-group label="Miniatura" label-for="miniatura">
                                <b-form-file id="miniatura" v-model="form.miniatura"
                                    accept=".jpg,.jpeg,.png,.gif,.webp" />
                                <div v-if="isEdit && form.miniatura_existente" class="mt-1">
                                    <img :src="urlStorage(form.miniatura_existente)"
                                        style="width: 80px; height: 80px; object-fit: cover; border-radius: 8px;"
                                        alt="Miniatura actual">
                                </div>
                            </b-form-group>

                            <b-form-group label="Estado" label-for="estado">
                                <b-form-radio-group id="estado" v-model="form.estado" :options="estadoOptions" />
                            </b-form-group>

                            <b-row class="mt-3">
                                <b-col>
                                    <b-button type="submit" variant="success" :disabled="cargando">
                                        <span v-if="cargando" class="spinner-border spinner-border-sm me-2"></span>
                                        {{ isEdit ? 'Actualizar' : 'Guardar' }}
                                    </b-button>
                                    <b-button type="button" variant="secondary" class="ms-2"
                                        @click="cancelar">Cancelar</b-button>
                                </b-col>
                            </b-row>
                        </b-form>
                    </b-card-body>
                </b-card>
            </b-col>
        </b-row>
    </DefaultLayout>
</template>

<script setup lang="ts">
import DefaultLayout from '@/layouts/DefaultLayout.vue'
import { ref, onMounted, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import Swal from 'sweetalert2'
import { recursoService } from '@/services/admin/recursoService'

const router = useRouter()
const route = useRoute()
const cargando = ref(false)
const isEdit = computed(() => !!route.params.id)

const form = ref({
    titulo: '',
    descripcion: '',
    tipo: 'video',
    categoria: '',
    url: '',
    archivo: null as File | null,
    archivo_existente: '',
    miniatura: null as File | null,
    miniatura_existente: '',
    orden: 0,
    destacado: false,
    estado: true
})

const estadoOptions = [
    { text: 'Activo', value: true },
    { text: 'Inactivo', value: false }
]

const urlStorage = (path: string) => {
    if (!path) return ''
    if (path.startsWith('http')) return path
    return import.meta.env.VITE_STORAGE_URL + '/' + path
}

const cargarRecurso = async (id: number) => {
    try {
        const data = await recursoService.obtener(id)
        form.value = {
            titulo: data.titulo || '',
            descripcion: data.descripcion || '',
            tipo: data.tipo || 'video',
            categoria: data.categoria || '',
            url: data.url || '',
            archivo: null,
            archivo_existente: data.archivo || '',
            miniatura: null,
            miniatura_existente: data.miniatura || '',
            orden: data.orden || 0,
            destacado: data.destacado || false,
            estado: data.estado !== undefined ? data.estado : true
        }
    } catch (error) {
        Swal.fire('Error', 'No se pudo cargar el recurso.', 'error')
        router.push('/manuales')
    }
}

const guardar = async () => {
    cargando.value = true
    try {
        const formData = new FormData()
        Object.entries(form.value).forEach(([key, val]) => {
            if (val !== null && val !== undefined && val !== '') {
                if (key === 'archivo' || key === 'miniatura') {
                    if (val instanceof File) formData.append(key, val)
                } else if (key === 'destacado' || key === 'estado') {
                    formData.append(key, val ? '1' : '0')
                } else {
                    formData.append(key, String(val))
                }
            }
        })

        let res
        if (isEdit.value) {
            formData.append('_method', 'PUT')
            res = await recursoService.actualizar(Number(route.params.id), formData)
        } else {
            res = await recursoService.crear(formData)
        }
        Swal.fire('¡Éxito!', res.message || 'Recurso guardado correctamente.', 'success')
        router.push('/recursos')
    } catch (error: any) {
        let msg = 'Error al guardar el recurso.'
        if (error.response?.data?.errors) {
            const errs = error.response.data.errors
            msg = Object.values(errs).flat().join('\n')
        }
        Swal.fire('Error', msg, 'error')
    } finally {
        cargando.value = false
    }
}

const cancelar = () => {
    router.push('/recursos')
}

onMounted(() => {
    if (isEdit.value) cargarRecurso(Number(route.params.id))
})
</script>