<template>
    <DefaultLayout>
        <div v-if="loading" class="text-center py-5">
            <span class="spinner-border"></span>
        </div>

        <!-- ── Apertura de caja ──────────────────────────────────────── -->
        <b-row v-else-if="status && !status.has_open_session" class="justify-content-center">
            <b-col cols="12" md="8" lg="6">
                <b-card class="shadow-sm">
                    <b-card-header>
                        <b-card-title>Apertura de Caja</b-card-title>
                    </b-card-header>
                    <b-card-body>
                        <div v-if="!availableRegisters.length" class="alert alert-warning mb-0">
                            No hay cajas disponibles para abrir. Contacta a un administrador.
                        </div>
                        <template v-else>
                            <div class="mb-3" v-if="availableRegisters.length > 1">
                                <label class="form-label fw-semibold">Caja</label>
                                <b-form-select v-model="selectedRegisterId" @change="onRegisterChange">
                                    <option v-for="r in availableRegisters" :key="r.id" :value="r.id">
                                        {{ r.branch?.name }} — {{ r.name }}
                                    </option>
                                </b-form-select>
                            </div>
                            <div class="mb-3" v-else>
                                <label class="form-label fw-semibold">Caja</label>
                                <div>{{ availableRegisters[0].branch?.name }} — {{ availableRegisters[0].name }}</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Fondo de apertura (S/)</label>
                                <b-form-input type="number" step="0.01" min="0" v-model.number="openingAmount" />
                            </div>

                            <b-button variant="primary" :disabled="opening" @click="abrirCaja">
                                <span v-if="opening" class="spinner-border spinner-border-sm me-1"></span>
                                Abrir caja
                            </b-button>
                        </template>
                    </b-card-body>
                </b-card>
            </b-col>
        </b-row>

        <!-- ── Turno activo ─────────────────────────────────────────── -->
        <b-row v-else-if="status && status.has_open_session && session" class="justify-content-center">
            <b-col cols="12">
                <b-card class="shadow-sm">
                    <b-card-header class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div>
                            <b-card-title class="mb-1">
                                Turno activo — {{ session.cash_register.name }}
                                <small class="text-muted">({{ session.cash_register.branch?.name }})</small>
                            </b-card-title>
                            <small class="text-muted d-block">
                                Cajero: {{ session.opened_by_user.name }} —
                                Apertura: {{ session.opened_at }} —
                                Fondo inicial: S/ {{ Number(session.opening_amount).toFixed(2) }}
                                <span v-if="session.opening_amount_adjusted" class="badge bg-warning text-dark ms-1">
                                    fondo ajustado
                                </span>
                            </small>
                        </div>
                        <b-button variant="danger" @click="abrirModalCierre">
                            <i class="fas fa-lock me-1"></i> Cerrar caja
                        </b-button>
                    </b-card-header>

                    <b-card-body>
                        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                            <h6 class="fw-semibold mb-0">Movimientos de la sesión</h6>
                            <div class="d-flex gap-2">
                                <b-button variant="outline-success" size="sm" @click="abrirModalMovimiento('manual_income')">
                                    <i class="fas fa-arrow-down me-1"></i> Registrar ingreso
                                </b-button>
                                <b-button variant="outline-warning" size="sm" @click="abrirModalMovimiento('manual_expense')">
                                    <i class="fas fa-arrow-up me-1"></i> Registrar egreso
                                </b-button>
                            </div>
                        </div>
                        <b-table-simple responsive small class="mb-4">
                            <b-thead class="table-light">
                                <b-tr>
                                    <b-th>Tipo</b-th>
                                    <b-th>Método</b-th>
                                    <b-th>Dirección</b-th>
                                    <b-th>Monto</b-th>
                                    <b-th>Concepto / Contraparte</b-th>
                                    <b-th>Estado</b-th>
                                    <b-th>Fecha</b-th>
                                    <b-th>Acciones</b-th>
                                </b-tr>
                            </b-thead>
                            <b-tbody>
                                <b-tr v-if="!session.movements.length">
                                    <b-td colspan="8" class="text-center text-muted">Sin movimientos todavía</b-td>
                                </b-tr>
                                <b-tr v-for="m in session.movements" :key="m.id">
                                    <b-td>{{ tipoLabel(m.type) }}</b-td>
                                    <b-td>{{ m.payment_method?.name ?? '—' }}</b-td>
                                    <b-td>
                                        <b-badge :variant="m.direction === 'in' ? 'success' : 'warning'">
                                            {{ m.direction === 'in' ? 'Ingreso' : 'Egreso' }}
                                        </b-badge>
                                    </b-td>
                                    <b-td>S/ {{ Number(m.amount).toFixed(2) }}</b-td>
                                    <b-td>
                                        <div v-if="m.concept">{{ m.concept.name }}</div>
                                        <small class="text-muted d-block" v-if="m.counterparty_name">
                                            {{ m.counterparty_name }}
                                        </small>
                                    </b-td>
                                    <b-td>
                                        <b-badge v-if="m.corrected_by" variant="secondary">Corregido</b-badge>
                                        <b-badge v-else-if="m.status === 'pending_approval'" variant="warning" class="text-dark">
                                            Pendiente aprobación
                                        </b-badge>
                                        <b-badge v-else-if="m.status === 'rejected'" variant="danger">Rechazado</b-badge>
                                        <b-badge v-else variant="success">Confirmado</b-badge>
                                    </b-td>
                                    <b-td>{{ m.created_at }}</b-td>
                                    <b-td>
                                        <div class="d-flex gap-1">
                                            <template v-if="puedeCorregir(m)">
                                                <button type="button" class="btn btn-sm btn-outline-primary" title="Editar"
                                                    @click="abrirModalEdicion(m)">
                                                    <i class="fas fa-pen"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-danger" title="Eliminar"
                                                    @click="eliminarMovimiento(m)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </template>
                                            <template v-if="m.status === 'pending_approval' && puedeAprobar">
                                                <button type="button" class="btn btn-sm btn-outline-success" title="Aprobar"
                                                    @click="aprobarMovimiento(m)">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" title="Rechazar"
                                                    @click="rechazarMovimiento(m)">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </template>
                                        </div>
                                    </b-td>
                                </b-tr>
                            </b-tbody>
                        </b-table-simple>

                        <h6 class="fw-semibold">Totales en vivo por método de pago (corte X)</h6>
                        <b-table-simple responsive small>
                            <b-thead class="table-light">
                                <b-tr><b-th>Método</b-th><b-th>Total</b-th></b-tr>
                            </b-thead>
                            <b-tbody>
                                <b-tr v-if="!session.totals_by_payment_method.length">
                                    <b-td colspan="2" class="text-center text-muted">Sin movimientos todavía</b-td>
                                </b-tr>
                                <b-tr v-for="t in session.totals_by_payment_method" :key="t.payment_method_id">
                                    <b-td>{{ t.payment_method_name }}</b-td>
                                    <b-td>S/ {{ t.total.toFixed(2) }}</b-td>
                                </b-tr>
                            </b-tbody>
                        </b-table-simple>
                    </b-card-body>
                </b-card>
            </b-col>
        </b-row>

        <!-- ── Modal de cierre ──────────────────────────────────────── -->
        <b-modal v-model="showCloseModal" title="Cerrar caja" hide-footer centered size="lg">
            <div v-if="session">
                <p v-if="!session.cash_register.blind_close_resolved">
                    Efectivo esperado: <strong>S/ {{ session.expected_cash_live.toFixed(2) }}</strong>
                </p>
                <p v-else class="text-muted">
                    Cierre ciego: el esperado se muestra recién después de confirmar el conteo.
                </p>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Efectivo contado (S/)</label>
                    <b-form-input type="number" step="0.01" min="0" v-model.number="countedCash" />
                </div>

                <div class="mb-3" v-if="requiereMotivo">
                    <label class="form-label fw-semibold">Motivo de la diferencia (obligatorio)</label>
                    <b-form-textarea v-model="differenceReason" rows="2" />
                    <small class="text-muted">
                        La diferencia estimada (S/ {{ diferenciaEstimada.toFixed(2) }}) supera la tolerancia
                        (S/ {{ (status?.difference_tolerance ?? 0).toFixed(2) }}).
                    </small>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Notas de cierre (opcional)</label>
                    <b-form-textarea v-model="closingNotes" rows="2" />
                </div>

                <div class="mb-3">
                    <b-button variant="outline-secondary" size="sm" @click="showDenominations = !showDenominations">
                        {{ showDenominations ? 'Ocultar' : 'Detalle por denominación (opcional)' }}
                    </b-button>
                    <div v-if="showDenominations" class="mt-2 border rounded p-2">
                        <div v-for="(d, idx) in denominations" :key="idx" class="row g-2 mb-2 align-items-center">
                            <div class="col-4">
                                <b-form-input type="number" step="0.01" min="0" placeholder="Denominación"
                                    v-model.number="d.denomination" />
                            </div>
                            <div class="col-4">
                                <b-form-input type="number" min="0" placeholder="Cantidad" v-model.number="d.quantity" />
                            </div>
                            <div class="col-4">
                                <button type="button" class="btn btn-outline-danger btn-sm" @click="denominations.splice(idx, 1)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                        <b-button size="sm" variant="outline-primary" @click="denominations.push({ denomination: 0, quantity: 0 })">
                            + Agregar denominación
                        </b-button>
                    </div>
                </div>

                <div class="modal-footer">
                    <b-button variant="secondary" @click="showCloseModal = false">Cancelar</b-button>
                    <b-button variant="primary" :disabled="closing" @click="confirmarCierre">
                        <span v-if="closing" class="spinner-border spinner-border-sm me-1"></span>
                        Confirmar cierre
                    </b-button>
                </div>
            </div>
        </b-modal>

        <!-- ── Modal de movimiento manual (crear/editar) ────────────── -->
        <b-modal v-model="showMovementModal" :title="movementModalTitle" hide-footer centered size="lg">
            <div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Método de pago</label>
                    <b-form-select v-model="movementForm.payment_method_id">
                        <option :value="null" disabled>Selecciona un método</option>
                        <option v-for="pm in paymentMethods" :key="pm.id" :value="pm.id">{{ pm.name }}</option>
                    </b-form-select>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Concepto</label>
                    <b-form-select v-model="movementForm.concept_id">
                        <option :value="null" disabled>Selecciona un concepto</option>
                        <option v-for="c in conceptsFiltrados" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </b-form-select>
                    <small class="text-muted" v-if="!conceptsFiltrados.length">
                        No hay conceptos de {{ movementType === 'manual_income' ? 'ingreso' : 'egreso' }} configurados.
                    </small>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Monto (S/)</label>
                    <b-form-input type="number" step="0.01" min="0.01" v-model.number="movementForm.amount" />
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Descripción (obligatoria)</label>
                    <b-form-textarea v-model="movementForm.description" rows="2" />
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold d-block">Contraparte</label>
                    <div class="btn-group mb-2" role="group">
                        <button type="button" class="btn btn-sm"
                            :class="counterpartyMode === 'cliente' ? 'btn-primary' : 'btn-outline-primary'"
                            @click="cambiarModoContraparte('cliente')">Cliente</button>
                        <button type="button" class="btn btn-sm"
                            :class="counterpartyMode === 'proveedor' ? 'btn-primary' : 'btn-outline-primary'"
                            @click="cambiarModoContraparte('proveedor')">Proveedor</button>
                        <button type="button" class="btn btn-sm"
                            :class="counterpartyMode === 'otro' ? 'btn-primary' : 'btn-outline-primary'"
                            @click="cambiarModoContraparte('otro')">Otro</button>
                    </div>

                    <template v-if="counterpartyMode === 'cliente' || counterpartyMode === 'proveedor'">
                        <div v-if="selectedCounterparty" class="border rounded p-2 d-flex justify-content-between align-items-center">
                            <div>
                                <strong>{{ selectedCounterparty.name }}</strong>
                                <small class="text-muted d-block">{{ selectedCounterparty.document ?? 'Sin documento' }}</small>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="quitarContraparte">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div v-else class="position-relative">
                            <input type="text" class="form-control" v-model="counterpartySearch"
                                :placeholder="`Buscar ${counterpartyMode}...`"
                                @input="onCounterpartySearchInput" autocomplete="off">
                            <div v-if="counterpartySuggestions.length" class="list-group mt-1"
                                style="max-height:220px;overflow-y:auto;box-shadow:0 4px 8px rgba(0,0,0,.1)">
                                <button type="button" class="list-group-item list-group-item-action"
                                    v-for="s in counterpartySuggestions" :key="s.id" @mousedown.prevent="seleccionarContraparte(s)">
                                    {{ s.name }} <small class="text-muted">{{ s.document }}</small>
                                </button>
                            </div>
                            <small v-else-if="counterpartySearch.trim().length >= 2" class="text-muted d-block mt-1">
                                Sin resultados — puedes usar "Otro" para escribir el nombre manualmente.
                            </small>
                        </div>
                    </template>
                    <template v-else>
                        <b-form-input v-model="movementForm.counterparty_name" placeholder="Nombre (texto libre)" />
                    </template>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Adjunto (opcional)</label>
                    <input type="file" class="form-control" @change="onAttachmentChange">
                </div>

                <div class="modal-footer">
                    <b-button variant="secondary" @click="showMovementModal = false">Cancelar</b-button>
                    <b-button variant="primary" :disabled="savingMovement" @click="guardarMovimiento">
                        <span v-if="savingMovement" class="spinner-border spinner-border-sm me-1"></span>
                        Guardar
                    </b-button>
                </div>
            </div>
        </b-modal>
    </DefaultLayout>
