<template>
    <div v-if="mostrarOpcionalesId === op.id" class="mt-2 border-top pt-2">
        <div v-if="!op.opcionales?.length" class="text-muted small fst-italic mb-2">Sin opcionales cargados todavía — son actividades que el cliente puede agregar aparte; "Agregar al lienzo" recién los suma al total.</div>
        <div v-for="opl in op.opcionales" :key="opl.id" class="border rounded p-2 mb-1">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <strong>{{ opl.nombre }}</strong>
                    <div v-if="opl.incluye" class="text-muted" style="font-size:11px">{{ textoPlano(opl.incluye) }}</div>
                </div>
                <div class="d-flex align-items-start gap-2 text-nowrap">
                    <span>{{ opl.moneda }} {{ Number(opl.precio_por_persona).toFixed(2) }} /pax</span>
                    <template v-if="!alternativaSoloLectura">
                        <i class="fas fa-pen text-muted" style="cursor:pointer;font-size:11px" title="Editar" @click="abrirEdicionOpcional(opl)"></i>
                        <i v-if="eliminandoOpcionalId !== opl.id" class="fas fa-trash text-muted" style="cursor:pointer;font-size:11px" title="Eliminar" @click="eliminarOpcionalCargado(opl)"></i>
                        <span v-else class="spinner-border spinner-border-sm" style="width:11px;height:11px"></span>
                    </template>
                </div>
            </div>
            <!-- Hueco real cerrado (07-sep-2026): hasta ahora un opcional
                 solo era info de referencia en el PDF, sin forma de que el
                 cliente lo elija de verdad y sume al total. Mismo criterio
                 "por persona" que ya usa el hotel de la matriz mayorista. -->
            <button v-if="!alternativaSoloLectura" class="btn btn-sm btn-outline-success w-100 mt-1"
                :disabled="agregandoOpcionalAlLienzoId === opl.id || op.estado !== 'elegida'"
                :title="op.estado !== 'elegida' ? 'Marca esta opción como elegida para poder agregar sus opcionales' : ''"
                @click="agregarOpcionalAlLienzo(op, opl)">
                <span v-if="agregandoOpcionalAlLienzoId === opl.id" class="spinner-border spinner-border-sm me-1"></span>
                <i v-else class="fas fa-plus me-1"></i>Agregar al lienzo
            </button>
            <div v-if="opcionalEnEdicionId === opl.id" class="mt-1 border-top pt-1">
                <input type="text" class="form-control form-control-sm mb-1" placeholder="Nombre" v-model="formEdicionOpcional.nombre">
                <div class="row g-1 mb-1">
                    <div class="col-8">
                        <input type="number" step="0.01" class="form-control form-control-sm" placeholder="Precio por persona" v-model.number="formEdicionOpcional.precio_por_persona">
                    </div>
                    <div class="col-4">
                        <select class="form-select form-select-sm" v-model="formEdicionOpcional.moneda">
                            <option value="USD">USD</option>
                            <option value="PEN">PEN</option>
                        </select>
                    </div>
                </div>
                <div class="mb-1">
                    <RichTextEditor v-model="formEdicionOpcional.incluye" placeholder="Incluye..." />
                </div>
                <div class="mb-1">
                    <RichTextEditor v-model="formEdicionOpcional.no_incluye" placeholder="No incluye..." />
                </div>
                <button class="btn btn-sm btn-primary w-100" @click="guardarEdicionOpcional(opl)" :disabled="guardandoEdicionOpcional">
                    <span v-if="guardandoEdicionOpcional" class="spinner-border spinner-border-sm me-1"></span>Guardar
                </button>
            </div>
        </div>
        <button v-if="!alternativaSoloLectura" class="btn btn-sm btn-outline-secondary w-100 mt-1" @click="mostrarFormOpcional = mostrarFormOpcional === op.id ? null : op.id">
            <i class="fas fa-plus me-1"></i>Agregar opcional
        </button>
        <div v-if="mostrarFormOpcional === op.id && !alternativaSoloLectura" class="border rounded p-2 mt-2">
            <input type="text" class="form-control form-control-sm mb-1" placeholder="Nombre (ej. Isla de Taboga - Full Day)" v-model="formOpcional.nombre">
            <div class="row g-1 mb-1">
                <div class="col-8">
                    <input type="number" step="0.01" class="form-control form-control-sm" placeholder="Precio por persona" v-model.number="formOpcional.precio_por_persona">
                </div>
                <div class="col-4">
                    <select class="form-select form-select-sm" v-model="formOpcional.moneda">
                        <option value="USD">USD</option>
                        <option value="PEN">PEN</option>
                    </select>
                </div>
            </div>
            <div class="mb-1">
                <RichTextEditor v-model="formOpcional.incluye" placeholder="Incluye..." />
            </div>
            <div class="mb-1">
                <RichTextEditor v-model="formOpcional.no_incluye" placeholder="No incluye..." />
            </div>
            <button class="btn btn-sm btn-primary w-100" @click="guardarOpcional(op)" :disabled="guardandoOpcional">
                <span v-if="guardandoOpcional" class="spinner-border spinner-border-sm me-1"></span>Guardar
            </button>
        </div>
    </div>
</template>

<script setup lang="ts">
/*
 * Opcionales de una opción de mayorista: lista, edición, alta y pasar uno al lienzo. Todo llega por
 * inject desde cotizador/editar.vue; por prop, solo la opción del bucle (T-105).
 */
import RichTextEditor from '@/components/RichTextEditor.vue';
import { textoPlano } from '@/helpers/agencia-viajes/cotizador/presentacionItem';
import { CLAVE_NUCLEO, CLAVE_OPCIONALES_MAYORISTA, inyectar } from '@/composables/agencia-viajes/cotizador/claves';
import type { OpcionMayorista } from '@/types/agencia-viajes';

defineProps<{ op: OpcionMayorista }>();
const { alternativaSoloLectura } = inyectar(CLAVE_NUCLEO);
const {
    abrirEdicionOpcional, agregandoOpcionalAlLienzoId, agregarOpcionalAlLienzo, eliminandoOpcionalId,
    eliminarOpcionalCargado, formEdicionOpcional, formOpcional, guardandoEdicionOpcional, guardandoOpcional,
    guardarEdicionOpcional, guardarOpcional, mostrarFormOpcional, mostrarOpcionalesId, opcionalEnEdicionId,
} = inyectar(CLAVE_OPCIONALES_MAYORISTA);
</script>
