<template>
    <div class="container-fluid py-4">

        <div class="mb-4">
            <small class="text-danger fw-bold text-uppercase">
                Mi Cuenta
            </small>

            <h2 class="text-white fw-bold">
                Datos Personales
            </h2>
        </div>

        <!-- Perfil -->
        <div class="card profile-card mb-4">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center">
                    <div class="avatar-circle me-3">
                        {{ inicial }}
                    </div>

                    <div>
                        <h4 class="mb-1 text-white">
                            {{ form.name }} {{ form.surname }}
                        </h4>
                        <small class="text-secondary">
                            Cliente desde:
                            {{ fechaRegistro }}
                        </small>
                    </div>

                </div>

                <button class="btn btn-outline-danger">
                    <i class="fas fa-camera me-2"></i>
                    Cambiar Foto
                </button>

            </div>

        </div>

        <!-- Formulario -->
        <div class="card profile-card">

            <div class="card-header bg-transparent">

                <h5 class="text-white mb-0">
                    <i class="fas fa-user me-2"></i>
                    Información Personal
                </h5>

            </div>

            <div class="card-body">

                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label text-secondary">
                            Nombres
                        </label>

                        <input v-model="form.name" class="form-control form-dark">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label text-secondary">
                            Apellidos
                        </label>

                        <input v-model="form.surname" class="form-control form-dark">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label text-secondary">
                            Correo Electrónico
                        </label>

                        <input v-model="form.email" class="form-control form-dark">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label text-secondary">
                            Teléfono
                        </label>

                        <input v-model="form.phone" class="form-control form-dark">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label text-secondary">
                            Tipo Documento
                        </label>

                        <select v-model="form.type_document" class="form-select form-dark">
                            <option>DNI</option>
                            <option>RUC</option>
                            <option>PASAPORTE</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label text-secondary">
                            Número Documento
                        </label>

                        <input v-model="form.n_document" class="form-control form-dark">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label text-secondary">
                            Dirección
                        </label>

                        <input v-model="form.address" class="form-control form-dark">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label text-secondary">
                            Departamento
                        </label>

                        <input v-model="form.region" class="form-control form-dark">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label text-secondary">
                            Provincia
                        </label>

                        <input v-model="form.provincia" class="form-control form-dark">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label text-secondary">
                            Distrito
                        </label>

                        <input v-model="form.distrito" class="form-control form-dark">
                    </div>

                </div>

                <div class="mt-4">

                    <button class="btn btn-danger px-4" @click="guardar">
                        Guardar Cambios
                    </button>

                </div>

            </div>

        </div>

    </div>
</template>
<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import Swal from 'sweetalert2'
import publicHttpClient from '@/helpers/publicHttpClient'
import { useClientAuthStore } from '@/stores/clientAuth'


const clientAuth = useClientAuthStore()
const loading = ref(false)
const saving = ref(false)

const form = ref({
    name: '',
    surname: '',
    email: '',
    phone: '',
    type_document: 'DNI',
    n_document: '',
    address: '',
    region: '',
    provincia: '',
    distrito: ''
})

const inicial = computed(() => {
    if (!form.value.name) return 'U'
    return form.value.name.substring(0, 1).toUpperCase()
})

const fechaRegistro = computed(() => {
    if (!clientAuth.client?.created_at) return ''

    return new Date(
        clientAuth.client.created_at
    ).toLocaleDateString('es-PE')
})
const cargarPerfil = async () => {
    loading.value = true

    try {

        const response = await publicHttpClient.get(
            '/portal/profile'
        )

        form.value = {
            name: response.data.client.name || '',
            surname: response.data.client.surname || '',
            email: response.data.client.email || '',
            phone: response.data.client.phone || '',
            type_document: response.data.client.type_document || 'DNI',
            n_document: response.data.client.n_document || '',
            address: response.data.client.address || '',
            region: response.data.client.region || '',
            provincia: response.data.client.provincia || '',
            distrito: response.data.client.distrito || ''
        }

    } catch (error) {

        console.error(error)

        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'No se pudo cargar la información'
        })

    } finally {

        loading.value = false

    }
}

const guardar = async () => {
    saving.value = true
    try {
        const response = await publicHttpClient.put(
            '/portal/profile',
            form.value
        )
        if (response.data.success) {
            // actualizar store local
            clientAuth.client = {
                ...clientAuth.client,
                ...form.value
            }

            Swal.fire({
                icon: 'success',
                title: 'Datos actualizados',
                text: 'La información fue actualizada correctamente'
            })
        }

    } catch (error: any) {
        console.error(error)
        if (error.response?.data?.errors) {
            let mensaje = ''
            Object.keys(error.response.data.errors).forEach(key => {
                mensaje += `${error.response.data.errors[key][0]}\n`
            })

            Swal.fire({
                icon: 'warning',
                title: 'Validación',
                text: mensaje
            })

        } else {

            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'No se pudo actualizar la información'
            })
        }
    } finally {

        saving.value = false

    }
}


onMounted(() => {
    cargarPerfil()
})
</script>


<style scoped>
.profile-card {
    background: #0f0f0f;
    border: 1px solid #242424;
    border-radius: 18px;
}

.avatar-circle {
    width: 70px;
    height: 70px;
    border-radius: 50%;
    background: #dc3545;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 30px;
    font-weight: 700;
}

.form-dark {
    background: #171717;
    border: 1px solid #2b2b2b;
    color: white;
}

.form-dark:focus {
    background: #171717;
    color: white;
    border-color: #dc3545;
    box-shadow: none;
}
</style>