</template>

<script setup lang="ts">
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import httpClient from '@/helpers/http-client';
import type { AxiosResponse } from 'axios';
import { ref, computed, onMounted } from 'vue';

import Swal from 'sweetalert2/dist/sweetalert2.js';
import { useAuthStore } from '@/stores/auth';
import type {
    CashStatusResponse,
    CashRegister,
    CashSessionDetail,
    CashOpenResponse,
    CashCloseResponse,
    CashMovement,
    CashMovementPayload,
    CashMovementResponse,
    CounterpartySearchResult,
} from '@/types/cash-session';
import type { PaymentMethod, PaymentMethods, CashConcept, CashConcepts } from '@/types/cash';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const authStore = useAuthStore();

const loading = ref(true);
const status = ref<CashStatusResponse | null>(null);

const availableRegisters = computed<CashRegister[]>(() => status.value?.available_registers ?? []);
const session = computed<CashSessionDetail | null>(() => status.value?.session ?? null);

// ── Apertura ─────────────────────────────────────────────────────────
const selectedRegisterId = ref<number | null>(null);
const openingAmount = ref<number>(0);
const opening = ref(false);

const onRegisterChange = () => {
    const register = availableRegisters.value.find(r => r.id === selectedRegisterId.value);
    openingAmount.value = register ? Number(register.default_opening_amount) : 0;
};

