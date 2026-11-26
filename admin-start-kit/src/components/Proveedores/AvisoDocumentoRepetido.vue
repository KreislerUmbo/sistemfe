<template>
    <div class="alert alert-warning py-2 mb-0">
        <div class="fw-semibold small mb-1">
            Ya existe un proveedor con este documento. ¿Es el mismo u otro establecimiento?
        </div>
        <ul class="list-unstyled mb-2">
            <li v-for="p in existentes" :key="p.id" class="d-flex align-items-center justify-content-between gap-2 py-1 small">
                <span>
                    {{ nombreConCiudad(p.nombre_comercial || p.razon_social, p.ciudad) }}
                    <span v-if="!p.estado" class="badge bg-secondary ms-1">Inactivo</span>
                    <span class="d-block text-muted">{{ p.direccion || p.direccion_fiscal || 'Sin dirección' }}</span>
                </span>
                <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap" @click="emit('usar', p)">
                    Es este
                </button>
            </li>
        </ul>
        <button type="button" class="btn btn-sm btn-warning" :disabled="guardando" @click="emit('crear')">
            Es otro establecimiento: crear
        </button>
    </div>
</template>

<script setup lang="ts">
// T-134 — el RUC no es único: aviso con los que ya existen, sin bloquear.
import { nombreConCiudad } from '@/helpers/proveedor';
import type { ProveedorExistente } from '@/types/cash';

defineProps<{ existentes: ProveedorExistente[]; guardando?: boolean }>();
const emit = defineEmits<{
    (e: 'usar', proveedor: ProveedorExistente): void;
    (e: 'crear'): void;
}>();
</script>
