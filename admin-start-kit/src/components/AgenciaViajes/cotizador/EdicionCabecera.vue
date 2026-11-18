<template>
    <!-- Corregir cliente/destino/fecha (me equivoqué al crear la cotización) -->
    <div v-if="editandoCabecera" class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <div class="row g-2">
                <div class="col-12 col-md-5 position-relative">
                    <label class="form-label mb-1 small fw-semibold text-secondary">Cliente</label>
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control" v-model="clientSearchText"
                            placeholder="Buscar por DNI, RUC o nombre..." @input="onClientSearchInput"
                            @focus="showClientSuggestions = true" @blur="onClientSearchBlur" autocomplete="off">
                        <button v-if="clienteEditado" class="btn btn-outline-primary" type="button" title="Editar datos del cliente" @click="abrirEdicionCliente">
                            <i class="fas fa-pen"></i>
                        </button>
                        <button v-if="clienteEditado" class="btn btn-outline-danger" type="button" @click="limpiarClienteEditado">
                            <i class="fas fa-times"></i>
                        </button>
                        <button v-else class="btn btn-outline-primary" type="button" @click="abrirNuevoCliente" title="Registrar cliente nuevo">
                            <i class="fas fa-user-plus"></i>
                        </button>
                    </div>
                    <div v-if="showClientSuggestions && clientSuggestions.length > 0 && !clienteEditado"
                        class="list-group mt-1 position-absolute"
                        style="max-height:220px;overflow-y:auto;z-index:1050;width:calc(100% - 2px);box-shadow:0 4px 8px rgba(0,0,0,.1)">
                        <button type="button" class="list-group-item list-group-item-action" v-for="c in clientSuggestions" :key="c.id"
                            @mousedown.prevent="seleccionarClienteEditado(c)">
                            <div class="d-flex justify-content-between">
                                <span>{{ c.full_name }}</span>
                                <small class="text-muted">{{ c.n_document }}</small>
                            </div>
                        </button>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label mb-1 small fw-semibold text-secondary">Destino</label>
                    <input type="text" class="form-control form-control-sm" v-model="formCabecera.destino">
                </div>
                <div class="col-6 col-md-3 d-flex align-items-end gap-1">
                    <button class="btn btn-primary btn-sm w-100" @click="guardarCabecera" :disabled="guardandoCabecera">
                        <span v-if="guardandoCabecera" class="spinner-border spinner-border-sm"></span>
                        <span v-else><i class="fas fa-check me-1"></i>Guardar</span>
                    </button>
                    <button class="btn btn-outline-secondary btn-sm" @click="editandoCabecera = false"><i class="fas fa-times"></i></button>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label mb-1 small fw-semibold text-secondary">Fecha desde</label>
                    <CampoFecha v-model="formCabecera.fecha_viaje_desde" />
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label mb-1 small fw-semibold text-secondary">Fecha hasta</label>
                    <CampoFecha v-model="formCabecera.fecha_viaje_hasta" />
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" tabindex="-1" :class="{ show: showQuickClientModal, 'd-block': showQuickClientModal }"
        style="background:rgba(0,0,0,.5)" v-if="showQuickClientModal">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">{{ modoEdicionCliente ? 'Editar Cliente' : 'Registrar Cliente Rápido' }}</h6>
                    <button class="btn-close" @click="showQuickClientModal = false"></button>
                </div>
                <div class="modal-body">
                    <ClientFormQuick :initial-data="modoEdicionCliente ? clienteEditado : null"
                        :cliente-id="modoEdicionCliente ? clienteEditado?.id ?? null : null"
                        @saved="onClientCreated" @cancel="showQuickClientModal = false" />
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
/*
 * Corregir cliente, destino y fechas de la cotización, con el modal de cliente rápido. Todo llega por
 * inject desde cotizador/editar.vue (T-105).
 */
import CampoFecha from '@/components/CampoFecha.vue';
import ClientFormQuick from '@/components/Sales/ClientFormQuick.vue';
import { CLAVE_CABECERA, inyectar } from '@/composables/agencia-viajes/cotizador/claves';

const {
    abrirEdicionCliente, abrirNuevoCliente, clientSearchText, clientSuggestions, clienteEditado,
    editandoCabecera, formCabecera, guardandoCabecera, guardarCabecera, limpiarClienteEditado,
    modoEdicionCliente, onClientCreated, onClientSearchBlur, onClientSearchInput, seleccionarClienteEditado,
    showClientSuggestions, showQuickClientModal,
} = inyectar(CLAVE_CABECERA);
</script>