const abrirCaja = async () => {
    if (!selectedRegisterId.value) {
        (Swal as TVueSwalInstance).fire('Error', 'Selecciona una caja.', 'error');
        return;
    }
    if (openingAmount.value < 0) {
        (Swal as TVueSwalInstance).fire('Error', 'El fondo de apertura no puede ser negativo.', 'error');
        return;
    }

    opening.value = true;
    try {
        const res: AxiosResponse<CashOpenResponse> = await httpClient.post('cash/open', {
            cash_register_id: selectedRegisterId.value,
            opening_amount: openingAmount.value,
        });
        (Swal as TVueSwalInstance).fire('Listo', res.data.message, 'success');
        await cargarEstado();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo abrir la caja.', 'error');
    } finally {
        opening.value = false;
    }
};

// ── Cierre ───────────────────────────────────────────────────────────
const showCloseModal = ref(false);
const countedCash = ref<number>(0);
const differenceReason = ref('');
const closingNotes = ref('');
const showDenominations = ref(false);
const denominations = ref<{ denomination: number; quantity: number }[]>([]);
const closing = ref(false);

const diferenciaEstimada = computed(() => {
    if (!session.value) return 0;
    return countedCash.value - session.value.expected_cash_live;
});

const requiereMotivo = computed(() => {
    if (!status.value || !session.value) return false;
    // Cierre ciego: no se conoce el esperado todavía en pantalla, así que
    // esta validación de frontend no puede anticiparse — queda a cargo del
    // backend en la respuesta de close(); acá solo se aplica en modo no ciego.
    if (session.value.cash_register.blind_close_resolved) return false;
    return Math.abs(diferenciaEstimada.value) > status.value.difference_tolerance;
});

