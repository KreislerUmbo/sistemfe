<template>
    <!-- CRM C2 (T-051): filas de la bandeja de pendientes (Inicio y Seguimiento). Una acción del CRM
         se marca hecha o se reprograma desde la fila; un pendiente automático (fuente 'operacion',
         fila 11f) solo tiene "Resolver", que lleva a su pantalla. Textos con {{ }}, nunca v-html. -->
    <ul class="list-group list-group-flush small">
        <li v-for="f in filas" :key="f.id" class="list-group-item d-flex justify-content-between align-items-start gap-2 px-0">
            <div class="d-flex gap-2">
                <i class="fas mt-1" :class="[icono(f), f.bloque === 'atrasadas' ? 'text-danger' : f.bloque === 'hoy' ? 'text-warning' : 'text-primary']"></i>
                <div>
                    <div>
                        <span class="fw-semibold">{{ f.titulo }}</span>
                        <template v-if="f.cliente">
                            ·
                            <router-link :to="{ name: 'clients.ficha', params: { id: f.cliente.id } }" class="text-decoration-none">{{ f.cliente.nombre }}</router-link>
                        </template>
                        <span v-if="f.bloque === 'atrasadas'" class="badge bg-danger-subtle text-danger ms-1">Atrasada · {{ formatDiaCorto(f.fecha) }}</span>
                        <span v-else-if="f.bloque === 'proximos'" class="text-muted ms-1">· {{ formatDiaCorto(f.fecha) }}</span>
                    </div>
                    <div v-if="f.descripcion" class="text-break">{{ f.descripcion }}</div>
                    <div class="text-muted">
                        <router-link v-if="f.referencia" :to="enlaceReferencia(f.referencia)" class="text-decoration-none">
                            {{ f.referencia.tipo === 'cotizacion' ? 'Cotización' : 'Reserva' }} {{ f.referencia.codigo ?? `#${f.referencia.id}` }}
                        </router-link>
                        <template v-if="mostrarResponsable && f.responsable">
                            <template v-if="f.referencia"> · </template>{{ f.responsable.nombre }}
                        </template>
                    </div>
                </div>
            </div>
            <span class="text-nowrap">
                <template v-if="f.accion?.puede_gestionar">
                    <button type="button" class="btn btn-outline-success btn-sm py-0 me-1" @click="modalRef?.marcarHecha(f.accion)">
                        <i class="fas fa-check me-1"></i>Hecha
                    </button>
                    <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none text-muted" @click="modalRef?.reprogramar(f.accion)">Reprogramar</button>
                </template>
                <router-link v-else-if="f.enlace" :to="f.enlace" class="btn btn-outline-primary btn-sm py-0">Resolver</router-link>
            </span>
        </li>
    </ul>
    <AccionCrmModal ref="modalRef" @guardado="emit('cambio')" />
</template>

<script setup lang="ts">
import { ref } from 'vue';
import AccionCrmModal from '@/components/Clientes/AccionCrmModal.vue';
import { formatDiaCorto } from '@/helpers/fecha';
import type { FilaBandeja } from '@/services/admin/crmAccionService';

withDefaults(defineProps<{
    filas: FilaBandeja[];
    /** "De todos": se ve de quién es cada pendiente. */
    mostrarResponsable?: boolean;
}>(), { mostrarResponsable: false });

const emit = defineEmits<{ (e: 'cambio'): void }>();

const modalRef = ref<InstanceType<typeof AccionCrmModal> | null>(null);

const ICONOS: Record<string, string> = {
    llamar: 'fa-phone-alt', whatsapp: 'fa-comment', enviar_cotizacion: 'fa-paper-plane',
    cobrar_saldo: 'fa-money-bill-wave', saludar: 'fa-gift', otro: 'fa-tasks',
};
const icono = (f: FilaBandeja) => (f.accion ? ICONOS[f.accion.tipo] ?? 'fa-tasks' : 'fa-bell');

const enlaceReferencia = (r: NonNullable<FilaBandeja['referencia']>) =>
    r.tipo === 'cotizacion'
        ? { name: 'agencia.cotizador.editar', params: { id: r.id } }
        : { name: 'agencia.reservas.detalle', params: { id: r.id } };
</script>
