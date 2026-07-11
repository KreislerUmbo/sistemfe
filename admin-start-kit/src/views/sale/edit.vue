<template>
    <DefaultLayout>

        <!-- ═══════════════════════════════════════════════════
             TOPBAR — Tipo de transacción
        ═══════════════════════════════════════════════════ -->
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-cash-register me-2 text-primary"></i>
                    Editar {{ state_sale == 1 ? 'Venta' : 'Cotización' }}
                </h5>
                <small class="text-muted">Completa los pasos — los totales se actualizan en tiempo real</small>
            </div>
            <div class="btn-group shadow-sm" role="group">
                <input type="radio" class="btn-check" name="state_sale" id="btn-venta" value="1"
                    :checked="state_sale == 1" @click="state_sale = 1" autocomplete="off">
                <label class="btn btn-outline-primary px-4 fw-semibold" for="btn-venta">
                    <i class="fas fa-cart-plus me-2"></i>Venta
                </label>
                <input type="radio" class="btn-check" name="state_sale" id="btn-cotizacion" value="2"
                    :checked="state_sale == 2" @click="state_sale = 2" autocomplete="off">
                <label class="btn btn-outline-primary px-4 fw-semibold" for="btn-cotizacion">
                    <i class="fas fa-file-alt me-2"></i>Cotización
                </label>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════
             PASO 1 — DATOS GENERALES
        ═══════════════════════════════════════════════════ -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
                <span class="badge bg-primary rounded-pill">1</span>
                <span class="fw-semibold text-dark">Datos Generales</span>
            </div>
            <div class="card-body py-3">
                <div class="row g-3 align-items-end">

                    <!-- ── Condiciones especiales de venta ─────────────────
                         Selector simple que despliega solo lo necesario.
                         Reemplaza los 4 botones de retención/detracción/percepción/anticipo
                    ───────────────────────────────────────────────────── -->
                    <div class="col-6 col-md-4" v-if="is_exportacion == 0">
                        <label class="form-label mb-1 small fw-semibold text-secondary">
                            <i class="fas fa-sliders-h me-1"></i>Condiciones Especiales
                        </label>
                        <div>
                            <select v-model="condicion_especial" class="form-select form-select-sm">
                                <option value="0">— Ninguna (venta normal) —</option>
                                <option value="anticipo">Anticipo (comprobante previo)</option>
                                <option value="exportacion">Exportación (0% IGV)</option>
                                <option value="1">Retención IGV 3% (R.S. 037-2002)</option>
                                <option value="2">Detracción SPOT (R.S. 183-2004)</option>
                                <option value="3">Percepción (R.S. 058-2006)</option>
                            </select>
                        </div> <small class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>Retenciones, detracciones, percepciones y anticipos
                            ...
                        </small>
                    </div>
                    <div class="col-3 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">
                            <i class="fas fa-sliders-h me-1"></i>Moneda
                        </label>
                        <select v-model="currency" class="form-select form-select-sm">
                            <option value="PEN">—Soles —</option>
                            <option value="USD">Dolares</option>
                        </select>
                        <small><i class="fas fa-money-bill me-1"></i>Moneda de venta </small>
                    </div>

                    <!-- Destino (Ley 27037) -->
                    <div class="col-3 col-md-3" v-if="condicion_especial !== 'exportacion'">
                        <label class="form-label mb-1 small fw-semibold text-secondary">
                            <i class="fas fa-map-marker-alt me-1"></i>Destino
                        </label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="destino-c" id="dest-amazonia" value="amazonia"
                                :checked="destino == 'amazonia'" @click="destino = 'amazonia'" autocomplete="off">
                            <label class="btn btn-outline-success btn-sm" for="dest-amazonia">
                                <i class="fas fa-tree me-1"></i>Amazonía
                            </label>
                            <input type="radio" class="btn-check" name="destino-c" id="dest-nacional" value="nacional"
                                :checked="destino == 'nacional'" @click="destino = 'nacional'" autocomplete="off">
                            <label class="btn btn-outline-secondary btn-sm" for="dest-nacional">
                                <i class="fas fa-truck me-1"></i>Fuera
                            </label>
                        </div>
                        <small class="text-muted">
                            {{ destino == 'amazonia' ? 'Exonerado IGV (Ley 27037)' : 'Gravado IGV normal' }}
                        </small>
                    </div>


                    <!-- ── CLIENTE con búsqueda inteligente ─────────────────── -->
                    <div class="row g-3 align-items-end mt-1">
                        <div class="col-12 col-md-8 position-relative">
                            <label for="client-search" class="form-label mb-1 small fw-semibold text-secondary">
                                <i class="fas fa-user me-1"></i>Cliente
                                <span v-if="client_selected" class="badge ms-1 fw-semibold" :class="client_selected.type_document === 'RUC'
                                    ? 'bg-info-subtle text-info border border-info-subtle'
                                    : 'bg-success-subtle text-success border border-success-subtle'">
                                    {{ client_selected.type_document === 'RUC' ? 'RUC → Factura' : 'DNI → Boleta' }}
                                </span>
                            </label>
                            <div class="input-group input-group-sm">
                                <input type="text" id="client-search" class="form-control" v-model="clientSearchText"
                                    placeholder="Buscar por DNI, RUC, nombre o teléfono..." @input="onClientSearchInput"
                                    @keydown.enter="searchClientByDocument" @focus="onClientSearchFocus"
                                    @blur="onClientSearchBlur" autocomplete="off" />
                                <button class="btn btn-outline-primary" type="button" @click="searchClientByDocument"
                                    title="Buscar por DNI/RUC">
                                    <i class="fas fa-search"></i>
                                </button>
                                <button v-if="client_selected" class="btn btn-outline-danger" type="button"
                                    @click="clearClientSelection" title="Quitar cliente">
                                    <i class="fas fa-times"></i>
                                </button>
                                <button class="btn btn-success" type="button" @click="openQuickClientModal"
                                    title="Registrar nuevo cliente">
                                    <i class="fas fa-user-plus"></i>
                                </button>
                            </div>
                            <!-- Sugerencias -->
                            <div v-if="clientSuggestions.length > 0 && !client_selected"
                                class="list-group mt-1 position-absolute"
                                style="max-height:220px;overflow-y:auto;z-index:1050;width:calc(100% - 2px);box-shadow:0 4px 8px rgba(0,0,0,.1)">
                                <button type="button" class="list-group-item list-group-item-action"
                                    v-for="client in clientSuggestions" :key="client.id"
                                    @mousedown.prevent="selectClient(client)">
                                    <div class="d-flex justify-content-between">
                                        <span>{{ client.full_name }}</span>
                                        <small class="text-muted">{{ client.n_document }}</small>
                                    </div>
                                    <small class="text-muted">{{ client.distrito }}, {{ client.region }}</small>
                                </button>
                            </div>
                            <div v-if="clientNotFound && !client_selected && clientSearchText.length > 2"
                                class="text-danger small mt-1">
                                <i class="fas fa-exclamation-circle me-1"></i>
                                No se encontró — <a href="#" @click.prevent="openQuickClientModal">regístralo
                                    ahora</a>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="client-address" class="form-label mb-1 small fw-semibold text-secondary">
                                <i class="fas fa-map-marker-alt me-1"></i>Dirección
                            </label>
                            <b-form-input type="text" id="client-address" v-model="client_address"
                                placeholder="Dirección del cliente" size="sm" />
                        </div>
                    </div>


                    <!-- Comprobante — autocompletado según cliente -->
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">
                            <i class="fas fa-file-invoice me-1"></i>Comprobante
                        </label>
                        <select v-model="serie" class="form-select form-select-sm">
                            <option value="F001">F001 — Factura</option>
                            <option value="B001">B001 — Boleta</option>
                        </select>
                        <small class="text-muted" v-if="client_selected">
                            Auto según cliente
                        </small>
                    </div>

                    <!-- N° Documento interno -->
                    <div class="col-6 col-md-2">
                        <label for="n-transaction" class="form-label mb-1 small fw-semibold text-secondary">
                            N° Documento
                        </label>
                        <b-form-input type="text" id="n-transaction" v-model="n_transaction" placeholder="000000"
                            size="sm" />
                    </div>

                    <!-- Fecha de emisión -->
                    <div class="col-6 col-md-2">
                        <label for="n-f-emision" class="form-label mb-1 small fw-semibold text-secondary">
                            Fecha Emisión
                        </label>
                        <b-form-input type="date" id="n-f-emision" v-model="today" size="sm" />
                    </div>

                    <!-- Exportación
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">
                            <i class="fas fa-globe me-1"></i>Exportación
                        </label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="is_exportacion-c" id="exp-no" value="0"
                                :checked="is_exportacion == 0" @click="is_exportacion = 0" autocomplete="off">
                            <label class="btn btn-outline-secondary btn-sm" for="exp-no">No</label>
                            <input type="radio" class="btn-check" name="is_exportacion-c" id="exp-si" value="1"
                                :checked="is_exportacion == 1" @click="is_exportacion = 1" autocomplete="off">
                            <label class="btn btn-outline-warning btn-sm" for="exp-si">Sí</label>
                        </div>
                    </div> -->



                    <!-- Campos que aparecen según condición seleccionada -->

                    <!-- Anticipo -->
                    <div class="col-12 col-md-4" v-if="condicion_especial === 'anticipo'">
                        <label class="form-label mb-1 small fw-semibold text-secondary">
                            <i class="fas fa-receipt me-1"></i>N° Comprobante de Anticipo
                        </label>
                        <div class="input-group input-group-sm">
                            <b-form-input type="text" v-model="n_comprobante_anticipo" @keyup.enter="searchAnticipo()"
                                placeholder="Ej: F001-00000123" :disabled="sale_anticipo > 0" size="sm" />
                            <button v-if="sale_anticipo > 0" type="button" class="btn btn-outline-danger btn-sm"
                                @click="resetAnticipo()">
                                <i class="fas fa-times"></i>
                            </button>
                            <button v-else type="button" class="btn btn-outline-primary btn-sm"
                                @click="searchAnticipo()">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Detracción — selector de código del Anexo SUNAT -->
                    <div class="col-12 col-md-5" v-if="condicion_especial == '2'">
                        <label class="form-label mb-1 small fw-semibold text-secondary">
                            <i class="fas fa-list-ol me-1"></i>Código de Detracción (Anexo SUNAT)
                        </label>
                        <select v-model="codigo_detraccion_sel" class="form-select form-select-sm"
                            @change="onDetractionCodeChange">
                            <option value="">— Seleccionar código —</option>
                            <option v-for="d in codigos_detraccion" :key="d.codigo" :value="d.codigo">
                                [{{ d.codigo }}] {{ d.nombre }} ({{ Number(d.tasa_porcentaje).toFixed(1) }}%)
                            </option>
                        </select>
                        <small class="text-muted" v-if="porcentaje_detraccion_sel > 0">
                            <i class="fas fa-info-circle me-1"></i>
                            Monto mínimo para aplicar detracción:
                            S/ {{codigos_detraccion.find(d => d.codigo === codigo_detraccion_sel)?.monto_minimo ??
                                700
                            }}
                        </small>
                    </div>

                </div>


            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════
             PASO 2 — BUSCAR PRODUCTO
        ═══════════════════════════════════════════════════ -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
                <span class="badge bg-success rounded-pill">2</span>
                <span class="fw-semibold text-dark">Buscar Producto</span>
                <span class="badge bg-light text-secondary border ms-auto">{{ sale_details.length }} ítem(s)</span>
            </div>
            <div class="card-body py-3">
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-md-6 position-relative">
                        <label for="product-search" class="form-label mb-1 small fw-semibold text-secondary">
                            <i class="fas fa-box me-1"></i>Producto / Servicio
                        </label>
                        <div class="input-group input-group-sm">
                            <input type="text" id="product-search" class="form-control" v-model="productSearchText"
                                placeholder="Buscar por nombre o SKU..." @input="onProductSearchInput"
                                @focus="onProductSearchFocus" @blur="onProductSearchBlur" autocomplete="off" />
                            <button class="btn btn-success" type="button" @click="openQuickProductModal"
                                title="Nuevo producto">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                        <div v-if="productSuggestions.length > 0 && !productSelected"
                            class="list-group mt-1 position-absolute"
                            style="max-height:220px;overflow-y:auto;z-index:1050;width:100%;box-shadow:0 4px 8px rgba(0,0,0,.1)">
                            <button type="button" class="list-group-item list-group-item-action"
                                v-for="product in productSuggestions" :key="product.id"
                                @mousedown.prevent="selectAndAddProduct(product)">
                                <div class="d-flex justify-content-between">
                                    <span>{{ product.title }}</span>
                                    <small :style="{ color: product.stock <= 5 ? 'red' : '#6b7280' }">
                                        stock: {{ product.stock }}
                                    </small>
                                </div>
                                <small class="text-muted">{{ product.sku }} | {{ currency }} {{
                                    product.price_general
                                    }}</small>
                            </button>
                        </div>
                        <div v-if="productNotFound && !productSelected && productSearchText.length > 2"
                            class="text-danger small mt-1">
                            <i class="fas fa-exclamation-circle me-1"></i>
                            No encontrado — <a href="#" @click.prevent="openQuickProductModal">regístralo ahora</a>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Cantidad por defecto</label>
                        <b-form-input type="number" v-model.number="default_quantity" min="1" size="sm" />
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Precio por defecto</label>
                        <b-input-group size="sm">
                            <b-input-group-text>{{ currency }}</b-input-group-text>
                            <b-form-input type="number" v-model.number="default_price" step="0.01" size="sm" />
                        </b-input-group>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════
             PASO 3 — DETALLE DE LA VENTA
        ═══════════════════════════════════════════════════ -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
                <span class="badge bg-warning text-dark rounded-pill">3</span>
                <span class="fw-semibold text-dark">Detalle de {{ state_sale == 1 ? 'Venta' : 'Cotización' }}</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0 align-middle">
                        <thead class="table-light">
                            <tr class="small text-secondary text-uppercase">
                                <th class="ps-3">#</th>
                                <th>Producto</th>
                                <th style="min-width:90px">Cant.</th>
                                <th style="min-width:110px">P. Base</th>
                                <th class="text-center">Desc.%</th>
                                <th class="text-end">IGV</th>
                                <th class="text-end">Subtotal</th>
                                <th class="text-center">Afect.</th>
                                <th class="pe-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="sale_details.length === 0">
                                <td colspan="9" class="text-center text-muted py-4 fst-italic">
                                    <i class="fas fa-box-open me-2 opacity-50"></i>
                                    Sin productos — busca uno en el paso 2
                                </td>
                            </tr>
                            <tr v-for="(item, index) in sale_details" :key="index">
                                <td class="ps-3 text-muted small">{{ index + 1 }}</td>
                                <td>
                                    <span class="fw-semibold">{{ item.product.title }}</span>
                                    <br>
                                    <small class="text-muted">{{ item.unidad_medida }}</small>
                                </td>
                                <!-- Cantidad editable -->
                                <td>
                                    <b-form-input type="number" v-model.number="item.quantity" min="1" size="sm"
                                        style="width:80px" @input="updateItem(index)" />
                                </td>
                                <!-- Precio base editable -->
                                <td>
                                    <div class="input-group input-group-sm" style="width:110px">
                                        <span class="input-group-text text-muted px-1 small">{{ currency }}</span>
                                        <b-form-input type="number" v-model.number="item.price_base" step="0.01"
                                            size="sm" @input="updateItem(index)" />
                                    </div>
                                </td>
                                <!-- Descuento % editable -->
                                <td class="text-center">
                                    <b-form-input type="number" v-model.number="item.discount_percent" min="0"
                                        :max="item.product.max_discount || 0" size="sm" style="width:70px"
                                        @input="updateItem(index)" :disabled="item.product.is_discount != 2" />
                                </td>
                                <td class="text-end small text-muted">{{ currency }} {{ item.igv.toFixed(2) }}</td>
                                <td class="text-end fw-bold">{{ currency }} {{ item.subtotal.toFixed(2) }}</td>
                                <!-- Tipo de afectación IGV — editable por el vendedor -->
                                <td class="text-center">
                                    <select v-model="item.tip_afe_igv" class="form-select form-select-sm"
                                        style="width:110px; font-size:.75rem" @change="updateItem(index)"
                                        :title="'tip_afe_igv: ' + item.tip_afe_igv">
                                        <option value="10">Grav. 10</option>
                                        <option value="20">Exon. 20</option>
                                        <option value="30">Inaf. 30</option>
                                        <option value="17">IVAP 17</option>
                                        <option value="40">Exp. 40</option>
                                        <option value="11">Grat. 11</option>
                                    </select>
                                </td>
                                <td class="pe-3">
                                    <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2"
                                        @click="removeItem(index)">
                                        <i class="fas fa-trash-alt small"></i>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Descuento global + totales -->
                <div class="row g-0 border-top">
                    <div class="col-12 col-md-6 p-3 border-end">
                        <label class="form-label small fw-semibold text-secondary mb-1">
                            <i class="fas fa-tag me-1"></i>Descuento Global
                        </label>
                        <div class="input-group input-group-sm" style="max-width:200px">
                            <span class="input-group-text text-muted small">{{ currency }}</span>
                            <b-form-input type="number" id="n-discount-global" v-model.number="discount_global"
                                placeholder="0.00" size="sm" />
                        </div>
                    </div>
                    <div class="col-12 col-md-6 p-3">
                        <table class="table table-sm table-borderless mb-0 small">
                            <tbody>
                                <tr>
                                    <td class="text-muted">Valor Venta</td>
                                    <td class="text-end">{{ currency }} {{ sale_subtotal.toFixed(2) }}</td>
                                </tr>
                                <tr class="text-danger" v-if="(discount_total + discount_global) > 0">
                                    <td>(-) Descuento</td>
                                    <td class="text-end">{{ currency }} {{ (discount_total +
                                        discount_global).toFixed(2)
                                        }}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Base Imponible</td>
                                    <td class="text-end">{{ currency }} {{ getSubTotalSale().toFixed(2) }}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted">IGV ({{ getTasaIgvGlobal() }}%)</td>
                                    <td class="text-end">{{ currency }} {{ getIgvTotal().toFixed(2) }}</td>
                                </tr>
                                <tr v-if="icbper_total > 0">
                                    <td class="text-muted">ICBPER (bolsas)</td>
                                    <td class="text-end">{{ currency }} {{ icbper_total.toFixed(2) }}</td>
                                </tr>
                                <tr v-if="isc_total > 0">
                                    <td class="text-muted">ISC</td>
                                    <td class="text-end">{{ currency }} {{ isc_total.toFixed(2) }}</td>
                                </tr>
                                <tr class="border-top fw-semibold">
                                    <td>Total Factura</td>
                                    <td class="text-end">
                                        {{ currency }} {{ (getSubTotalSale() + getIgvTotal() + icbper_total +
                                            isc_total).toFixed(2) }}
                                    </td>
                                </tr>
                                <!-- Condiciones especiales — solo si aplica -->
                                <tr v-if="condicion_especial == '3'" class="text-info">
                                    <td>(+) Percepción {{ (porcentaje_percepcion_sel * 100).toFixed(1) }}%</td>
                                    <td class="text-end">{{ currency }} {{ monto_percepcion.toFixed(2) }}</td>
                                </tr>
                                <tr v-if="condicion_especial == '1'" class="text-danger">
                                    <td>(-) Retención {{ (porcentaje_retencion_sel * 100).toFixed(1) }}%</td>
                                    <td class="text-end">-{{ currency }} {{ monto_retencion.toFixed(2) }}</td>
                                </tr>
                                <tr v-if="condicion_especial == '2'" class="text-warning">
                                    <td>(-) Detracción {{ (porcentaje_detraccion_sel * 100).toFixed(1) }}% [{{
                                        codigo_detraccion_sel }}]</td>
                                    <td class="text-end">{{ currency }} {{ monto_detraccion.toFixed(2) }}</td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="bg-primary text-white">
                                    <td class="fw-bold fs-6 ps-2">MONTO A PAGAR</td>
                                    <td class="fw-bold fs-6 text-end pe-2">
                                        {{ currency }} {{ getTotalSales().toFixed(2) }}
                                    </td>
                                </tr>
                                <tr class="text-success fw-semibold">
                                    <td class="pt-1">Total Pagado</td>
                                    <td class="text-end pt-1">{{ currency }} {{ total_payments.toFixed(2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════
             PASO 4 — PAGOS + OBSERVACIONES + GUARDAR
        ═══════════════════════════════════════════════════ -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
                <span class="badge bg-danger rounded-pill">4</span>
                <span class="fw-semibold text-dark">Pagos</span>
            </div>
            <div class="card-body py-3">
                <!-- Fila de entrada de pago -->
                <div class="row g-3 align-items-end pb-3 border-bottom mb-3">
                    <div class="col-12 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Tipo de Pago</label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="type-pay-c" id="pay-contado" value="1"
                                :checked="type_payment == 1" @click="type_payment = 1" autocomplete="off">
                            <label class="btn btn-outline-success btn-sm fw-semibold" for="pay-contado">
                                <i class="fas fa-money-bill-wave me-1"></i>Contado
                            </label>
                            <input type="radio" class="btn-check" name="type-pay-c" id="pay-credito" value="2"
                                :checked="type_payment == 2" @click="type_payment = 2" autocomplete="off">
                            <label class="btn btn-outline-warning btn-sm fw-semibold" for="pay-credito">
                                <i class="fas fa-calendar-alt me-1"></i>Crédito
                            </label>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Método</label>
                        <select v-model="method_payment" class="form-select form-select-sm">
                            <option value="EFECTIVO">💵 Efectivo</option>
                            <option value="TRANSFERENCIA">🏦 Transferencia</option>
                            <option value="YAPE">📱 Yape</option>
                            <option value="PLIN">📱 Plin</option>
                            <option value="TARJETA DE CREDITO">💳 Tarjeta de Crédito</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Monto</label>
                        <b-input-group size="sm">
                            <b-input-group-text>{{ currency }}</b-input-group-text>
                            <b-form-input type="number" v-model.number="amount" placeholder="0.00" size="sm" />
                        </b-input-group>
                    </div>
                    <div class="col-6 col-md-2" v-if="type_payment == 2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Fecha de Pago</label>
                        <b-form-input type="date" v-model="date_payment" size="sm" />
                    </div>
                    <div class="col-6 col-md-2">
                        <button type="button" class="btn btn-primary btn-sm w-100 fw-semibold" @click="addPayment()">
                            <i class="fas fa-plus me-1"></i>Agregar Pago
                        </button>
                    </div>
                </div>

                <!-- Lista de pagos + observaciones + botones -->
                <div class="row g-3 align-items-start">
                    <!-- Tabla de pagos -->
                    <div class="col-12 col-md-5">
                        <p class="small fw-semibold text-secondary mb-1">
                            <i class="fas fa-list-ul me-1"></i>Pagos registrados
                        </p>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr class="small text-secondary text-uppercase">
                                        <th>#</th>
                                        <th>Método</th>
                                        <th class="text-end">Monto</th>
                                        <th v-if="type_payment == 2">Fecha</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-if="sale_payments.length === 0">
                                        <td colspan="5" class="text-center text-muted fst-italic py-3 small">
                                            Sin pagos aún
                                        </td>
                                    </tr>
                                    <tr v-for="(p, index) in sale_payments" :key="index">
                                        <td class="text-muted small">{{ index + 1 }}</td>
                                        <td class="fw-semibold small">{{ p.method_payment }}</td>
                                        <td class="text-end fw-bold">{{ currency }} {{ p.amount }}</td>
                                        <td v-if="type_payment == 2" class="small text-muted">{{ p.date_payment }}
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2"
                                                @click="removePayment(index)">
                                                <i class="fas fa-trash-alt small"></i>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Observaciones -->
                    <div class="col-12 col-md-4">
                        <label class="form-label small fw-semibold text-secondary mb-1">
                            <i class="fas fa-comment-alt me-1"></i>Observaciones
                        </label>
                        <b-form-textarea v-model="description" rows="4"
                            placeholder="Notas internas, referencias, instrucciones..."
                            class="form-control form-control-sm" />
                    </div>

                    <!-- Botones -->
                    <div class="col-12 col-md-3 d-flex flex-column gap-2 pt-md-4">
                        <button type="button" class="btn btn-success fw-bold py-2 shadow-sm" @click="store()">
                            <i class="fas fa-check-circle me-2"></i>
                            Guardar {{ state_sale == 1 ? 'Venta' : 'Cotización' }}
                        </button>
                        <button type="button" class="btn btn-outline-secondary fw-semibold py-2"
                            @click="$router.push({ name: 'sale.list' })">
                            <i class="fas fa-undo me-2"></i>Listar {{ state_sale == 1 ? 'Ventas' : 'Cotizacións' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- BARRA FIJA INFERIOR -->
        <div class="sv-bottom-bar">
            <div class="sv-bottom-inner">
                <div class="sv-bottom-chip">
                    <span class="sv-bottom-label">Ítems</span>
                    <span class="sv-bottom-value text-primary fw-bold">{{ sale_details.length }}</span>
                </div>
                <div class="sv-bottom-divider"></div>
                <div class="sv-bottom-chip">
                    <span class="sv-bottom-label">Base</span>
                    <span class="sv-bottom-value">{{ currency }} {{ getSubTotalSale().toFixed(2) }}</span>
                </div>
                <div class="sv-bottom-divider d-none d-md-block"></div>
                <div class="sv-bottom-chip d-none d-md-flex">
                    <span class="sv-bottom-label">IGV</span>
                    <span class="sv-bottom-value">{{ currency }} {{ getIgvTotal().toFixed(2) }}</span>
                </div>
                <div class="sv-bottom-divider d-none d-md-block"></div>
                <div class="sv-bottom-chip d-none d-md-flex" v-if="total_payments > 0">
                    <span class="sv-bottom-label">Pagado</span>
                    <span class="sv-bottom-value text-success fw-semibold">{{ currency }} {{
                        total_payments.toFixed(2)
                        }}</span>
                </div>
                <div class="sv-bottom-divider d-none d-md-block" v-if="total_payments > 0"></div>
                <div class="sv-bottom-total ms-auto">
                    <span class="sv-bottom-total-label">TOTAL</span>
                    <span class="sv-bottom-total-amount">{{ currency }} {{ getTotalSales().toFixed(2) }}</span>
                </div>
                <button type="button" class="btn btn-success fw-bold px-4 shadow-sm sv-bottom-save" @click="store()">
                    <i class="fas fa-check-circle me-2"></i>
                    <span class="d-none d-sm-inline">Guardar </span>{{ state_sale == 1 ? 'Venta' : 'Cotización' }}
                </button>
            </div>
        </div>

        <!-- MODALES clientes y productos -->
        <Teleport to="body">
            <b-modal v-model="showQuickClientModal" title="Registrar Cliente Rápido" hide-footer centered size="lg"
                @hidden="showQuickClientModal = false">
                <ClientFormQuick :initial-data="quickClientData" @saved="onClientCreated"
                    @cancel="showQuickClientModal = false" />
            </b-modal>
        </Teleport>

        <Teleport to="body">
            <b-modal v-model="showQuickProductModal" title="Registrar Producto Rápido" hide-footer centered size="lg"
                @hidden="showQuickProductModal = false">
                <ProductFormQuick :initial-data="quickProductData" @saved="onProductCreated"
                    @cancel="showQuickProductModal = false" />
            </b-modal>
        </Teleport>

    </DefaultLayout>
</template>

<script setup lang="ts">

import { ref, watch, onMounted, computed, nextTick } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { AxiosResponse } from 'axios';
import httpClient from '@/helpers/http-client';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import type { SaleDetail, SaleConfig, SaleResponse, SalePayment } from '@/types/sales';
import type { Client } from '@/types/clients';
import type { Product } from '@/types/products';
import ClientFormQuick from '@/components/Sales/ClientFormQuick.vue';
import ProductFormQuick from '@/components/Sales/ProductFormQuick.vue';
import { imprimirComprobante } from '@/composables/usePrintComprobante';
import { getPrecioBaseSinIgv } from '@/composables/useProductPricing';
import { useAuthStore } from '@/stores/auth';


type TVueSwalInstance = typeof Swal & typeof Swal.fire;

//const route = useRoute();
const route = useRoute();
const router = useRouter();

const sale_id = ref<string>(route.params.id as string);
//const sale_id_edit = ref<string | string[]>("");//id de la venta a edit



// ── Datos maestros ──────────────────────────────────────────────────
const clients = ref<Client[]>([]);
const products = ref<Product[]>([]);
const client_selected = ref<Client | undefined>(undefined);
const productSelected = ref<Product | undefined>(undefined);

// ── Búsqueda de clientes ──────────────────────────────────────────
const clientSearchText = ref<string>('');
const clientSuggestions = ref<Client[]>([]);
const client_address = ref<string>('');
let clientSearchTimeout: any = null;
const clientNotFound = ref<boolean>(false);

// ── Búsqueda de productos ──────────────────────────────────────────
const productSearchText = ref<string>('');
const productSuggestions = ref<Product[]>([]);
let productSearchTimeout: any = null;
const productNotFound = ref<boolean>(false);

// ── Modales rápidos ────────────────────────────────────────────────
const showQuickClientModal = ref<boolean>(false);
const quickClientData = ref<any>(null);
const showQuickProductModal = ref<boolean>(false);
const quickProductData = ref<any>(null);

// ── Datos de la transacción ────────────────────────────────────────
const state_sale = ref<number>(1);
const is_exportacion = ref<number>(0);
const n_transaction = ref<string>('');
const today = ref<string>('');
const serie = ref<string>('B001');
const type_operation = ref<string>('10');
const destino = ref<string>('amazonia');
const currency = ref<string>('PEN');
const currency_symbol = computed(() => currency.value === 'USD' ? '$' : 'S/.');

// Bloquea los watchers de recálculo tributario mientras los datos se
// asignan de forma programática (carga inicial de la venta, o reversión
// de un cambio de destino que el usuario canceló en el diálogo de aviso)
const isHydrating = ref(false);

// ── Condiciones especiales ─────────────────────────────────────────
const condicion_especial = ref<string>('0');
const n_comprobante_anticipo = ref<string>('');
const sale_anticipo = ref<number>(0);
const codigo_detraccion_sel = ref<string>('');
const porcentaje_detraccion_sel = ref<number>(0);
const monto_detraccion = ref<number>(0);
const porcentaje_retencion_sel = ref<number>(0.03);
const monto_retencion = ref<number>(0);
const retencion_igv = ref<number>(0);
const porcentaje_percepcion_sel = ref<number>(0.02);
const monto_percepcion = ref<number>(0);

// ── Parámetros tributarios ─────────────────────────────────────────
const parametros_tributarios = ref<Record<string, any[]>>({});
const codigos_detraccion = ref<any[]>([]);

const getIgvGeneralTasa = (): number => {
    const igv_default = parametros_tributarios.value['IGV']?.find((t: any) => t.es_default);
    return Number(igv_default?.tasa_porcentaje ?? 18);
};
const getIgvAmazoniaTasa = (): number =>
    Number(parametros_tributarios.value['IGV']?.find((t: any) => t.ubigeos_aplicables)?.tasa_porcentaje ?? 0);
const getIvapTasa = (): number =>
    Number(parametros_tributarios.value['IVAP']?.[0]?.tasa_porcentaje ?? 4);
const getIcbperMonto = (): number =>
    Number(parametros_tributarios.value['ICBPER']?.[0]?.tasa_porcentaje ?? 0.50);
const getRetencionTasa = (): number =>
    Number(parametros_tributarios.value['RETENCION']?.[0]?.tasa_porcentaje ?? 3) / 100;
const getPercepcionTasa = (): number =>
    Number(parametros_tributarios.value['PERCEPCION']?.[0]?.tasa_porcentaje ?? 2) / 100;

// ── Moneda ──────────────────────────────────────────────────────
const currency_iso = ref<string>('PEN'); // 'PEN' o 'USD' para el backend



// ── Detalle y pagos ────────────────────────────────────────────────
const sale_details = ref<SaleDetail[]>([]);
const discount_total = ref<number>(0);
const discount_global = ref<number>(0);
const default_quantity = ref<number>(1);
const default_price = ref<number>(0);
const description = ref<string>('');

// Pagos
const type_payment = ref<number>(1);
const method_payment = ref<string>('EFECTIVO');
const amount = ref<number>(0);
const date_payment = ref<string>('');
const sale_payments = ref<SalePayment[]>([]);

// Totales
const igv_total = ref<number>(0);
const sale_total = ref<number>(0);
const sale_subtotal = ref<number>(0);
const icbper_total = ref<number>(0);
const isc_total = ref<number>(0);
const total_payments = ref<number>(0);


// ── MÉTODOS DE CLIENTE (idénticos a register) ────────────────────
const onClientSearchInput = () => {
    clearTimeout(clientSearchTimeout);
    const query = clientSearchText.value.trim();
    clientNotFound.value = false;
    if (client_selected.value && query.length > 0) clearClientSelection();
    if (query.length === 0) { clientSuggestions.value = []; return; }
    if (/^\d{8}$/.test(query) || /^\d{11}$/.test(query)) {
        searchClientByDocument(); return;
    }
    if (query.length >= 2) {
        clientSearchTimeout = setTimeout(() => searchClients(query), 300);
    }
};
const onClientSearchFocus = () => {
    if (client_selected.value) return;
    const query = clientSearchText.value.trim();
    if (query.length >= 2) searchClients(query);
};
const onClientSearchBlur = () => {
    setTimeout(() => {
        clientSuggestions.value = [];
        if (clientSearchText.value.trim().length > 2 && !client_selected.value) {
            clientNotFound.value = true;
        }
    }, 200);
};

const searchClients = async (query: string) => {
    try {
        const res = await httpClient.get(`clients?search=${encodeURIComponent(query)}&take=10`);
        clientSuggestions.value = res.data.clients.data;
        clientNotFound.value = clientSuggestions.value.length === 0;
    } catch {
        clientSuggestions.value = [];
        clientNotFound.value = true;
    }
};

const searchClientByDocument = async () => {
    const doc = clientSearchText.value.trim();
    if (!doc) return;
    if (client_selected.value) { clearClientSelection(); setTimeout(searchClientByDocument, 100); return; }

    const existing = clients.value.find(c => c.n_document === doc);
    if (existing) { selectClient(existing); return; }

    if (/^\d{8}$/.test(doc)) await searchDocumentAPI('dni', doc);
    else if (/^\d{11}$/.test(doc)) await searchDocumentAPI('ruc', doc);
    else await searchClients(doc);
};

const searchDocumentAPI = async (type: string, number: string) => {
    try {
        const response = await httpClient.get(`/search-document/${type}/${number}`);
        if (response.data.success === false) {
            openQuickClientModalWithData({ type_document: type.toUpperCase(), n_document: number });
            return;
        }
        const existing = clients.value.find(c => c.n_document === number);
        if (existing) { selectClient(existing); return; }

        const data = response.data;
        let clientData: any = { type_document: type.toUpperCase(), n_document: number };
        if (type === 'dni') {
            clientData.name = data.nombres || '';
            clientData.surname = `${data.apellidoPaterno || ''} ${data.apellidoMaterno || ''}`.trim();
            clientData.full_name = `${clientData.name} ${clientData.surname}`;
        } else {
            clientData.full_name = data.razonSocial || '';
            clientData.name_comerc = data.nombreComercial || '';
            clientData.address = data.direccion || '';
        }
        openQuickClientModalWithData(clientData);
    } catch {
        openQuickClientModalWithData({ type_document: type.toUpperCase(), n_document: number });
    }
};


const selectClient = (client: Client) => {
    client_selected.value = client;
    clientSearchText.value = client.full_name || client.name_comerc || client.name || '';
    clientSuggestions.value = [];
    client_address.value = client.address || '';
    clientNotFound.value = false;

    // ── Autocompletar comprobante según tipo de documento del cliente ──
    // RUC → Factura (F001), DNI y otros → Boleta (B001)
    serie.value = client.type_document === 'RUC' ? 'F001' : 'B001';

    // ── Sugerir destino según zona del cliente ─────────────────────
    // Si el cliente es de zona Amazonía, pre-seleccionar 'amazonia'
    // El vendedor puede cambiarlo si el pedido se envía fuera
    if (client.es_amazonia) {
        destino.value = 'amazonia';
    }
    // Si no es Amazonía, no cambiar automáticamente — puede ser
    // un cliente de Lima que compra acá o uno local que envía fuera
};
const clearClientSelection = () => {
    client_selected.value = undefined;
    clientSearchText.value = '';
    clientSuggestions.value = [];
    client_address.value = '';
    clientNotFound.value = false;
    serie.value = 'B001'; // resetear a boleta por defecto
};

// ── Métodos de producto ───────────────────────────────────────────

const onProductSearchInput = () => {
    clearTimeout(productSearchTimeout);
    const query = productSearchText.value.trim();
    productNotFound.value = false;
    if (query.length === 0) { productSuggestions.value = []; return; }
    if (query.length >= 2) {
        productSearchTimeout = setTimeout(() => searchProducts(query), 300);
    }
};

const onProductSearchFocus = () => {
    const query = productSearchText.value.trim();
    if (query.length >= 2) searchProducts(query);
};

const onProductSearchBlur = () => {
    setTimeout(() => {
        productSuggestions.value = [];
        if (productSearchText.value.trim().length > 2 && !productSelected.value) {
            productNotFound.value = true;
        }
    }, 200);
};

const searchProducts = async (query: string) => {
    try {
        const res = await httpClient.get(`products?search=${encodeURIComponent(query)}&take=10`);
        productSuggestions.value = res.data.products.data;
        productNotFound.value = productSuggestions.value.length === 0;
    } catch {
        productSuggestions.value = [];
        productNotFound.value = true;
    }
};

// ── Resolver tip_afe_igv según las reglas de negocio ─────────────
// Esta es la función central que integra todo lo que definimos:
// producto + destino + exportación → tip_afe_igv correcto
const resolverTipAfeIgv = (product: Product): string => {
    // Exportación siempre gana sobre cualquier otra regla
    if (is_exportacion.value === 1) return '40';

    // Producto exonerado o inafecto por naturaleza propia
    // (no depende del destino — ej: medicamentos del Apéndice I)
    if (['20', '30'].includes(product.tip_afe_igv_default ?? '10')) {
        return product.tip_afe_igv_default;
    }

    // IVAP (arroz pilado) — siempre aplica independientemente del destino
    if (product.is_ivap === 2) return '17';

    // Ley 27037 Amazonía — bien o servicio entregado en la zona
    // El cliente puede ser de cualquier lugar — lo que importa es el destino
    if (destino.value === 'amazonia') return '20';

    // Caso por defecto: gravado con IGV normal
    return '10';
};

// ── Recalcula tip_afe_igv y sus montos dependientes en una sola operación ──
// Nunca deben actualizarse por separado: si tip_afe_igv cambia sin recalcular
// igv/mto_base_igv/mto_valor_venta/subtotal (o viceversa), los totales quedan
// descuadrados frente a lo que se envía a SUNAT.
const recalcularItemTipAfeIgv = (item: SaleDetail, index: number) => {
    item.tip_afe_igv = resolverTipAfeIgv(item.product);
    updateItem(index);
};

// ── SELECT AND ADD PRODUCT (directo al detalle) ────────────────────
const selectAndAddProduct = (product: Product) => {
    if (product.stock <= 0 && product.disponiblidad !== 1) {
        (Swal as TVueSwalInstance).fire('Stock insuficiente', 'No hay stock disponible', 'error');
        return;
    }

    // Si el producto ya está en la lista, incrementar cantidad
    const existing = sale_details.value.find(d => d.product.id === product.id);
    if (existing) {
        const newQty = existing.quantity + (default_quantity.value || 1);
        if (newQty > product.stock && product.disponiblidad !== 1) {
            (Swal as TVueSwalInstance).fire('Stock insuficiente', `Solo hay ${product.stock} unidades`, 'error');
            return;
        }
        existing.quantity = newQty;
        updateItem(sale_details.value.indexOf(existing));
        clearSearchProduct();
        return;
    }

    // Precio según tipo de cliente
    let price = default_price.value;
    if (price <= 0) {
        const rawPrice = String(client_selected.value?.type_client) === '2'
            ? product.price_company || product.price_general || 0
            : product.price_general || 0;
        // El precio crudo del catálogo puede venir CON IGV incluido
        // (product.include_igv) — hay que revertir ese cálculo para obtener
        // el P.BASE real, igual que hace el registro de producto.
        price = getPrecioBaseSinIgv(rawPrice, product, {
            igvGeneral: getIgvGeneralTasa(),
            ivap: getIvapTasa(),
        });
    }

    // Resolver el tipo de afectación IGV usando las reglas de negocio
    const tip_afe = resolverTipAfeIgv(product);

    sale_details.value.push({
        product: product,
        unidad_medida: product.unidad_medida || 'NIU',
        price_base: Number(price),
        quantity: default_quantity.value || 1,
        discount_percent: 0,
        tip_afe_igv: tip_afe,
        subtotal: 0,
        igv: 0,
        isc: 0,
        icbper: 0,
        discount: 0,
        price_final: 0,
        mto_valor_venta: 0,
        mto_base_igv: 0,
        porcentaje_igv: 0,
        total_impuestos: 0,
        tipo_isc: product.tipo_isc ?? '01',
        monto_isc_fijo: product.monto_isc_fijo ?? 0,
        contenido_neto_litros: product.contenido_neto_litros ?? undefined,
        per_icbper: 0,
    });

    const lastIndex = sale_details.value.length - 1;
    updateItem(lastIndex);
    sumDetails();
    clearSearchProduct();
    default_price.value = 0;
};

const clearSearchProduct = () => {
    productSearchText.value = '';
    productSuggestions.value = [];
    productNotFound.value = false;
};

// ── ACTUALIZAR ÍTEM ──────────────────────────────────────────────────
const updateItem = (index: number) => {
    const item = sale_details.value[index];
    if (!item) return;

    const qty = Number(item.quantity) || 0;
    const price_base = Number(item.price_base) || 0;
    const disc_pct = Number(item.discount_percent) || 0;

    // mto_valor_venta = precio_base × cantidad (ANTES del descuento)
    // Greenter: SaleDetail→setMtoValorVenta()
    const mto_valor_venta = qty * price_base;

    // Descuento de la línea en monto
    const descuento_linea = mto_valor_venta * (disc_pct / 100);

    // mto_base_igv = base neta sobre la que se calcula el IGV
    // = mto_valor_venta - descuento_linea
    // Greenter: SaleDetail→setMtoBaseIgv()
    const mto_base_igv = mto_valor_venta - descuento_linea;

    // ISC según régimen — solo si el producto tiene is_isc=true.
    // No basta con mirar percentage_isc/monto_isc_fijo > 0: un producto
    // puede tener datos residuales (bug conocido) sin estar afecto a ISC.
    let isc = 0;
    if (item.product.is_isc && (item.product.percentage_isc > 0 || item.monto_isc_fijo > 0)) {
        const tipo_isc = item.tipo_isc ?? '01';
        switch (tipo_isc) {
            case '01': // Al valor (% sobre base)
                isc = mto_base_igv * (item.product.percentage_isc / 100);
                break;
            case '02': // Específico (monto fijo — por litro si el producto
                       // tiene contenido_neto_litros, si no, directo por unidad)
                // PENDIENTE: contenido_neto_litros solo cubre productos líquidos.
                // Cigarrillos/combustibles (también Específico) necesitan un
                // factor de contenido no-líquido — ver nota en product/register.vue.
                isc = qty * (item.contenido_neto_litros || 1) * (item.monto_isc_fijo || 0);
                break;
            case '03': // Al valor según PVP
                isc = (price_base * qty) * (item.product.percentage_isc / 100);
                break;
        }
    }

    // Tasa de IGV según tip_afe_igv — lee de tax_params, no hardcode
    let tasa_igv = 0;
    let porcentaje_igv = 0;
    if (['10', '11'].includes(item.tip_afe_igv)) {
        tasa_igv = getIgvGeneralTasa();
        porcentaje_igv = tasa_igv;
    } else if (item.tip_afe_igv === '17') {
        tasa_igv = getIvapTasa();
        porcentaje_igv = tasa_igv;
    }
    // tip_afe_igv '20', '30', '40' → tasa 0

    // IGV se aplica sobre (mto_base_igv + ISC)
    const igv = (mto_base_igv + isc) * (tasa_igv / 100);

    // ICBPER — monto por unidad desde tax_params
    let icbper = 0;
    let per_icbper = 0;
    if (item.product.is_icbper === 2) {
        per_icbper = getIcbperMonto();   // S/ 0.50 por bolsa (desde BD)
        icbper = qty * per_icbper;
    }

    // Precio final unitario con IGV incluido
    const price_final = qty > 0
        ? (mto_base_igv + igv + isc + icbper) / qty
        : 0;

    // Total de impuestos de la línea = IGV + ISC + ICBPER
    const total_impuestos = igv + isc + icbper;

    // Guardar en el ítem
    item.mto_valor_venta = Number(mto_valor_venta.toFixed(6));
    item.mto_base_igv = Number(mto_base_igv.toFixed(6));
    item.porcentaje_igv = porcentaje_igv;
    item.subtotal = Number(mto_base_igv.toFixed(4));   // base neta = subtotal
    item.discount = Number(descuento_linea.toFixed(4));
    item.isc = Number(isc.toFixed(4));
    item.igv = Number(igv.toFixed(4));
    item.icbper = Number(icbper.toFixed(4));
    item.per_icbper = per_icbper;
    item.price_final = Number(price_final.toFixed(4));
    item.total_impuestos = Number(total_impuestos.toFixed(4));

    sumDetails();
};

const removeItem = (index: number) => {
    sale_details.value.splice(index, 1);
    sumDetails();
};

// ── Tasas de IGV para mostrar en la tabla de totales ─────────────
const getTasaIgv = (item?: SaleDetail): number => {
    if (!item) return getIgvGeneralTasa();
    if (item.tip_afe_igv === '17') return getIvapTasa();
    if (['20', '30', '40'].includes(item.tip_afe_igv)) return 0;
    return getIgvGeneralTasa();
};

const getTasaIgvGlobal = (): number => {
    if (sale_details.value.length === 0) return getIgvGeneralTasa();
    return getTasaIgv(sale_details.value[0]);
};


// ── Cálculo de totales ────────────────────────────────────────────
const getSubTotalSale = (): number => {
    const descuento = Number(discount_global.value) || 0;
    return Number((sale_subtotal.value - descuento).toFixed(4));
};

const getIgvTotal = (): number => {
    // igv_total ya viene recalculado en sumDetails() sobre la base
    // neta del descuento global (prorrateado por línea)
    return Number(igv_total.value.toFixed(4));
};

const getTotalFactura = (): number => {
    return getSubTotalSale() + getIgvTotal() + icbper_total.value + isc_total.value;
};

const getTotalSales = (): number => sale_total.value;

const getBaseForRegimen = (): number => getTotalFactura();

const sumDetails = () => {
    // Asegurar arrays

    const pagos = sale_payments.value || [];

    if (isNaN(discount_global.value) || discount_global.value === null) {
        discount_global.value = 0;
    }

    // Excluir operaciones gratuitas (tip_afe_igv = 11) de los totales
    const lineas_cobro = sale_details.value.filter(d => d.tip_afe_igv !== '11');

    // ── IGV recalculado sobre la base neta del descuento global ──────
    // El descuento global se prorratea por línea según su peso en el
    // subtotal bruto, y el IGV se recalcula sobre esa base ya descontada
    // respetando la tasa propia de cada línea — necesario porque una
    // misma venta puede mezclar líneas gravadas, exoneradas e IVAP.
    const subtotal_bruto = lineas_cobro.reduce((s, d) => s + d.subtotal, 0);
    const descuento_global_actual = Number(discount_global.value) || 0;
    igv_total.value = Number(lineas_cobro.reduce((s, d) => {
        const proporcion = subtotal_bruto > 0 ? d.subtotal / subtotal_bruto : 0;
        const base_neta = d.subtotal - (descuento_global_actual * proporcion);
        return s + (base_neta * (d.porcentaje_igv / 100));
    }, 0).toFixed(4));
    // ICBPER: monto fijo por unidad (bolsa), no depende del valor de venta
    // → nunca se prorratea con el descuento global. Confirmado con el usuario.
    icbper_total.value = Number(lineas_cobro.reduce((s, d) => s + d.icbper, 0).toFixed(4));

    // ISC: solo se prorratea con el descuento global si el régimen del
    // producto es porcentual sobre el valor de venta ('01' Al valor, '03'
    // Al valor según PVP) — mismo criterio que el IGV. El régimen '02'
    // (Específico, monto fijo x unidad) se comporta como ICBPER: no se
    // prorratea. PENDIENTE DE VALIDAR CON DATOS REALES: no hay productos
    // con ISC activo en pruebas todavía (ver conversación 2026-07-09).
    isc_total.value = Number(lineas_cobro.reduce((s, d) => {
        if (!d.product?.is_isc || d.tipo_isc === '02') {
            return s + d.isc;
        }
        const proporcion = subtotal_bruto > 0 ? d.subtotal / subtotal_bruto : 0;
        const descuento_linea = descuento_global_actual * proporcion;
        const percentage = d.product?.percentage_isc ?? 0;
        if (d.tipo_isc === '03') {
            return s + ((d.mto_valor_venta - descuento_linea) * (percentage / 100));
        }
        return s + ((d.subtotal - descuento_linea) * (percentage / 100)); // '01'
    }, 0).toFixed(4));
    sale_subtotal.value = Number(lineas_cobro.reduce((s, d) => s + d.subtotal, 0).toFixed(4));
    discount_total.value = Number(lineas_cobro.reduce((s, d) => s + d.discount, 0).toFixed(4));
    // Pagos — usar Number() en cada amount
    const totalPayments = pagos.reduce((sum, p) => sum + Number(p.amount || 0), 0);
    total_payments.value = Number(totalPayments.toFixed(2));

    // ── Calcular montos de regímenes especiales ───────────────────
    // Las tasas vienen de tax_params (BD), no hardcodeadas
    const base_regimen = getBaseForRegimen();

    monto_retencion.value = 0;
    monto_detraccion.value = 0;
    monto_percepcion.value = 0;

    // Actualizar tasas desde BD cada vez que se recalcula
    porcentaje_retencion_sel.value = getRetencionTasa();
    porcentaje_percepcion_sel.value = getPercepcionTasa();

    const cond = condicion_especial.value;
    if (cond === '1') {
        // Retención: solo aplica si supera el mínimo (S/ 700 por defecto)
        const minimo_retencion = Number(
            parametros_tributarios.value['RETENCION']?.[0]?.monto_minimo ?? 700
        );
        if (base_regimen > minimo_retencion) {
            monto_retencion.value = Number(
                (base_regimen * porcentaje_retencion_sel.value).toFixed(2)
            );
        }
    } else if (cond === '2' && porcentaje_detraccion_sel.value > 0) {
        // Detracción: solo aplica si tiene código seleccionado y supera el mínimo
        const codigo_sel = codigos_detraccion.value.find(d => d.codigo === codigo_detraccion_sel.value);
        const minimo_detraccion = Number(codigo_sel?.monto_minimo ?? 700);
        if (base_regimen > minimo_detraccion) {
            monto_detraccion.value = Number(
                (base_regimen * porcentaje_detraccion_sel.value).toFixed(2)
            );
        }
    } else if (cond === '3') {
        // Percepción
        const minimo_percepcion = Number(
            parametros_tributarios.value['PERCEPCION']?.[0]?.monto_minimo ?? 700
        );
        if (base_regimen > minimo_percepcion) {
            monto_percepcion.value = Number(
                (base_regimen * porcentaje_percepcion_sel.value).toFixed(2)
            );
        }
    }

    // Total final = base + percepción - retención - detracción
    sale_total.value = Number((
        base_regimen
        + monto_percepcion.value
        - monto_retencion.value
        - monto_detraccion.value
    ).toFixed(4));
};

// ── Cuando cambia el código de detracción ─────────────────────────
const onDetractionCodeChange = () => {
    const codigo_sel = codigos_detraccion.value
        .find(d => d.codigo === codigo_detraccion_sel.value);

    // hay que dividir entre 100 para obtener 0.12
    porcentaje_detraccion_sel.value = codigo_sel
        ? Number(codigo_sel.tasa_porcentaje) / 100
        : 0;

    sumDetails();
};

// ── Anticipo ──────────────────────────────────────────────────────
const searchAnticipo = () => {
    if (!n_comprobante_anticipo.value.trim()) {
        (Swal as TVueSwalInstance).fire('Advertencia', 'Ingresa un número de comprobante.', 'warning');
        return;
    }
    (Swal as TVueSwalInstance).fire('Buscando...', 'Función pendiente de implementar.', 'info');
};

const resetAnticipo = () => {
    n_comprobante_anticipo.value = '';
    sale_anticipo.value = 0;
};

// ── PAGOS ───────────────────────────────────────────────────────────
const addPayment = () => {
    if (!method_payment.value) {
        (Swal as TVueSwalInstance).fire('Error', 'El método de pago es obligatorio.', 'error'); return;
    }
    if (amount.value <= 0) {
        (Swal as TVueSwalInstance).fire('Error', 'El monto debe ser mayor a 0.', 'error'); return;
    }
    if (type_payment.value == 2 && !date_payment.value) {
        (Swal as TVueSwalInstance).fire('Error', 'La fecha de pago es obligatoria en crédito.', 'error'); return;
    }
    if (getTotalSales() < (amount.value + total_payments.value)) {
        (Swal as TVueSwalInstance).fire('Error', 'El monto no puede superar el total de la venta.', 'error'); return;
    }
    if (method_payment.value === 'EFECTIVO' && sale_payments.value.some(p => p.method_payment === 'EFECTIVO')) {
        (Swal as TVueSwalInstance).fire('Error', 'Ya hay un pago en efectivo registrado.', 'error'); return;
    }

    // Si estamos editando, guardar el pago en backend
    const data = {
        sale_id: sale_id.value,
        method_payment: method_payment.value,
        amount: amount.value,
        date_payment: date_payment.value
    };

    httpClient.post('sale_payments', data)
        .then(res => {
            sale_payments.value.push(res.data.payment);
            setTimeout(() => {
                method_payment.value = '';
                amount.value = 0;
                date_payment.value = '';
                sumDetails();
            }, 50);
        })
        .catch(err => {
            (Swal as TVueSwalInstance).fire('Error', err.response?.data?.message || 'Error al agregar pago', 'error');
        });
};


const removePayment = (index: number) => {
    const payment = sale_payments.value[index];
    if (!payment) return;
    (Swal as TVueSwalInstance)
        .fire({
            title: "Confirmar eliminación",
            text: `¿Estás seguro de eliminar el pago ${payment.method_payment}?`,
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#3085d6",
            cancelButtonColor: "#d33",
            confirmButtonText: "Sí, eliminar",
            cancelButtonText: "Cancelar"
        })
        .then(async (result: any) => {
            if (result.isConfirmed) {
                try {
                    await httpClient.delete(`sale_payments/${payment.id}`);
                    sale_payments.value.splice(index, 1);
                    (Swal as TVueSwalInstance).fire('Eliminado', 'Pago eliminado correctamente', 'success');
                    sumDetails();
                } catch (err: any) {
                    (Swal as TVueSwalInstance).fire('Error', err.response?.data?.message || 'Error al eliminar', 'error');
                }
            }
        });
};
/* const removePayment = (index: number) => {
    sale_payments.value.splice(index, 1);
    sumDetails();
}; */

// ── Guardar venta ─────────────────────────────────────────────────
const store = async () => {
    if (!client_selected.value) {
        (Swal as TVueSwalInstance).fire({ icon: 'error', title: 'Error', text: 'Selecciona un cliente.' }); return;
    }
    if (sale_details.value.length === 0) {
        (Swal as TVueSwalInstance).fire({ icon: 'error', title: 'Error', text: 'Agrega al menos un producto.' }); return;
    }
    if (state_sale.value === 1 && sale_payments.value.length === 0 && getTotalSales() > 0) {
        (Swal as TVueSwalInstance).fire({ icon: 'error', title: 'Error', text: 'Agrega al menos un pago para la venta.' }); return;
    }
    if (condicion_especial.value === '2' && !codigo_detraccion_sel.value) {
        (Swal as TVueSwalInstance).fire({ icon: 'error', title: 'Error', text: 'Selecciona el código de detracción.' }); return;
    }

    // Estado de pago derivado automáticamente
    let estado_pago = 1; // pendiente
    if (state_sale.value === 1) {
        if (total_payments.value === getTotalSales()) estado_pago = 3;      // pagado completo
        else if (total_payments.value > 0) estado_pago = 2;      // pago parcial
    }

    // Mapear condicion_especial → retencion_igv (campo de la BD)
    const retencion_igv_bd = condicion_especial.value === 'anticipo' ? 0
        : ['1', '2', '3'].includes(condicion_especial.value) ? Number(condicion_especial.value)
            : 0;

    const data = {
        // Identificación
        serie: serie.value,
        n_transaction: n_transaction.value,
        date: today.value,

        // Cliente
        client_id: client_selected.value.id,
        type_client: client_selected.value.type_client,
        // Tipo de documento según Catálogo 06 SUNAT — ya calculado en el cliente
        cod_tipo_doc_cliente: client_selected.value.cod_tipo_doc_sunat ?? '1',

        // Configuración de la operación
        currency: currency.value,         // 'PEN' o 'USD' (código ISO)
        is_exportacion: is_exportacion.value,
        destino: destino.value,          // 'amazonia' o 'nacional'
        state_sale: state_sale.value,
        type_payment: type_payment.value,

        // Regímenes especiales
        retencion_igv: retencion_igv_bd,
        codigo_detraccion: codigo_detraccion_sel.value || null,
        porcentaje_detraccion: porcentaje_detraccion_sel.value,
        monto_detraccion: monto_detraccion.value,
        porcentaje_percepcion: porcentaje_percepcion_sel.value,
        monto_percepcion: monto_percepcion.value,
        porcentaje_retencion: porcentaje_retencion_sel.value,
        monto_retencion: monto_retencion.value,

        // Anticipo
        n_comprobante_anticipo: condicion_especial.value === 'anticipo' ? n_comprobante_anticipo.value : null,
        amount_anticipo: sale_anticipo.value,

        // Totales calculados (el backend no recalcula — usa estos valores)
        subtotal: sale_subtotal.value,
        igv: igv_total.value,
        total: sale_total.value,
        discount: discount_total.value,
        discount_global: discount_global.value,
        igv_discount_general: 0,  // deprecated — mantener por compatibilidad

        // Totales por tipo de operación para Greenter
        mto_oper_gravadas: sale_details.value.filter(d => d.tip_afe_igv === '10').reduce((s, d) => s + d.subtotal, 0),
        mto_oper_exoneradas: sale_details.value.filter(d => d.tip_afe_igv === '20').reduce((s, d) => s + d.subtotal, 0),
        mto_oper_inafectas: sale_details.value.filter(d => d.tip_afe_igv === '30').reduce((s, d) => s + d.subtotal, 0),
        mto_oper_exportacion: sale_details.value.filter(d => d.tip_afe_igv === '40').reduce((s, d) => s + d.subtotal, 0),
        mto_oper_gratuitas: sale_details.value.filter(d => d.tip_afe_igv === '11').reduce((s, d) => s + d.subtotal, 0),

        // Impuestos desagregados
        isc_total: isc_total.value,
        icbper_total: icbper_total.value,
        ivap_total: sale_details.value.filter(d => d.tip_afe_igv === '17').reduce((s, d) => s + d.igv, 0),
        total_impuestos: sale_details.value.reduce((s, d) => s + d.total_impuestos, 0),
        valor_venta: getSubTotalSale(),
        mto_imp_venta: Math.round(getTotalFactura() * 10) / 10,
        redondeo: Math.round((Math.round(getTotalFactura() * 10) / 10 - getTotalFactura()) * 100) / 100,

        // Estado y deuda
        state_payment: estado_pago,
        debt: getTotalSales() - total_payments.value,
        paid_out: total_payments.value,

        // Detalles — incluyen todos los campos para Greenter
        sale_details: sale_details.value,

        // Pagos
        payments: sale_payments.value,

        description: description.value,
    };

    try {
        const resp: AxiosResponse<SaleResponse> = await httpClient.patch(`sales/${sale_id.value}`, data);

        if (resp.data.error) {
            (Swal as TVueSwalInstance).fire('Error interno', resp.data.error.message, 'error');
            return;
        }
        if (resp.data.code === 200) {
            await (Swal as TVueSwalInstance).fire('¡Listo!', resp.data.message, 'success');
            const formatoDefault = useAuthStore().getUser()?.formato_impresion_default ?? 'a4';
            await imprimirComprobante(sale_id.value, formatoDefault);
            router.push({ name: 'sale.list' });
        }
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire('Error', e.response?.data?.message ?? 'Error inesperado', 'error');
    }
};

// ── CARGAR DATOS DE LA VENTA ──────────────────────────────────────
const loadSale = async () => {
    // Evita que los watchers de destino/condicion_especial/is_exportacion
    // recalculen tip_afe_igv o descarten sale_details mientras hidratamos
    // los valores ya guardados en la API.
    isHydrating.value = true;
    try {
        const res: AxiosResponse<SaleResponse> = await httpClient.get(`sales/${sale_id.value}`);
        if (res.data.code === 405) {
            (Swal as TVueSwalInstance).fire('Error', res.data.message, 'error');
            return;
        }
console.log(res);
        const sale = res.data.sale;
        if (!sale) return;

        // Asignar datos
        state_sale.value = sale.state_sale;
        n_transaction.value = sale.n_transaction;
        today.value = sale.created_at_format || sale.date || '';
        serie.value = sale.serie;
        currency.value = sale.currency || 'PEN';
        destino.value = sale.destino || 'nacional';
        description.value = sale.description || '';
        discount_global.value = Number(sale.discount_global || 0);
        type_payment.value = sale.type_payment || 1;

        // Cliente
        if (sale.client) {
            selectClient(sale.client);
        }

        // Detalles
        if (sale.sale_details && sale.sale_details.length) {
            // Mapear para asegurar propiedades adicionales.
            // IMPORTANTE: los montos (tip_afe_igv, igv, mto_base_igv,
            // mto_valor_venta, subtotal, etc.) se toman TAL CUAL vienen de la
            // API — solo se convierten a Number por si el backend/Postgres
            // los serializa como string. No se recalculan aquí: recalcular
            // con las tasas/reglas vigentes hoy podría no coincidir con lo
            // que ya se envió a SUNAT en su momento.
            sale_details.value = sale.sale_details.map((d: any) => ({
                ...d,
                discount_percent: Number(d.discount_percent ?? 0),
                monto_isc_fijo: d.product?.monto_isc_fijo || 0,
                tipo_isc: d.product?.tipo_isc || '01',
                per_icbper: Number(d.per_icbper ?? 0),
                total_impuestos: Number(d.total_impuestos ?? 0),
                quantity: Number(d.quantity ?? 0),
                price_base: Number(d.price_base ?? 0),
                mto_valor_venta: Number(d.mto_valor_venta ?? 0),
                mto_base_igv: Number(d.mto_base_igv ?? 0),
                porcentaje_igv: Number(d.porcentaje_igv ?? 0),
                subtotal: Number(d.subtotal ?? 0),
                discount: Number(d.discount ?? 0),
                isc: Number(d.isc ?? 0),
                igv: Number(d.igv ?? 0),
                icbper: Number(d.icbper ?? 0),
                price_final: Number(d.price_final ?? 0),
            }));
        }

        // Pagos   
        // //convertir amount a number
        if (sale.sale_payments) {
            sale_payments.value = sale.sale_payments.map((p: any) => ({
                ...p,
                amount: Number(p.amount) || 0
            }));
        } else {
            sale_payments.value = [];
        }

        // 1. Primero asignar condicion_especial
        if (sale.retencion_igv == 1) condicion_especial.value = '1';
        else if (sale.retencion_igv == 2) condicion_especial.value = '2';
        else if (sale.retencion_igv == 3) condicion_especial.value = '3';
        else if (sale.is_exportacion == 1) condicion_especial.value = 'exportacion';
        else if (sale.n_comprobante_anticipo) condicion_especial.value = 'anticipo';

        else condicion_especial.value = '0';
        // 2. Luego cargar el código de detracción con su porcentaje
        if (sale.codigo_detraccion) {
            codigo_detraccion_sel.value = sale.codigo_detraccion;

            // Buscar el código en la lista y cargar su porcentaje
            const cod = codigos_detraccion.value
                .find(d => d.codigo === sale.codigo_detraccion);

            porcentaje_detraccion_sel.value = cod
                ? Number(cod.tasa_porcentaje) / 100
                : Number(sale.porcentaje_detraccion ?? 0); // fallback: usar lo guardado en la venta
        }

        // Recalcular totales finales
        sumDetails();
    } catch (error) {
        console.error(error);
    } finally {
        // Esperamos a que Vue procese los watchers en cola (destino,
        // condicion_especial, is_exportacion) mientras la bandera sigue
        // activa, y solo entonces la liberamos para cambios reales del usuario.
        await nextTick();
        isHydrating.value = false;
    }
};




// ── Modales rápidos ───────────────────────────────────────────────
const openQuickClientModal = () => {
    quickClientData.value = null;
    showQuickClientModal.value = true;
};
const openQuickClientModalWithData = (data: any) => {
    quickClientData.value = data;
    showQuickClientModal.value = true;
};
const onClientCreated = (client: Client) => {
    clients.value.unshift(client);
    selectClient(client);
    showQuickClientModal.value = false;
};
const openQuickProductModal = () => {
    quickProductData.value = null;
    showQuickProductModal.value = true;
};
const onProductCreated = (product: Product) => {
    products.value.unshift(product);
    selectAndAddProduct(product);
    showQuickProductModal.value = false;
};

// ── CONFIGURACIÓN INICIAL ──────────────────────────────────────────
// ── CONFIGURACIÓN INICIAL ──────────────────────────────────────────
const config = async () => {
    try {
        const res: AxiosResponse<SaleConfig> = await httpClient.get('sales/config');
        // No asignamos n_transaction ni today porque ya vienen de la venta cargada
        if (products.value.length === 0) {
            clients.value = res.data.clients.data;
            products.value = res.data.products.data;
        }
        parametros_tributarios.value = (res.data as any).parametros_tributarios ?? {};
        codigos_detraccion.value = (res.data as any).codigos_detraccion ?? [];

        porcentaje_retencion_sel.value = getRetencionTasa();
        porcentaje_percepcion_sel.value = getPercepcionTasa();

        // Cargar venta después de tener los catálogos
        await loadSale();
    } catch (error) {
        console.error(error);
    }
};


// ── Watchers ──────────────────────────────────────────────────────
watch(discount_global, () => sumDetails());
watch(retencion_igv, () => sumDetails()); // compatibilidad

watch(condicion_especial, (valor) => {
    // Activar exportación si se seleccionó esa condición
    is_exportacion.value = valor === 'exportacion' ? 1 : 0;

    // Durante la carga inicial no se recalcula nada: is_exportacion ya
    // queda sincronizado arriba, pero los montos deben respetar lo guardado.
    if (isHydrating.value) return;

    // Recalcular todos los ítems porque el tip_afe_igv cambia
    sale_details.value.forEach((item, index) => {
        recalcularItemTipAfeIgv(item, index);
    });

    // Moneda: exportación siempre en USD por defecto
    // (el vendedor puede cambiarla si negoció en soles)
    if (valor === 'exportacion') {
        currency.value = 'USD';
    } else if (is_exportacion.value === 0) {
        currency.value = 'PEN';
    }

    sumDetails();
});


watch(type_payment, () => {
    setTimeout(() => {
        method_payment.value = '';
        amount.value = 0;
        date_payment.value = '';
        sumDetails();
    }, 50);
});

watch(is_exportacion, (value) => {
    // Durante la carga inicial NO se debe vaciar sale_details: esto es lo
    // que hacía desaparecer los detalles de ventas de exportación al editar.
    if (isHydrating.value) return;

    // Código ISO para Greenter: 'PEN' = soles, 'USD' = dólares
    currency.value = value === 1 ? 'USD' : 'PEN';
    sale_details.value = [];
    discount_global.value = 0;
    sumDetails();
});

watch(destino, async (_nuevoValor, valorAnterior) => {
    if (isHydrating.value) return;

    // Si ya hay líneas cargadas, avisar antes de recalcular en vez de
    // hacerlo en silencio — cambiar el destino puede mover líneas dentro/
    // fuera de la exoneración Ley 27037 y alterar los totales de la venta.
    if (sale_details.value.length) {
        const confirmacion = await (Swal as TVueSwalInstance).fire({
            title: 'Cambiar destino de la venta',
            text: `Esto recalculará el IGV de ${sale_details.value.length} línea(s) ya agregada(s). ¿Deseas continuar?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Sí, recalcular',
            cancelButtonText: 'Cancelar',
        });

        if (!confirmacion.isConfirmed) {
            isHydrating.value = true;
            destino.value = valorAnterior;
            await nextTick();
            isHydrating.value = false;
            return;
        }
    }

    // Cuando cambia el destino, recalcular tip_afe_igv de todos los ítems
    // porque la exoneración por Ley 27037 depende del destino
    sale_details.value.forEach((item, index) => {
        recalcularItemTipAfeIgv(item, index);
    });
    sumDetails();
});


/* const config = async () => {
    try {
        const res: AxiosResponse<SaleConfig> = await httpClient.get(
            `sales/config`);
        // n_transaction.value = res.data.n_transaction;
        // today.value = res.data.today;
        if (products.value.length == 0) {
            clients.value = res.data.clients.data;
            products.value = res.data.products.data;

            setTimeout(() => {
                clientSelectr.value = new Selectr('#n-f-clients');
                productSelectr.value = new Selectr('#n-f-products', {
                    searchable: true,

                    renderOption(option: any) {//
                        if (!option.value) return option.text;

                        const stock = option.dataset.stock ?? 0;

                        return `
                    <div style="display:flex;justify-content:space-between; width:100%;">
                        <span>${option.text}</span>
                        <span style="font-size:12px;color:${stock <= 5 ? 'red' : '#6b7280'};">stock: ${stock}</span>
                    </div>
    `;
                    },

                    renderSelection(option: any) {
                        return option.text;
                    }
                });
                show();
            }, 25);
        }



        console.log(res);

        setTimeout(() => {
            clientSelectr.value.on("selectr.change", (option: any) => {
                console.log(option.value);
                client_selected.value = clients.value.find(client => client.id == option.value);
                if (product_selected.value && client_selected.value) {
                    if (client_selected.value.type_client === '1') { //CLIENTE FINAL
                        price_base.value = Number(Number(getPriceBaseCF()).toFixed(3));
                    }
                    if (client_selected.value.type_client === '2') { // CLIENTE EMPRESA
                        price_base.value = Number(Number(getPriceBaseCE()).toFixed(3));
                    }
                }
            });

            productSelectr.value.on("selectr.change", (option: any) => {
                console.log(option.value);
                product_selected.value = products.value.find(product => product.id == option.value);
                if (product_selected.value) {
                    if (product_selected.value?.is_ivap == 1 && is_exportacion.value == 0) {
                        type_operation.value = "10";
                    };
                    if (is_exportacion.value == 1) {
                        type_operation.value = "40";
                    }
                    if (product_selected.value?.is_ivap == 2 && is_exportacion.value == 0) {
                        type_operation.value = "17";
                    };
                }




                if (client_selected.value) {
                    if (client_selected.value.type_client == '1') {//CLIENTE FINAL
                        price_base.value = Number(Number(getPriceBaseCF()).toFixed(3));

                    }
                    if (client_selected.value.type_client == '2') {// CLIENTE EMPRESA
                        price_base.value = Number(Number(getPriceBaseCE()).toFixed(3));
                    }
                } else {
                    price_base.value = product_selected.value?.price_general ?? 0;
                }
            });

        }, 50);

    } catch (error) {
        console.log(error);

    }

} */

/* const deleteSaleDetail = async (index: number) => {
    const SALE_DETAIL = sale_details.value[index];
    if (!SALE_DETAIL) return;

    try {
        const result = await (Swal as TVueSwalInstance).fire({
            title: "Confirmar eliminación",
            text: `¿Estás seguro de eliminar el producto '${SALE_DETAIL.product.title}'?`,
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#3085d6",
            cancelButtonColor: "#d33",
            confirmButtonText: "Sí, eliminar",
            cancelButtonText: "Cancelar"
        });

        if (result.isConfirmed) {
            await httpClient.delete("sale_details/" + SALE_DETAIL.id);
            (Swal as TVueSwalInstance).fire("Eliminado!", `Producto eliminado.`, "success");
            sale_details.value.splice(index, 1);
            discount_global.value = 0;
            sumDetails();
        }
    } catch (error: any) {
        if (error.response?.data?.error) {
            (Swal as TVueSwalInstance).fire('Error', error.response.data.error, 'error');
        }
    }
};
 */

onMounted(() => config());
</script>

<style scoped>
/* ── Barra fija inferior ─────────────────────────────── */
.sv-bottom-bar {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 1030;
    background: #ffffff;
    border-top: 2px solid #e2e8f0;
    box-shadow: 0 -4px 20px rgba(0, 0, 0, .10);
    padding: 0 16px;
    height: 58px;
}

.sv-bottom-inner {
    max-width: 1400px;
    margin: 0 auto;
    height: 100%;
    display: flex;
    align-items: center;
    gap: 8px;
}

.sv-bottom-chip {
    display: flex;
    flex-direction: column;
    line-height: 1.15;
    padding: 0 4px;
    min-width: 70px;
}

.sv-bottom-label {
    font-size: .68rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: #94a3b8;
}

.sv-bottom-value {
    font-size: .88rem;
    font-weight: 600;
    color: #1e293b;
}

.sv-bottom-divider {
    width: 1px;
    height: 28px;
    background: #e2e8f0;
    flex-shrink: 0;
}

.sv-bottom-total {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    padding: 0 8px;
    line-height: 1.1;
}

.sv-bottom-total-label {
    font-size: .65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: #64748b;
}

.sv-bottom-total-amount {
    font-size: 1.2rem;
    font-weight: 800;
    color: #1e40af;
    letter-spacing: -.5px;
}

.sv-bottom-save {
    height: 38px;
    border-radius: 8px;
    font-size: .9rem;
    white-space: nowrap;
    flex-shrink: 0;
}

:deep(.default-layout-content),
:deep(main),
:deep(.layout-page-content) {
    padding-bottom: 74px !important;
}
</style>