const abrirModalCierre = () => {
    countedCash.value = 0;
    differenceReason.value = '';
    closingNotes.value = '';
    showDenominations.value = false;
    denominations.value = [];
    showCloseModal.value = true;
};

const confirmarCierre = async () => {
    if (requiereMotivo.value && !differenceReason.value.trim()) {
        (Swal as TVueSwalInstance).fire('Error', 'La diferencia supera la tolerancia — indica un motivo.', 'error');
        return;
    }

    closing.value = true;
    try {
        const res: AxiosResponse<CashCloseResponse> = await httpClient.post('cash/close', {
            counted_cash: countedCash.value,
            difference_reason: differenceReason.value || null,
            closing_notes: closingNotes.value || null,
            denominations: showDenominations.value ? denominations.value : [],
        });

        showCloseModal.value = false;

        const cerrada = res.data.session;
        (Swal as TVueSwalInstance).fire(
            'Caja cerrada',
            `Esperado: S/ ${Number(cerrada.expected_cash).toFixed(2)} — ` +
            `Contado: S/ ${Number(cerrada.counted_cash).toFixed(2)} — ` +
            `Diferencia: S/ ${Number(cerrada.difference).toFixed(2)}`,
            'success'
        );

        await cargarEstado();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo cerrar la caja.', 'error');
    } finally {
        closing.value = false;
    }
};

