<template>
    <div class="d-flex flex-wrap gap-2">
        <div v-for="(foto, i) in fotos" :key="foto.path" :style="{ width: `${tamano}px` }">
            <div class="position-relative">
                <img :src="foto.url" class="img-thumbnail" :style="{ width: `${tamano}px`, height: `${tamano}px`, objectFit: 'cover', cursor: 'zoom-in' }" @click="emit('ver', fotos.map((f) => f.url), i)">
                <span v-if="foto.tipo_foto === 'fachada'" class="badge bg-primary position-absolute top-0 start-0 m-1">
                    <i class="fas fa-building me-1"></i>Fachada
                </span>
                <button type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0 m-1" title="Borrar foto" :disabled="ocupado" @click="borrar(foto)">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
            <select v-if="conTipo" class="form-select form-select-sm mt-1" :value="foto.tipo_foto" :disabled="ocupado" @change="cambiarTipo(foto, ($event.target as HTMLSelectElement).value as TipoFoto)">
                <option value="fachada">Fachada</option>
                <option value="habitacion">Habitación</option>
                <option value="otra">Otra</option>
            </select>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { proveedorService } from '@/services/admin/proveedorService';
import type { FotoProveedor } from '@/types/agencia-viajes';

// T-020 — galería de un proveedor con tipo de foto (solo alojamiento) y borrado. Cada
// cambio se guarda al instante en el servidor (no espera al "Guardar" del formulario) y
// avisa con `actualizado` con la lista nueva de fotos.
type TipoFoto = FotoProveedor['tipo_foto'];
type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const props = withDefaults(defineProps<{
    proveedorId: number;
    fotos: FotoProveedor[];
    conTipo?: boolean;
    tamano?: number;
}>(), { conTipo: false, tamano: 110 });

const emit = defineEmits<{
    (e: 'actualizado', fotos: FotoProveedor[]): void;
    (e: 'ver', urls: string[], indice: number): void;
}>();

const ocupado = ref(false);

const cambiarTipo = async (foto: FotoProveedor, tipo: TipoFoto) => {
    if (tipo === foto.tipo_foto) return;
    ocupado.value = true;
    try {
        const res = await proveedorService.actualizarTipoFoto(props.proveedorId, foto.path, tipo);
        emit('actualizado', res.proveedor.fotos ?? []);
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo cambiar el tipo de foto', 'error');
        // El selector quedó en el valor nuevo: se vuelve a pintar con la lista que ya teníamos.
        emit('actualizado', [...props.fotos]);
    } finally {
        ocupado.value = false;
    }
};

const borrar = async (foto: FotoProveedor) => {
    const result = await (Swal as TVueSwalInstance).fire({
        title: 'Borrar foto',
        text: 'Se borra ahora mismo, sin esperar a guardar el formulario. ¿Continuar?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, borrar',
    });
    if (!result.isConfirmed) return;

    ocupado.value = true;
    try {
        const res = await proveedorService.eliminarFoto(props.proveedorId, foto.path);
        emit('actualizado', res.proveedor.fotos ?? []);
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo borrar la foto', 'error');
    } finally {
        ocupado.value = false;
    }
};
</script>
