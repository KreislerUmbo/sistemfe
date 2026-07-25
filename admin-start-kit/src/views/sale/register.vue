<template>
    <DefaultLayout>

        <!-- ═══════════════════════════════════════════════════
             TOPBAR — Tipo de transacción
        ═══════════════════════════════════════════════════ -->
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-cash-register me-2 text-primary"></i>
                    Nueva {{ state_sale == 1 ? 'Venta' : 'Cotización' }}
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
                    <div class="col-6 col-md-4" v-if="is_exportacion == 0 && tipoEsFiscal">
                        <label class="form-label mb-1 small fw-semibold text-secondary">
                            <i class="fas fa-sliders-h me-1"></i>Condiciones Especiales
                        </label>
                        <div>
                            <select v-model="condicion_especial" class="form-select form-select-sm">
                                <option value="0">— Ninguna (venta normal) —</option>
                                <option value="anticipo">Aplicar adelanto(s) del cliente</option>
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
                    <div class="col-3 col-md-3" v-if="condicion_especial !== 'exportacion' && tipoEsFiscal">
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


                    <!-- Sucursal — solo visible con permiso can_switch_branch; -->
                    <!-- el resto de usuarios emite siempre desde su sucursal fija (users.branch_id). -->
                    <div class="col-6 col-md-2" v-if="puedeCambiarSucursal">
                        <label class="form-label mb-1 small fw-semibold text-secondary">
                            <i class="fas fa-store me-1"></i>Sucursal
                        </label>
                        <select v-model="branch_id_seleccionado" class="form-select form-select-sm">
                            <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                    </div>

                    <!-- Comprobante — tipo de documento, autocompletado según cliente -->
                    <!-- Solo lista lo que activo_greenter=true (tipos fiscales soportados -->
                    <!-- hoy por GreenterService) o codigo='NV' Y el usuario puede emitir -->
                    <!-- (permiso emitir_factura/emitir_boleta/emitir_nota_venta) — la -->
                    <!-- serie real la resuelve el backend según sucursal+tipo+moneda. -->
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">
                            <i class="fas fa-file-invoice me-1"></i>Tipo de Documento
                        </label>
                        <select v-model="tipo_comprobante_codigo" class="form-select form-select-sm">
                            <option v-for="t in tiposComprobanteVisibles" :key="t.codigo" :value="t.codigo">
                                {{ t.nombre }}
                            </option>
                        </select>
                        <small class="text-muted d-block" v-if="serieResuelta">
                            Serie: <strong>{{ serieResuelta.serie }}</strong> (próx. correlativo #{{ serieResuelta.siguiente_correlativo }})
                        </small>
                        <small class="text-danger d-block" v-else-if="errorSeriePreview">
                            {{ errorSeriePreview }}
                        </small>
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

                    <!-- Adelantos disponibles del cliente -->
                    <div class="col-12" v-if="condicion_especial === 'anticipo'">
                        <label class="form-label mb-1 small fw-semibold text-secondary">
                            <i class="fas fa-hand-holding-usd me-1"></i>Adelantos disponibles
                        </label>
                        <div v-if="!client_selected" class="text-muted small">
                            Selecciona un cliente primero.
                        </div>
                        <div v-else-if="loadingAdvances" class="text-muted small">
                            <span class="spinner-border spinner-border-sm me-1"></span>Buscando adelantos...
                        </div>
                        <div v-else-if="availableAdvances.length === 0" class="text-muted small">
                            Este cliente no tiene adelantos disponibles.
                        </div>
                        <table v-else class="table table-sm table-bordered align-middle mb-1">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:36px;"></th>
                                    <th>Adelanto</th>
                                    <th class="text-end" style="width:130px;">Disponible</th>
                                    <th style="width:140px;">Monto a aplicar</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="a in availableAdvances" :key="a.id">
                                    <td>
                                        <input type="checkbox" v-model="a.seleccionado" @change="onAdvanceToggle(a)">
                                    </td>
                                    <td>
                                        Adelanto #{{ a.id }}
                                        ({{ a.currency === 'USD' ? 'US$' : 'S/' }} {{ Number(a.amount).toFixed(2) }})
                                    </td>
                                    <td class="text-end">
                                        {{ a.currency === 'USD' ? 'US$' : 'S/' }} {{ a.available_balance.toFixed(2) }}
                                    </td>
                                    <td>
                                        <input type="number" class="form-control form-control-sm" min="0"
                                            :max="a.available_balance" step="0.01" v-model.number="a.monto_aplicado"
                                            :disabled="!a.seleccionado">
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <small class="text-danger d-block" v-if="totalAdelantosAplicados() > sale_total">
                            La suma de adelantos aplicados no puede superar el total de la venta.
                        </small>
                        <small class="text-muted d-block" v-if="totalAdelantosAplicados() > 0">
                            Total a pagar con adelanto aplicado:
                            {{ currency === 'USD' ? 'US$' : 'S/' }}
                            {{ Math.max(0, sale_total - totalAdelantosAplicados()).toFixed(2) }}
                        </small>
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
                                <tr v-if="condicion_especial == 'anticipo'" class="bg-primary text-white">
                                    <td class="fw-bold fs-6 ps-2">MONTO A PAGAR - ANTICIPOS</td>
                                    <td class="fw-bold fs-6 text-end pe-2">
                                        {{ currency === 'USD' ? 'US$' : 'S/' }}
                                        {{ Math.max(0, sale_total - totalAdelantosAplicados()).toFixed(2) }}
                                    </td>
                                </tr>
                                <tr v-else class="bg-primary text-white">
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
                    <div class="col-12" v-if="type_payment == 2">
                        <p class="small text-muted mb-0">
                            <i class="fas fa-info-circle me-1"></i>
                            Esto registra un <strong>pago inicial (cuota inicial) opcional</strong>, no el cobro
                            completo de la venta — se descuenta del monto que se financiará con el cronograma de
                            cuotas. El seguimiento de cobros de la venta a crédito se hace después, desde
                            Cuentas por Cobrar.
                        </p>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">
                            {{ type_payment == 2 ? 'Método (pago inicial)' : 'Método' }}
                        </label>
                        <select v-model="method_payment" class="form-select form-select-sm">
                            <option v-for="pm in paymentMethods" :key="pm.id" :value="pm.code">{{ pm.name }}</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">
                            {{ type_payment == 2 ? 'Monto (pago inicial)' : 'Monto' }}
                        </label>
                        <b-input-group size="sm">
                            <b-input-group-text>{{ currency }}</b-input-group-text>
                            <b-form-input type="number" v-model.number="amount" placeholder="0.00" size="sm" />
                        </b-input-group>
                    </div>
                    <div class="col-6 col-md-2">
                        <button type="button" class="btn btn-primary btn-sm w-100 fw-semibold" @click="addPayment()">
                            <i class="fas fa-plus me-1"></i>{{ type_payment == 2 ? 'Agregar Pago Inicial' : 'Agregar Pago' }}
                        </button>
                    </div>
                </div>

                <!-- Configuración de Crédito (Módulo Amortizaciones — Fase 8) -->
                <div class="row g-3 align-items-end pb-3 border-bottom mb-3" v-if="type_payment == 2">
                    <div class="col-12">
                        <div class="alert alert-warning py-2 px-3 mb-2 small">
                            <i class="fas fa-info-circle me-1"></i>
                            Venta a crédito — genera un cronograma de cuotas fijas (módulo de
                            Amortizaciones). Por ahora solo se soporta <strong>cuotas fijas</strong>
                            desde este formulario.
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">N° de cuotas</label>
                        <b-form-input type="number" v-model.number="num_cuotas" min="1" max="60" size="sm" />
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Periodicidad</label>
                        <select v-model="periodicidad" class="form-select form-select-sm">
                            <option value="mensual">Mensual</option>
                            <option value="quincenal">Quincenal</option>
                            <option value="semanal">Semanal</option>
                            <option value="personalizada">Personalizada (fechas manuales)</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <button type="button" class="btn btn-outline-primary btn-sm w-100 fw-semibold"
                            :disabled="generandoCronograma" @click="generarCronogramaSugerido()">
                            <i class="fas fa-magic me-1"></i>Generar cronograma sugerido
                        </button>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="form-check form-switch pt-4">
                            <input class="form-check-input" type="checkbox" role="switch" id="aplica-mora"
                                v-model="aplica_mora">
                            <label class="form-check-label small fw-semibold text-secondary"
                                for="aplica-mora">Aplica mora</label>
                        </div>
                    </div>
                    <template v-if="aplica_mora">
                        <div class="col-6 col-md-2">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Tasa de mora</label>
                            <b-form-input type="number" v-model.number="tasa_mora" step="0.01" min="0" size="sm" />
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label mb-1 small fw-semibold text-secondary">Tipo de mora</label>
                            <select v-model="tipo_mora" class="form-select form-select-sm">
                                <option value="fijo_por_cuota">Monto fijo por cuota vencida</option>
                                <option value="porcentaje_diario">% diario sobre saldo vencido</option>
                                <option value="porcentaje_fijo_unico">% fijo único sobre saldo vencido</option>
                            </select>
                        </div>
                    </template>

                    <div class="col-12" v-if="cronograma.length > 0">
                        <p class="small fw-semibold text-secondary mb-1">
                            <i class="fas fa-calendar-check me-1"></i>Cronograma de cuotas
                        </p>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle mb-0">
                                <thead class="table-light">
                                    <tr class="small text-secondary text-uppercase">
                                        <th>#</th>
                                        <th>Monto</th>
                                        <th>Fecha de vencimiento</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(c, idx) in cronograma" :key="idx">
                                        <td class="small text-muted">{{ c.numero_cuota }}</td>
                                        <td>
                                            <b-input-group size="sm">
                                                <b-input-group-text>{{ currency }}</b-input-group-text>
                                                <b-form-input type="number" v-model.number="c.monto_programado"
                                                    step="0.01" size="sm" />
                                            </b-input-group>
                                        </td>
                                        <td>
                                            <b-form-input type="date" v-model="c.fecha_vencimiento" size="sm" />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="small mt-1"
                            :class="Math.abs(sumaCronograma() - montoAFinanciar()) < 0.01 ? 'text-success' : 'text-danger fw-bold'">
                            Suma cronograma: {{ currency }} {{ sumaCronograma().toFixed(2) }}
                            / Monto a financiar: {{ currency }} {{ montoAFinanciar().toFixed(2) }}
                        </div>
                    </div>
                </div>

                <!-- Lista de pagos + observaciones + botones -->
                <div class="row g-3 align-items-start">
                    <!-- Tabla de pagos -->
                    <div class="col-12 col-md-5">
                        <p class="small fw-semibold text-secondary mb-1">
                            <i class="fas fa-list-ul me-1"></i>
                            {{ type_payment == 2 ? 'Pago(s) inicial(es) registrado(s)' : 'Pagos registrados' }}
                        </p>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr class="small text-secondary text-uppercase">
                                        <th>#</th>
                                        <th>Método</th>
                                        <th class="text-end">Monto</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-if="sale_payments.length === 0">
                                        <td colspan="4" class="text-center text-muted fst-italic py-3 small">
                                            {{ type_payment == 2 ? 'Sin pago inicial' : 'Sin pagos aún' }}
                                        </td>
                                    </tr>
                                    <tr v-for="(p, index) in sale_payments" :key="index">
                                        <td class="text-muted small">{{ index + 1 }}</td>
                                        <td class="fw-semibold small">{{ p.method_payment }}</td>
                                        <td class="text-end fw-bold">{{ currency }} {{ p.amount }}</td>
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
import { ref, computed, watch, onMounted } from 'vue';
import type { AxiosResponse } from 'axios';
import httpClient from '@/helpers/http-client';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import type { SaleDetail, SaleConfig, SaleResponse } from '@/types/sales';
import type { PaymentMethod, PaymentMethods } from '@/types/cash';
import type { ClientAdvance } from '@/types/advances';
import type { Client } from '@/types/clients';
import type { Product } from '@/types/products';
import ClientFormQuick from '@/components/Sales/ClientFormQuick.vue';
import ProductFormQuick from '@/components/Sales/ProductFormQuick.vue';
import { imprimirComprobante } from '@/composables/usePrintComprobante';
import { getPrecioBaseSinIgv } from '@/composables/useProductPricing';
import { useAuthStore } from '@/stores/auth';
import { resolverTipAfeIgv as resolverTipAfeIgvPuro } from '@/utils/resolverTipAfeIgv';
import type { Branch } from '@/types/cash-session';
import type { TipoComprobante } from '@/types/series-comprobante';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const authStore = useAuthStore();

// Mapa tipo_comprobante_codigo → permiso Spatie que habilita emitirlo desde
// este formulario — mismo mapa que SaleController::PERMISOS_EMISION en el
// backend (fuente de verdad real: el backend rechaza con 422 aunque este
// filtro del frontend se saltee de alguna forma). '07'/'08' (NC/ND) no
// tienen entrada acá a propósito — se emiten desde el flujo de Notas, no
// desde este formulario.
const PERMISOS_EMISION: Record<string, string> = {
    '01': 'emitir_factura',
    '03': 'emitir_boleta',
    'NV': 'emitir_nota_venta',
};

// ── Datos maestros ─────────────────────────────────────────────────
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

// ── Búsqueda de productos ─────────────────────────────────────────
const productSearchText = ref<string>('');
const productSuggestions = ref<Product[]>([]);
let productSearchTimeout: any = null;
const productNotFound = ref<boolean>(false);

// ── Modales rápidos ───────────────────────────────────────────────
const showQuickClientModal = ref<boolean>(false);
const quickClientData = ref<any>(null);
const showQuickProductModal = ref<boolean>(false);
const quickProductData = ref<any>(null);

// ── Configuración de la transacción ──────────────────────────────
const state_sale = ref<number>(1);     // 1=venta, 2=cotización
const is_exportacion = ref<number>(0);
const n_transaction = ref<string>('');
const today = ref<string>('');
// Módulo de series de comprobantes — reemplaza el 'serie' de texto libre de
// antes. La serie real (F001/B001/NV001/...) la resuelve el backend según
// sucursal+tipo+moneda (SerieComprobanteService::resolverSerie()) — este
// formulario solo elige el TIPO, nunca la serie directamente.
const tipo_comprobante_codigo = ref<string>('03'); // default boleta — cambia según cliente
const tiposComprobante = ref<TipoComprobante[]>([]);
const branches = ref<Branch[]>([]);
const branch_id_seleccionado = ref<number | undefined>(undefined);

const puedeCambiarSucursal = computed(() => authStore.isPermitedRoute('can_switch_branch'));

// Solo lo que el catálogo marca disponible para crear series
// (activo_greenter=true O 'NV', ya filtrado por el backend en
// ?disponibles_para_serie=1) Y que el usuario autenticado puede emitir
// (permiso emitir_*) — un cajero con solo emitir_nota_venta ni siquiera ve
// factura/boleta como opción.
const tiposComprobanteVisibles = computed(() =>
    tiposComprobante.value.filter((t) => {
        const permiso = PERMISOS_EMISION[t.codigo];
        return permiso ? authStore.isPermitedRoute(permiso) : false;
    })
);

const tipoEsFiscal = computed(() => {
    const tipo = tiposComprobante.value.find((t) => t.codigo === tipo_comprobante_codigo.value);
    return tipo ? tipo.es_documento_sunat : true; // default true: no ocultar de más antes de cargar el catálogo
});

// Preview en vivo de la serie que va a resolver el backend — el <select>
// viejo mostraba "F001 — Factura" directamente; el selector de tipo de
// documento nuevo no dice nada por sí solo sobre QUÉ serie/correlativo se
// va a usar. Se recalcula cada vez que cambia tipo/moneda/sucursal.
const serieResuelta = ref<{ serie: string; siguiente_correlativo: number } | null>(null);
const errorSeriePreview = ref<string>('');
let previewSerieTimeout: any = null;

const previsualizarSerie = async () => {
    if (!tipo_comprobante_codigo.value) {
        serieResuelta.value = null;
        return;
    }

    try {
        const params = new URLSearchParams();
        params.set('tipo_comprobante_codigo', tipo_comprobante_codigo.value);
        params.set('currency', currency.value);
        if (puedeCambiarSucursal.value && branch_id_seleccionado.value) {
            params.set('branch_id', String(branch_id_seleccionado.value));
        }

        const res = await httpClient.get<{ serie: string; siguiente_correlativo: number }>(
            `sales/serie-preview?${params.toString()}`
        );
        serieResuelta.value = res.data;
        errorSeriePreview.value = '';
    } catch (error: any) {
        serieResuelta.value = null;
        errorSeriePreview.value = error.response?.data?.message ?? 'No se pudo resolver la serie para este tipo de documento.';
    }
};

const type_operation = ref<string>('10');
const destino = ref<string>('amazonia'); // default Amazonía — empresa en San Martín

// ── Condiciones especiales de venta ─────────────────────────────
// '0'=ninguna, 'anticipo', '1'=retención, '2'=detracción, '3'=percepción
const condicion_especial = ref<string>('0');

// ── Adelantos disponibles del cliente seleccionado ───────────────
type AdvanceCheckoutItem = ClientAdvance & { seleccionado: boolean; monto_aplicado: number };
const availableAdvances = ref<AdvanceCheckoutItem[]>([]);
const loadingAdvances = ref<boolean>(false);

// Detracción — selección del código del Anexo SUNAT
const codigo_detraccion_sel = ref<string>('');
const porcentaje_detraccion_sel = ref<number>(0);
const monto_detraccion = ref<number>(0);

// Retención — tasa desde tax_params
const porcentaje_retencion_sel = ref<number>(0.03);
const monto_retencion = ref<number>(0);
const retencion_igv = ref<number>(0);

// Percepción — tasa desde tax_params
const porcentaje_percepcion_sel = ref<number>(0.02);
const monto_percepcion = ref<number>(0);

// ── Parámetros tributarios desde BD (no hardcode) ─────────────────
// Se cargan en config() desde sales/config → tax_configs
const parametros_tributarios = ref<Record<string, any[]>>({});
const codigos_detraccion = ref<any[]>([]);

// Helpers para leer las tasas desde la BD — con fallback seguro
//const getIgvGeneralTasa = (): number =>
//  Number(parametros_tributarios.value['IGV']?.find((t: any) => !t.ubigeos_aplicables)?.tasa_porcentaje ?? 18);

const getIgvGeneralTasa = (): number => {
    const igv_default = parametros_tributarios.value['IGV']
        ?.find((t: any) => t.es_default);
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
const currency_symbol = ref<string>('S/.'); // S/. o $ para mostrar



// ── Detalle y pagos ───────────────────────────────────────────────
const sale_details = ref<SaleDetail[]>([]);
const currency = ref<string>('PEN');   // código ISO — Greenter requiere 'PEN' o 'USD'
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
const sale_payments = ref<any[]>([]);

// Módulo Caja — Fase 0 (plan-modulo-caja.md §3): picker dinámico en vez del
// <option> hardcodeado. method_payment sigue enviándose igual que antes
// (el 'code' tal cual, ej. "YAPE") — cero cambios en el payload de la venta.
const paymentMethods = ref<PaymentMethod[]>([]);

// ── Configuración de Crédito (Módulo Amortizaciones — Fase 8) ─────
// Solo credit_type='cuotas_fijas' — 'libre' queda fuera de alcance de
// esta fase (decisión explícita, plan-modulo-amortizaciones.md §6 pto 8).
const credit_type = ref<string>('cuotas_fijas');
const num_cuotas = ref<number>(3);
const periodicidad = ref<string>('mensual');
const cronograma = ref<{ numero_cuota: number; monto_programado: number; fecha_vencimiento: string }[]>([]);
const aplica_mora = ref<boolean>(false);
const tasa_mora = ref<number>(0);
const tipo_mora = ref<string>('porcentaje_diario');
const generandoCronograma = ref<boolean>(false);
const company_mora_defaults = ref<{ mora_habilitada_default: boolean; tasa_mora_default: number | null; tipo_mora_default: string | null }>({
    mora_habilitada_default: false,
    tasa_mora_default: null,
    tipo_mora_default: null,
});

// Totales
const igv_total = ref<number>(0);
const sale_total = ref<number>(0);
const sale_subtotal = ref<number>(0);
const icbper_total = ref<number>(0);
const isc_total = ref<number>(0);
const total_payments = ref<number>(0);
// Subtotal bruto de las líneas cobrables (excluye gratuitas) — lo calcula
// sumDetails() y lo reutiliza store() para netear mto_oper_* del descuento
// global con la misma proporción que ya usa el IGV.
const subtotalBrutoCobro = ref<number>(0);

// ── Métodos de cliente ────────────────────────────────────────────

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

    // ── Autocompletar tipo de documento según tipo de documento del cliente ──
    // RUC → Factura ('01'), DNI y otros → Boleta ('03') — solo si el usuario
    // puede emitir ese tipo; si no, se deja el que ya estaba seleccionado.
    const tipoSugerido = client.type_document === 'RUC' ? '01' : '03';
    if (tiposComprobanteVisibles.value.some((t) => t.codigo === tipoSugerido)) {
        tipo_comprobante_codigo.value = tipoSugerido;
    }

    // ── Sugerir destino según zona del cliente ─────────────────────
    // Si el cliente es de zona Amazonía, pre-seleccionar 'amazonia'
    // El vendedor puede cambiarlo si el pedido se envía fuera
    if (client.es_amazonia) {
        destino.value = 'amazonia';
    }
    // Si no es Amazonía, no cambiar automáticamente — puede ser
    // un cliente de Lima que compra acá o uno local que envía fuera

    cargarAdelantosDisponibles(client.id);
};

const clearClientSelection = () => {
    client_selected.value = undefined;
    clientSearchText.value = '';
    clientSuggestions.value = [];
    client_address.value = '';
    clientNotFound.value = false;
    tipo_comprobante_codigo.value = '03'; // resetear a boleta por defecto
    availableAdvances.value = [];
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
// Lógica pura extraída a src/utils/resolverTipAfeIgv.ts (testeada ahí
// con Vitest) — acá solo se cierra sobre los refs de esta vista.
const resolverTipAfeIgv = (product: Product): string =>
    resolverTipAfeIgvPuro(product, destino.value, is_exportacion.value);

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

// ── Cálculos de cada ítem ─────────────────────────────────────────
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

// ── Subtotal de línea neto del descuento global (prorrateado) ────────
// Único punto de cálculo para "subtotal de línea ya con el descuento
// global aplicado" — lo usan tanto el IGV (sumDetails()) como los
// mto_oper_* que se mandan a SUNAT (store()). Antes cada uno lo
// calculaba por separado y solo el IGV restaba el descuento; ese
// desfase entre el total (neto) y mto_oper_exoneradas/gravadas/etc.
// (brutos) causaba el error SUNAT 3275 en ventas con descuento global.
const subtotalNetoDescuentoGlobal = (d: SaleDetail, subtotalBruto: number): number => {
    const descuento = Number(discount_global.value) || 0;
    const proporcion = subtotalBruto > 0 ? d.subtotal / subtotalBruto : 0;
    return d.subtotal - (descuento * proporcion);
};

const sumDetails = () => {
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
    subtotalBrutoCobro.value = subtotal_bruto;
    const descuento_global_actual = Number(discount_global.value) || 0;
    igv_total.value = Number(lineas_cobro.reduce((s, d) => {
        const base_neta = subtotalNetoDescuentoGlobal(d, subtotal_bruto);
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
    total_payments.value = Number(sale_payments.value.reduce((s, p) => s + p.amount, 0).toFixed(2));

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

// ── Adelantos disponibles del cliente ────────────────────────────
const cargarAdelantosDisponibles = async (clientId: number | string) => {
    loadingAdvances.value = true;
    availableAdvances.value = [];
    try {
        const { data } = await httpClient.get(`clients/${clientId}/advances`);
        availableAdvances.value = (data.advances ?? []).map((a: ClientAdvance) => ({
            ...a,
            seleccionado: false,
            monto_aplicado: 0,
        }));
    } catch {
        // Silencioso — un fallo acá no debe bloquear el registro de la venta.
        availableAdvances.value = [];
    } finally {
        loadingAdvances.value = false;
    }
};

const onAdvanceToggle = (a: AdvanceCheckoutItem) => {
    a.monto_aplicado = a.seleccionado ? a.available_balance : 0;
};

const totalAdelantosAplicados = (): number => {
    return availableAdvances.value
        .filter(a => a.seleccionado)
        .reduce((s, a) => s + (Number(a.monto_aplicado) || 0), 0);
};

// ── Configuración de Crédito (Módulo Amortizaciones — Fase 8) ─────
// Monto que financia el cronograma: total menos adelantos aplicados menos
// pagos iniciales ya ingresados (ej. cuota inicial en efectivo) — mismo
// cálculo que ya usa store() para 'debt', reusado acá para el preview y
// la validación en vivo de la suma del cronograma.
const montoAFinanciar = (): number => {
    const totalAdelantos = condicion_especial.value === 'anticipo' ? totalAdelantosAplicados() : 0;
    return Math.max(0, getTotalSales() - totalAdelantos - total_payments.value);
};

const sumaCronograma = (): number =>
    cronograma.value.reduce((s, c) => s + (Number(c.monto_programado) || 0), 0);

const aplicarDefaultsMora = () => {
    aplica_mora.value = company_mora_defaults.value.mora_habilitada_default;
    tasa_mora.value = company_mora_defaults.value.tasa_mora_default ?? 0;
    tipo_mora.value = company_mora_defaults.value.tipo_mora_default ?? 'porcentaje_diario';
};

const generarCronogramaSugerido = async () => {
    const monto = montoAFinanciar();
    if (monto <= 0) {
        (Swal as TVueSwalInstance).fire('Error', 'No hay monto a financiar (el total ya está cubierto por adelantos/pagos).', 'error');
        return;
    }
    generandoCronograma.value = true;
    try {
        const res = await httpClient.post('installments/schedule-preview', {
            monto_total: monto,
            num_cuotas: num_cuotas.value,
            periodicidad: periodicidad.value,
            fecha_anchor: today.value,
        });
        cronograma.value = (res.data as any).cronograma;
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire('Error', error?.response?.data?.message ?? 'No se pudo generar el cronograma.', 'error');
    } finally {
        generandoCronograma.value = false;
    }
};

// ── Pagos ─────────────────────────────────────────────────────────
const addPayment = () => {
    if (!method_payment.value) {
        (Swal as TVueSwalInstance).fire('Error', 'El método de pago es obligatorio.', 'error'); return;
    }
    if (amount.value <= 0) {
        (Swal as TVueSwalInstance).fire('Error', 'El monto debe ser mayor a 0.', 'error'); return;
    }
    if (getTotalSales() < (amount.value + total_payments.value)) {
        (Swal as TVueSwalInstance).fire('Error', 'El monto no puede superar el total de la venta.', 'error'); return;
    }
    if (method_payment.value === 'EFECTIVO' && sale_payments.value.some(p => p.method_payment === 'EFECTIVO')) {
        (Swal as TVueSwalInstance).fire('Error', 'Ya hay un pago en efectivo registrado.', 'error'); return;
    }

    sale_payments.value.push({
        method_payment: method_payment.value,
        amount: amount.value,
        date_payment: date_payment.value,
    });

    setTimeout(() => {
        method_payment.value = '';
        amount.value = 0;
        date_payment.value = '';
        sumDetails();
    }, 50);
};

const removePayment = (index: number) => {
    sale_payments.value.splice(index, 1);
    sumDetails();
};

// ── Guardar venta ─────────────────────────────────────────────────
const store = async () => {
    if (!client_selected.value) {
        (Swal as TVueSwalInstance).fire({ icon: 'error', title: 'Error', text: 'Selecciona un cliente.' }); return;
    }
    if (sale_details.value.length === 0) {
        (Swal as TVueSwalInstance).fire({ icon: 'error', title: 'Error', text: 'Agrega al menos un producto.' }); return;
    }
    const totalAdelantos = condicion_especial.value === 'anticipo' ? totalAdelantosAplicados() : 0;
    const totalConAdelanto = getTotalSales() - totalAdelantos;

    // Re-validar acá (no solo en addPayment()): el total pudo bajar después
    // de agregar un pago (se quitó una línea, cambió un descuento/régimen),
    // dejando un pago ya agregado por encima del total sin que nada lo avise.
    if (total_payments.value > totalConAdelanto + 0.009) {
        (Swal as TVueSwalInstance).fire({
            icon: 'error', title: 'Error',
            text: `Los pagos (${currency.value} ${total_payments.value.toFixed(2)}) superan el total a pagar ` +
                `(${currency.value} ${totalConAdelanto.toFixed(2)}). Ajusta los pagos antes de guardar.`,
        }); return;
    }

    // Requiere al menos un pago solo si NO es crédito — una venta a
    // crédito puede financiarse 100% vía cronograma sin pago inicial.
    if (state_sale.value === 1 && sale_payments.value.length === 0 && totalConAdelanto > 0 && type_payment.value != 2) {
        (Swal as TVueSwalInstance).fire({ icon: 'error', title: 'Error', text: 'Agrega al menos un pago para la venta.' }); return;
    }
    if (condicion_especial.value === '2' && !codigo_detraccion_sel.value) {
        (Swal as TVueSwalInstance).fire({ icon: 'error', title: 'Error', text: 'Selecciona el código de detracción.' }); return;
    }
    if (condicion_especial.value === 'anticipo') {
        if (totalAdelantos <= 0) {
            (Swal as TVueSwalInstance).fire({ icon: 'error', title: 'Error', text: 'Selecciona al menos un adelanto y un monto a aplicar.' }); return;
        }
        if (totalAdelantos > getTotalSales()) {
            (Swal as TVueSwalInstance).fire({ icon: 'error', title: 'Error', text: 'La suma de adelantos aplicados no puede superar el total de la venta.' }); return;
        }
    }

    // ── Validación de Configuración de Crédito (Módulo Amortizaciones — Fase 8) ──
    // Espeja la validación de validarConfiguracionCredito() en el backend
    // (SaleController.php) para dar feedback inmediato — el backend sigue
    // siendo la fuente de verdad, esto solo evita un viaje HTTP inútil.
    if (type_payment.value == 2) {
        if (credit_type.value !== 'cuotas_fijas') {
            (Swal as TVueSwalInstance).fire({ icon: 'error', title: 'Error', text: 'Solo se soporta crédito con cuotas fijas por ahora.' }); return;
        }
        if (cronograma.value.length === 0) {
            (Swal as TVueSwalInstance).fire({ icon: 'error', title: 'Error', text: 'Genera el cronograma de cuotas antes de guardar.' }); return;
        }
        if (Math.abs(sumaCronograma() - montoAFinanciar()) > 0.01) {
            (Swal as TVueSwalInstance).fire({
                icon: 'error', title: 'Error',
                text: `La suma del cronograma (${currency.value} ${sumaCronograma().toFixed(2)}) no calza con el monto ` +
                    `a financiar (${currency.value} ${montoAFinanciar().toFixed(2)}).`,
            }); return;
        }
        if (aplica_mora.value && (!tasa_mora.value || tasa_mora.value <= 0)) {
            (Swal as TVueSwalInstance).fire({ icon: 'error', title: 'Error', text: 'Si aplica mora, la tasa debe ser mayor a 0.' }); return;
        }
    }

    // Estado de pago derivado automáticamente (considera lo ya cubierto por adelantos)
    let estado_pago = 1; // pendiente
    if (state_sale.value === 1) {
        if (total_payments.value >= totalConAdelanto) estado_pago = 3;      // pagado completo
        else if (total_payments.value > 0 || totalAdelantos > 0) estado_pago = 2;      // pago parcial
    }

    // Mapear condicion_especial → retencion_igv (campo de la BD)
    const retencion_igv_bd = condicion_especial.value === 'anticipo' ? 0
        : ['1', '2', '3'].includes(condicion_especial.value) ? Number(condicion_especial.value)
            : 0;

    const data = {
        // Identificación
        tipo_comprobante_codigo: tipo_comprobante_codigo.value,
        ...(puedeCambiarSucursal.value && branch_id_seleccionado.value
            ? { branch_id: branch_id_seleccionado.value }
            : {}),
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

        // Adelantos aplicados — el backend valida saldo/pertenencia al
        // cliente y neta el total del comprobante (ver módulo Adelantos)
        advance_applications: availableAdvances.value
            .filter(a => a.seleccionado && a.monto_aplicado > 0)
            .map(a => ({ advance_id: a.id, amount: a.monto_aplicado })),

        // Totales calculados (el backend no recalcula — usa estos valores)
        subtotal: sale_subtotal.value,
        igv: igv_total.value,
        total: sale_total.value,
        discount: discount_total.value,
        discount_global: discount_global.value,
        igv_discount_general: 0,  // deprecated — mantener por compatibilidad

        // Totales por tipo de operación para Greenter — netos del descuento
        // global (prorrateado, ver subtotalNetoDescuentoGlobal()). SUNAT
        // rechaza (error 3275) si estos no cuadran con el total, que ya
        // sale neto del descuento (ver getSubTotalSale()).
        mto_oper_gravadas: sale_details.value.filter(d => d.tip_afe_igv === '10')
            .reduce((s, d) => s + subtotalNetoDescuentoGlobal(d, subtotalBrutoCobro.value), 0),
        mto_oper_exoneradas: sale_details.value.filter(d => d.tip_afe_igv === '20')
            .reduce((s, d) => s + subtotalNetoDescuentoGlobal(d, subtotalBrutoCobro.value), 0),
        mto_oper_inafectas: sale_details.value.filter(d => d.tip_afe_igv === '30')
            .reduce((s, d) => s + subtotalNetoDescuentoGlobal(d, subtotalBrutoCobro.value), 0),
        mto_oper_exportacion: sale_details.value.filter(d => d.tip_afe_igv === '40')
            .reduce((s, d) => s + subtotalNetoDescuentoGlobal(d, subtotalBrutoCobro.value), 0),
        // Gratuitas quedan brutas — no llevan descuento, están excluidas de
        // lineas_cobro/subtotal_bruto en sumDetails().
        mto_oper_gratuitas: sale_details.value.filter(d => d.tip_afe_igv === '11').reduce((s, d) => s + d.subtotal, 0),

        // Impuestos desagregados
        isc_total: isc_total.value,
        icbper_total: icbper_total.value,
        ivap_total: sale_details.value.filter(d => d.tip_afe_igv === '17').reduce((s, d) => s + d.igv, 0),
        total_impuestos: sale_details.value.reduce((s, d) => s + d.total_impuestos, 0),
        valor_venta: getSubTotalSale(),
        mto_imp_venta: Math.round(getTotalFactura() * 10) / 10,
        redondeo: Math.round((Math.round(getTotalFactura() * 10) / 10 - getTotalFactura()) * 100) / 100,

        // Estado y deuda — netos del adelanto aplicado (el dinero del
        // adelanto ya se recibió antes, en su propio comprobante)
        state_payment: estado_pago,
        debt: Math.max(0, totalConAdelanto - total_payments.value),
        paid_out: total_payments.value + totalAdelantos,

        // Detalles — incluyen todos los campos para Greenter
        sale_details: sale_details.value,

        // Pagos
        payments: sale_payments.value,

        description: description.value,

        // Configuración de Crédito (Módulo Amortizaciones — Fase 8) — solo
        // se manda si es venta a crédito, mismo criterio que el backend
        // usa para derivar condicion_pago de type_payment.
        ...(type_payment.value == 2 ? {
            credit_type: credit_type.value,
            cronograma: cronograma.value,
            aplica_mora: aplica_mora.value,
            tasa_mora: aplica_mora.value ? tasa_mora.value : null,
            tipo_mora: aplica_mora.value ? tipo_mora.value : null,
        } : {}),
    };

    try {
        const resp: AxiosResponse<SaleResponse> = await httpClient.post('sales', data);

        if (resp.data.error) {
            (Swal as TVueSwalInstance).fire('Error interno', resp.data.error.message, 'error');
            return;
        }
        if (resp.data.code === 200) {
            await (Swal as TVueSwalInstance).fire('¡Listo!', resp.data.message, 'success');
            if (resp.data.sale_id) {
                const formatoDefault = useAuthStore().getUser()?.formato_impresion_default ?? 'a4';
                await imprimirComprobante(resp.data.sale_id, formatoDefault);
            }
            resetData();
        }
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire('Error', e.response?.data?.message ?? 'Error inesperado', 'error');
    }
};

// ── Limpiar formulario ─────────────────────────────────────────────
const resetData = () => {
    client_selected.value = undefined;
    clientSearchText.value = '';
    clientSuggestions.value = [];
    client_address.value = '';
    clientNotFound.value = false;
    productSearchText.value = '';
    productSuggestions.value = [];
    productNotFound.value = false;
    sale_details.value = [];
    sale_payments.value = [];
    discount_total.value = 0;
    sale_subtotal.value = 0;
    sale_total.value = 0;
    igv_total.value = 0;
    icbper_total.value = 0;
    isc_total.value = 0;
    total_payments.value = 0;
    description.value = '';
    condicion_especial.value = '0';
    monto_retencion.value = 0;
    monto_detraccion.value = 0;
    monto_percepcion.value = 0;
    codigo_detraccion_sel.value = '';
    porcentaje_detraccion_sel.value = 0;
    discount_global.value = 0;
    availableAdvances.value = [];
    tipo_comprobante_codigo.value = '03';
    is_exportacion.value = 0;
    currency.value = 'PEN';
    destino.value = 'amazonia';
    default_quantity.value = 1;
    default_price.value = 0;
    state_sale.value = 1;
    type_payment.value = 1;
    method_payment.value = 'EFECTIVO';
    amount.value = 0;
    date_payment.value = '';
    credit_type.value = 'cuotas_fijas';
    num_cuotas.value = 3;
    periodicidad.value = 'mensual';
    cronograma.value = [];
    aplicarDefaultsMora();
    sumDetails();
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

// ── Configuración inicial ─────────────────────────────────────────
const config = async () => {
    try {
        const res: AxiosResponse<SaleConfig> = await httpClient.get('sales/config');
        n_transaction.value = res.data.n_transaction;
        today.value = res.data.today;
        if (products.value.length === 0) {
            clients.value = res.data.clients.data;
            products.value = res.data.products.data;
        }
        // Cargar parámetros tributarios desde BD — no hardcode
        parametros_tributarios.value = (res.data as any).parametros_tributarios ?? {};
        codigos_detraccion.value = (res.data as any).codigos_detraccion ?? [];

        // Inicializar tasas de regímenes desde BD
        porcentaje_retencion_sel.value = getRetencionTasa();
        porcentaje_percepcion_sel.value = getPercepcionTasa();

        // Defaults de mora de la empresa (Módulo Amortizaciones — Fase 8) —
        // prellenan el checkbox aplica_mora de Configuración de Crédito,
        // editable por venta puntual.
        company_mora_defaults.value = (res.data as any).company_mora_defaults ?? company_mora_defaults.value;
        aplicarDefaultsMora();
    } catch (error) {
        console.error(error);
    }
};

// Módulo Caja — Fase 0 (plan-modulo-caja.md §3). Endpoint propio (no
// sales/config) — este catálogo no es exclusivo de ventas.
const loadPaymentMethods = async () => {
    try {
        const res: AxiosResponse<PaymentMethods> = await httpClient.get('payment-methods?active=1');
        paymentMethods.value = res.data.payment_methods;
    } catch (error) {
        console.error(error);
    }
};

// ── Módulo de series de comprobantes ──────────────────────────────
const cargarTiposYSucursales = async () => {
    try {
        const resTipos = await httpClient.get<{ tipos_comprobante: TipoComprobante[] }>(
            'tipos-comprobante?disponibles_para_serie=1'
        );
        tiposComprobante.value = resTipos.data.tipos_comprobante;

        if (puedeCambiarSucursal.value) {
            const resBranches = await httpClient.get<{ branches: Branch[] }>('branches?active=1');
            branches.value = resBranches.data.branches;
            branch_id_seleccionado.value = branches.value[0]?.id;
        }

        previsualizarSerie();
    } catch (error) {
        console.error(error);
    }
};

// ── Limpieza de campos fiscales al cambiar de tipo de documento ────
// Mismo principio que "Configuración de Crédito": regenerar en vez de
// conservar estado potencialmente inconsistente. Se limpia SIEMPRE que el
// tipo cambia (no solo al entrar/salir de nota de venta) — si el usuario
// vuelve a un tipo fiscal, los campos aparecen vacíos, nunca con el valor
// que tenían antes de ocultarse.
const resetCamposFiscales = () => {
    condicion_especial.value = '0';
    destino.value = 'amazonia';
    is_exportacion.value = 0;
    codigo_detraccion_sel.value = '';
    porcentaje_detraccion_sel.value = 0;
    monto_detraccion.value = 0;
    monto_retencion.value = 0;
    monto_percepcion.value = 0;
};

watch(tipo_comprobante_codigo, () => resetCamposFiscales());

// Debounce corto: currency/branch_id_seleccionado pueden cambiar seguido
// (ej. el usuario tipeando), tipo_comprobante_codigo cambia por selección
// directa — todos disparan la misma previsualización.
watch([tipo_comprobante_codigo, currency, branch_id_seleccionado], () => {
    clearTimeout(previewSerieTimeout);
    previewSerieTimeout = setTimeout(previsualizarSerie, 200);
});

// ── Watchers ──────────────────────────────────────────────────────
watch(discount_global, () => sumDetails());
watch(retencion_igv, () => sumDetails()); // compatibilidad

watch(condicion_especial, (valor) => {
    // Activar exportación si se seleccionó esa condición
    is_exportacion.value = valor === 'exportacion' ? 1 : 0;

    // Recalcular todos los ítems porque el tip_afe_igv cambia
    sale_details.value.forEach((item, index) => {
        item.tip_afe_igv = resolverTipAfeIgv(item.product);
        updateItem(index);
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
    // Código ISO para Greenter: 'PEN' = soles, 'USD' = dólares
    currency.value = value === 1 ? 'USD' : 'PEN';
    sale_details.value = [];
    discount_global.value = 0;
    sumDetails();
});

watch(destino, () => {
    // Cuando cambia el destino, recalcular tip_afe_igv de todos los ítems
    // porque la exoneración por Ley 27037 depende del destino
    sale_details.value.forEach((item, index) => {
        item.tip_afe_igv = resolverTipAfeIgv(item.product);
        updateItem(index);
    });
    sumDetails();
});

// También vigilar cambios en moneda para actualizar el símbolo
watch(currency_iso, (nuevo) => {
    currency_symbol.value = nuevo === 'USD' ? '$' : 'S/.';
});

onMounted(() => {
    config();
    loadPaymentMethods();
    cargarTiposYSucursales();
});
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