// ── Movimientos manuales (Módulo Caja — Fase 4) ────────────────────────
const paymentMethods = ref<PaymentMethod[]>([]);
const concepts = ref<CashConcept[]>([]);

const cargarCatalogosMovimiento = async () => {
    try {
        const [pmRes, ccRes]: [AxiosResponse<PaymentMethods>, AxiosResponse<CashConcepts>] = await Promise.all([
            httpClient.get('payment-methods?active=1'),
            httpClient.get('cash-concepts?active=1'),
        ]);
        paymentMethods.value = pmRes.data.payment_methods;
        concepts.value = ccRes.data.cash_concepts;
    } catch (error) {
        console.error(error);
    }
};

const puedeAprobar = computed(() => authStore.isPermitedRoute('cash.approve_expenses'));

const tipoLabel = (type: string) => {
    const labels: Record<string, string> = {
        opening_fund: 'Fondo de apertura',
        sale_payment: 'Pago de venta',
        manual_income: 'Ingreso manual',
        manual_expense: 'Egreso manual',
        correction: 'Corrección',
    };
    return labels[type] ?? type;
};

// Solo movimientos manuales, no ya corregidos, y solo si la sesión sigue
// abierta o el usuario tiene permiso de supervisor sobre sesión cerrada —
// mismo criterio que el backend (evita mostrar un botón que va a fallar
// con 403 al hacer clic).
const puedeCorregir = (m: CashMovement) => {
    if (!['manual_income', 'manual_expense'].includes(m.type)) return false;
    if (m.corrected_by) return false;
    if (session.value?.status === 'open') return true;
    return authStore.isPermitedRoute('cash.close_others_session');
};

const showMovementModal = ref(false);
const movementType = ref<'manual_income' | 'manual_expense'>('manual_income');
const editingMovementId = ref<number | null>(null);
const savingMovement = ref(false);
const attachmentFile = ref<File | null>(null);

const movementForm = ref<CashMovementPayload>({
    payment_method_id: null as unknown as number,
    amount: 0,
    concept_id: null as unknown as number,
    description: '',
    counterparty_type: null,
    counterparty_id: null,
    counterparty_name: null,
    counterparty_document: null,
});

const counterpartyMode = ref<'cliente' | 'proveedor' | 'otro'>('otro');
const counterpartySearch = ref('');
const counterpartySuggestions = ref<CounterpartySearchResult[]>([]);
const selectedCounterparty = ref<CounterpartySearchResult | null>(null);
let counterpartyTimeout: ReturnType<typeof setTimeout> | undefined;

const conceptsFiltrados = computed(() => {
    const direction = movementType.value === 'manual_income' ? 'in' : 'out';
    return concepts.value.filter((c) => c.direction === direction);
});

const movementModalTitle = computed(() => {
    const accion = editingMovementId.value ? 'Editar' : 'Registrar';
    const tipo = movementType.value === 'manual_income' ? 'ingreso' : 'egreso';
    return `${accion} ${tipo} manual`;
});

const resetMovementForm = () => {
    movementForm.value = {
        payment_method_id: null as unknown as number,
        amount: 0,
        concept_id: null as unknown as number,
        description: '',
        counterparty_type: null,
        counterparty_id: null,
        counterparty_name: null,
        counterparty_document: null,
    };
    counterpartyMode.value = 'otro';
    counterpartySearch.value = '';
    counterpartySuggestions.value = [];
    selectedCounterparty.value = null;
    attachmentFile.value = null;
};

const abrirModalMovimiento = (type: 'manual_income' | 'manual_expense') => {
    resetMovementForm();
    movementType.value = type;
    editingMovementId.value = null;
    showMovementModal.value = true;
};

const abrirModalEdicion = (m: CashMovement) => {
    resetMovementForm();
    movementType.value = m.type as 'manual_income' | 'manual_expense';
    editingMovementId.value = m.id;
    movementForm.value = {
        payment_method_id: m.payment_method_id,
        amount: Number(m.amount),
        concept_id: m.concept_id as number,
        description: m.description ?? '',
        counterparty_type: m.counterparty_type as CashMovementPayload['counterparty_type'],
        counterparty_id: m.counterparty_id,
        counterparty_name: m.counterparty_name,
        counterparty_document: m.counterparty_document,
    };
    if (m.counterparty_id && (m.counterparty_type === 'cliente' || m.counterparty_type === 'proveedor')) {
        counterpartyMode.value = m.counterparty_type;
        selectedCounterparty.value = {
            id: m.counterparty_id,
            name: m.counterparty_name ?? '',
            document: m.counterparty_document,
        };
    } else {
        counterpartyMode.value = 'otro';
    }
    showMovementModal.value = true;
};

const cambiarModoContraparte = (mode: 'cliente' | 'proveedor' | 'otro') => {
    counterpartyMode.value = mode;
    selectedCounterparty.value = null;
    counterpartySearch.value = '';
    counterpartySuggestions.value = [];
    movementForm.value.counterparty_name = null;
};

const onCounterpartySearchInput = () => {
    clearTimeout(counterpartyTimeout);
    const q = counterpartySearch.value.trim();
    if (q.length < 2) {
        counterpartySuggestions.value = [];
        return;
    }
    counterpartyTimeout = setTimeout(async () => {
        try {
            const { data } = await httpClient.get('cash/counterparty-search', {
                params: { type: counterpartyMode.value, q },
            });
            counterpartySuggestions.value = data.results;
        } catch (error) {
            counterpartySuggestions.value = [];
        }
    }, 300);
};

const seleccionarContraparte = (s: CounterpartySearchResult) => {
    selectedCounterparty.value = s;
    counterpartySuggestions.value = [];
    counterpartySearch.value = '';
};

const quitarContraparte = () => {
    selectedCounterparty.value = null;
};

const onAttachmentChange = (event: Event) => {
    const target = event.target as HTMLInputElement;
    attachmentFile.value = target.files?.[0] ?? null;
};

const guardarMovimiento = async () => {
    if (!movementForm.value.payment_method_id) {
        (Swal as TVueSwalInstance).fire('Error', 'Selecciona un método de pago.', 'error');
        return;
    }
    if (!movementForm.value.concept_id) {
        (Swal as TVueSwalInstance).fire('Error', 'Selecciona un concepto.', 'error');
        return;
    }
    if (!movementForm.value.description?.trim()) {
        (Swal as TVueSwalInstance).fire('Error', 'La descripción es obligatoria.', 'error');
        return;
    }
    if (!movementForm.value.amount || movementForm.value.amount <= 0) {
        (Swal as TVueSwalInstance).fire('Error', 'El monto debe ser mayor a 0.', 'error');
        return;
    }

    const formData = new FormData();
    formData.append('payment_method_id', String(movementForm.value.payment_method_id));
    formData.append('amount', String(movementForm.value.amount));
    formData.append('concept_id', String(movementForm.value.concept_id));
    formData.append('description', movementForm.value.description);

    if (counterpartyMode.value === 'otro') {
        if (movementForm.value.counterparty_name) {
            formData.append('counterparty_type', 'otro');
            formData.append('counterparty_name', movementForm.value.counterparty_name);
        }
    } else if (selectedCounterparty.value) {
        formData.append('counterparty_type', counterpartyMode.value);
        formData.append('counterparty_id', String(selectedCounterparty.value.id));
    }

    if (!editingMovementId.value) {
        formData.append('type', movementType.value);
    }
    if (attachmentFile.value) {
        formData.append('attachment', attachmentFile.value);
    }

    savingMovement.value = true;
    try {
        const url = editingMovementId.value ? `cash/movements/${editingMovementId.value}` : 'cash/movements';
        const method = editingMovementId.value ? 'put' : 'post';
        // PUT con FormData no es soportado de forma confiable por todos los
        // backends de PHP (parseo de multipart en PUT) — se fuerza el
        // método real vía _method y se envía como POST, patrón estándar de
        // Laravel para form-data en updates.
        if (method === 'put') {
            formData.append('_method', 'PUT');
        }
        const res: AxiosResponse<CashMovementResponse> = await httpClient.post(url, formData);

        showMovementModal.value = false;
        (Swal as TVueSwalInstance).fire('Listo', res.data.message, 'success');
        await cargarEstado();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo guardar el movimiento.', 'error');
    } finally {
        savingMovement.value = false;
    }
};

const eliminarMovimiento = async (m: CashMovement) => {
    const confirm = await (Swal as TVueSwalInstance).fire({
        title: '¿Eliminar movimiento?',
        text: 'Se generará una corrección que anula este movimiento — no se borra el registro original.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar',
    });
    if (!confirm.isConfirmed) return;

    try {
        const res: AxiosResponse<{ code: number; message: string }> = await httpClient.delete(`cash/movements/${m.id}`);
        (Swal as TVueSwalInstance).fire('Listo', res.data.message, 'success');
        await cargarEstado();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo eliminar el movimiento.', 'error');
    }
};

const aprobarMovimiento = async (m: CashMovement) => {
    try {
        const res: AxiosResponse<CashMovementResponse> = await httpClient.post(`cash/movements/${m.id}/approve`);
        (Swal as TVueSwalInstance).fire('Listo', res.data.message, 'success');
        await cargarEstado();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo aprobar el egreso.', 'error');
    }
};

const rechazarMovimiento = async (m: CashMovement) => {
    try {
        const res: AxiosResponse<CashMovementResponse> = await httpClient.post(`cash/movements/${m.id}/reject`);
        (Swal as TVueSwalInstance).fire('Listo', res.data.message, 'success');
        await cargarEstado();
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo rechazar el egreso.', 'error');
    }
};

// ── Carga de estado ──────────────────────────────────────────────────
const cargarEstado = async () => {
    try {
        const res: AxiosResponse<CashStatusResponse> = await httpClient.get('cash/status');
        status.value = res.data;

        if (!res.data.has_open_session && res.data.available_registers?.length) {
            selectedRegisterId.value = res.data.available_registers[0].id;
            onRegisterChange();
        }
    } catch (error) {
        console.error(error);
    } finally {
        loading.value = false;
    }
};

onMounted(() => {
    cargarEstado();
    cargarCatalogosMovimiento();
});
</script>
