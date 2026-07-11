<template>
  <DefaultLayout>
    <b-card class="p-0">
      <RegisterLayout title="Registrar Producto" @save="store" @cancel="goToList" />

      <b-card-body class="pt-3">
        <!-- 🔵 TABS -->
        <b-tabs card>

          <!-- 🟢 TAB GENERAL -->
          <b-tab title="General" active>
            <b-row class="mt-3 g-3">
              <!-- Nombre -->
              <b-col lg="4" md="6">
                <label for="name-product" class="fw-semibold">Nombre del Producto <span
                    class="text-danger">*</span></label>
                <b-form-input id="name-product" type="text" v-model="title"
                  @input="title = $event.target.value.toUpperCase()" placeholder="Ej: Laptop HP" />
              </b-col>

              <!-- SKU -->
              <b-col lg="4" md="6">
                <label for="sku-product" class="fw-semibold">SKU <span class="text-danger">*</span></label>
                <b-form-input id="sku-product" type="text" v-model="sku"
                  @input="sku = $event.target.value.toUpperCase()" placeholder="Ej: FE4RFF" />
              </b-col>

              <!-- Categoría -->
              <b-col lg="4" md="6">
                <label class="fw-semibold">Categoría <span class="text-danger">*</span></label>
                <b-form-select v-model="categorie_id">
                  <option value="">Selecc. Categoría</option>
                  <option v-for="cat in categories" :key="cat.id" :value="cat.id">
                    {{ cat.title }}
                  </option>
                </b-form-select>
              </b-col>
            </b-row>

            <!-- Precios e IGV -->
            <b-row class="mt-3 g-3 align-items-end">
              <b-col lg="3" md="6">
                <label for="price_general" class="fw-semibold">Precio Cliente Final <span
                    class="text-danger">*</span></label>
                <b-input-group>
                  <b-input-group-text>S/.</b-input-group-text>
                  <b-form-input id="price_general" type="number" step="0.01" v-model="price_general"
                    placeholder="150.00" />
                </b-input-group>
              </b-col>

              <b-col lg="3" md="6">
                <label for="price_company" class="fw-semibold">Precio Cliente Empresa</label>
                <b-input-group>
                  <b-input-group-text>S/.</b-input-group-text>
                  <b-form-input id="price_company" type="number" step="0.01" v-model="price_company"
                    placeholder="250.00" />
                </b-input-group>
              </b-col>

              <b-col lg="3" md="6">
                <label class="fw-semibold">Incluye IGV</label>
                <div class="d-flex gap-3">
                  <b-form-radio name="igv" @click="include_igv = 0" :checked="include_igv == 0" value="1">
                    No
                  </b-form-radio>
                  <b-form-radio name="igv" @click="include_igv = 1" :checked="include_igv == 1" value="2">
                    Sí
                  </b-form-radio>
                </div>
              </b-col>

              <b-col lg="3" md="6">
                <div class="p-2 bg-light rounded border">
                  <small class="d-block text-muted">Precio Base (sin IGV)</small>
                  <div class="fw-bold">C.F: S/ {{ getPriceBaseCF() }}</div>
                  <div class="fw-bold">C.E: S/ {{ getPriceBaseCE() }}</div>
                  <small class="text-muted">Tasa usada: {{ getTasaActual() }}%</small>
                </div>
              </b-col>
            </b-row>

            <!-- Descripción, Unidad, Stock -->
            <b-row class="mt-3 g-3">
              <b-col lg="5" md="6">
                <label for="description" class="fw-semibold">Descripción <span class="text-danger">*</span></label>
                <b-form-textarea id="description" rows="3" v-model="description"
                  placeholder="Descripción detallada del producto..." />
              </b-col>

              <b-col lg="3" md="6">
                <label class="fw-semibold">Unidad <span class="text-danger">*</span></label>
                <b-form-select v-model="unidad_medida">
                  <option value="">Selecc. Unidad</option>
                  <option v-for="unit in units" :key="unit.code" :value="unit.code">
                    {{ unit.name }} ({{ unit.code }})
                  </option>
                </b-form-select>
              </b-col>

              <b-col lg="2" md="6">
                <label for="stock" class="fw-semibold">Stock <span class="text-danger">*</span></label>
                <b-form-input id="stock" type="number" min="0" v-model="stock" placeholder="0" />
              </b-col>

              <b-col lg="2" md="6">
                <label class="fw-semibold">Disponibilidad</label>
                <b-form-radio name="disp" @click="disponiblidad = 1" :checked="disponiblidad == 1" value="1">
                  Vender sin stock
                </b-form-radio>
                <b-form-radio name="disp" @click="disponiblidad = 2" :checked="disponiblidad == 2" value="2">
                  No vender sin stock
                </b-form-radio>
              </b-col>
            </b-row>

            <!-- Imagen -->
            <b-row class="mt-3">
              <b-col lg="6">
                <label class="fw-semibold">Imagen del Producto</label>
                <b-input-group>
                  <b-form-file @change="loadFile($event)" accept="image/*" />
                  <b-input-group-text>Subir</b-input-group-text>
                </b-input-group>
                <div v-if="IMAGEN_PREVIZUALIZA" class="mt-2 text-center">
                  <img :src="IMAGEN_PREVIZUALIZA" width="150" class="rounded border" />
                </div>
              </b-col>
            </b-row>
          </b-tab>

          <!-- 🔧 TAB CONFIGURACIONES -->
          <b-tab title="Configuraciones">
            <!-- Descuento -->
            <b-row class="mt-3 g-3">
              <b-col lg="4" md="6">
                <label class="fw-semibold">Permitir Descuento por Ítem</label>
                <div class="d-flex gap-3">
                  <b-form-radio name="discount" @click="is_discount = 1" :checked="is_discount == 1" value="1">
                    No
                  </b-form-radio>
                  <b-form-radio name="discount" @click="is_discount = 2" :checked="is_discount == 2" value="2">
                    Sí
                  </b-form-radio>
                </div>
              </b-col>

              <b-col lg="3" md="6" v-if="is_discount == 2">
                <label for="max_discount" class="fw-semibold">% Máximo de Descuento</label>
                <b-input-group>
                  <b-form-input id="max_discount" type="number" step="1" min="0" max="100" v-model="max_discount"
                    placeholder="0" />
                  <b-input-group-text>%</b-input-group-text>
                </b-input-group>
              </b-col>
            </b-row>

            <!-- Impuestos y opciones especiales -->
            <b-row class="mt-3 g-3">
              <b-col lg="3" md="6">
                <label class="fw-semibold">Bolsa Plástica (ICBPER)</label>
                <div class="d-flex gap-3">
                  <b-form-radio name="icbper" @click="is_icbper = 1" :checked="is_icbper == 1" value="1">
                    No
                  </b-form-radio>
                  <b-form-radio name="icbper" @click="is_icbper = 2" :checked="is_icbper == 2" value="2">
                    Sí (+S/.{{ getIcbperMonto().toFixed(2) }} por bolsa)
                  </b-form-radio>
                </div>
              </b-col>

              <b-col lg="3" md="6">
                <label class="fw-semibold">Arroz Pilado (IVAP)</label>
                <div class="d-flex gap-3">
                  <b-form-radio name="ivap" @click="setIvap(1)" :checked="is_ivap == 1" value="1">
                    No
                  </b-form-radio>
                  <b-form-radio name="ivap" @click="setIvap(2)" :checked="is_ivap == 2" value="2">
                    Sí ({{ getIvapTasa() }}% IGV)
                  </b-form-radio>
                </div>
              </b-col>

              <!-- ISC -->
              <b-col lg="2" md="6">
                <label class="fw-semibold">ISC</label>
                <div class="d-flex gap-3">
                  <b-form-radio name="isc" @click="is_isc = false" :checked="!is_isc">No</b-form-radio>
                  <b-form-radio name="isc" @click="is_isc = true" :checked="is_isc">Sí</b-form-radio>
                </div>
              </b-col>

              <template v-if="is_isc">

                <!-- Régimen ISC -->
                <b-col lg="3" md="6">
                  <label class="fw-semibold">Régimen ISC (Cat. 08)</label>
                  <b-form-select v-model="tipo_isc">
                    <option value="01">01 — Al valor (%)</option>
                    <option value="02">02 — Específico (monto fijo x unidad)</option>
                    <option value="03">03 — Al valor según PVP (%)</option>
                  </b-form-select>
                </b-col>

                <!-- Porcentaje (regímenes 01 y 03) -->
                <b-col lg="2" md="6" v-if="tipo_isc === '01' || tipo_isc === '03'">
                  <label class="fw-semibold">% ISC</label>
                  <b-input-group>
                    <b-form-input type="number" step="0.01" min="0" max="100" v-model="percentage_isc"
                      placeholder="0.00" />
                    <b-input-group-text>%</b-input-group-text>
                  </b-input-group>
                </b-col>

                <!-- Monto fijo (régimen 02) -->
                <b-col lg="2" md="6" v-if="tipo_isc === '02'">
                  <label class="fw-semibold">Monto fijo</label>
                  <b-input-group>
                    <b-input-group-text>S/.</b-input-group-text>
                    <b-form-input type="number" step="0.0001" min="0" v-model="monto_isc_fijo" placeholder="0.0000" />
                  </b-input-group>
                  <small class="text-muted">Por litro si tiene Contenido neto, si no por unidad vendida</small>
                </b-col>

                <!-- Contenido neto en litros (régimen 02) — solo cuando el ISC
                     específico se aplica por volumen (cerveza, licores) y no
                     por unidad vendida directa (ej. cajetilla de cigarrillos) -->
                <b-col lg="2" md="6" v-if="tipo_isc === '02'">
                  <label class="fw-semibold">Contenido neto</label>
                  <b-input-group>
                    <b-form-input type="number" step="0.0001" min="0" v-model="contenido_neto_litros"
                      placeholder="0.0000" />
                    <b-input-group-text>L</b-input-group-text>
                  </b-input-group>
                  <small class="text-muted">Litros por unidad vendida. Vacío = monto fijo aplica directo x unidad</small>
                </b-col>

              </template>
              <b-col lg="4" md="6">
                <label class="fw-semibold">Aplica Percepción</label>
                <div class="d-flex gap-3">
                  <b-form-radio name="percepcion" @click="aplica_percepcion = false" :checked="!aplica_percepcion"
                    :value="false">
                    No
                  </b-form-radio>
                  <b-form-radio name="percepcion" @click="aplica_percepcion = true" :checked="aplica_percepcion"
                    :value="true">
                    Sí
                  </b-form-radio>
                </div>
              </b-col>
            </b-row>

            <!-- ══════════════════════════════════════════════════════
                 NUEVA SECCIÓN — Configuración Tributaria SUNAT
                 Naturaleza del producto (no las tasas, esas son globales)
            ══════════════════════════════════════════════════════ -->
            <hr class="my-4" />
            <h6 class="fw-bold text-secondary mb-3">
              <i class="fas fa-file-invoice me-2"></i>Configuración Tributaria SUNAT
            </h6>

            <b-row class="g-3">

              <!-- Bien o servicio -->
              <b-col lg="3" md="6">
                <label class="fw-semibold">
                  Tipo <span class="text-danger">*</span>
                </label>
                <div class="btn-group w-100" role="group">
                  <input type="radio" class="btn-check" id="tipo-bien" value="BIEN" v-model="tipo_bien_servicio"
                    autocomplete="off">
                  <label class="btn btn-outline-primary btn-sm" for="tipo-bien">
                    <i class="fas fa-box me-1"></i>BIEN
                  </label>
                  <input type="radio" class="btn-check" id="tipo-servicio" value="SERVICIO" v-model="tipo_bien_servicio"
                    autocomplete="off">
                  <label class="btn btn-outline-primary btn-sm" for="tipo-servicio">
                    <i class="fas fa-concierge-bell me-1"></i>SERVICIO
                  </label>
                </div>
                <small class="text-muted">
                  Define si en Amazonía se exonera (solo bienes).
                </small>
              </b-col>

              <!-- Naturaleza tributaria base -->
              <b-col lg="5" md="6">
                <label class="fw-semibold">
                  Afectación IGV por defecto <span class="text-danger">*</span>
                </label>
                <b-form-select v-model="tip_afe_igv_default" :disabled="is_ivap == 2">
                  <option value="10">Gravado — Operación Onerosa ({{ getIgvTasa() }}%)</option>
                  <option value="20">Exonerado — Operación Onerosa (0%)</option>
                  <option value="30">Inafecto — Operación Onerosa (0%)</option>
                  <option v-if="is_ivap == 2" value="17">IVAP — Arroz Pilado ({{ getIvapTasa() }}%)</option>
                </b-form-select>
                <small class="text-muted" v-if="is_ivap == 2">
                  Se fijó automáticamente a IVAP por la opción de Arroz Pilado.
                </small>
                <small class="text-muted" v-else>
                  Es un valor por defecto — en cada venta se puede ajustar
                  según el cliente o destino del bien.
                </small>
              </b-col>

              <!-- Código de detracción -->
              <b-col lg="4" md="6">
                <label class="fw-semibold">Código de Detracción (SPOT)</label>
                <b-form-select v-model="codigo_detraccion">
                  <option value="">— No aplica detracción —</option>
                  <option v-for="d in detracciones" :key="d.codigo" :value="d.codigo">
                    [{{ d.codigo }}] {{ d.nombre }} {{ (d.tasa_porcentaje) }}%
                  </option>
                </b-form-select>
                <small class="text-muted">
                  Asigna si este bien/servicio está sujeto al SPOT (R.S. 183-2004/SUNAT).
                </small>
              </b-col>

            </b-row>

            <!-- Alerta informativa según combinación -->
            <b-row class="mt-3" v-if="tip_afe_igv_default !== '10' && is_ivap != 2">
              <b-col>
                <div class="alert alert-warning py-2 mb-0 small">
                  <i class="fas fa-info-circle me-2"></i>
                  <span v-if="tip_afe_igv_default === '20'">
                    <strong>Exonerado:</strong> Este producto no genera IGV por defecto,
                    sin importar la zona del cliente. Aplica si está en el Apéndice I o II
                    del TUO del IGV, o si se entrega en zona Amazonía (Ley 27037).
                  </span>
                  <span v-if="tip_afe_igv_default === '30'">
                    <strong>Inafecto:</strong> Este producto está fuera del ámbito del IGV.
                    No se declara en el PDT 621. Diferente a exonerado.
                  </span>
                </div>
              </b-col>
            </b-row>

            <!-- Otros flags -->
            <b-row class="mt-3 g-3">
              <b-col lg="4" md="6">
                <label class="fw-semibold">Nota Electrónica</label>
                <div class="d-flex gap-3">
                  <b-form-radio name="nota" @click="is_especial_nota = 0" :checked="is_especial_nota == 0" value="0">
                    No
                  </b-form-radio>
                  <b-form-radio name="nota" @click="is_especial_nota = 1" :checked="is_especial_nota == 1" value="1">
                    Sí
                  </b-form-radio>
                </div>
              </b-col>
            </b-row>

            <!-- Información de precios base -->
            <b-row class="mt-4">
              <b-col>
                <div class="p-3 bg-light rounded border">
                  <h6 class="fw-bold">Resumen de Precios Base</h6>
                  <div>Precio General (incluye IGV? {{ include_igv == 0 ? 'No' : 'Sí' }})</div>
                  <div>Tasa aplicada: {{ getTasaActual() }}%</div>
                  <div>Base C.F: S/ {{ getPriceBaseCF() }}</div>
                  <div>Base C.E: S/ {{ getPriceBaseCE() }}</div>
                </div>
              </b-col>
            </b-row>
          </b-tab>

          <!-- Tab Información Adicional -->
          <b-tab title="Información Adicional">
            <b-row class="mt-3">
              <b-col>
                <p class="text-muted">Espacio para futuras personalizaciones (ej. atributos, proveedores, etc.)</p>
              </b-col>
            </b-row>
          </b-tab>

        </b-tabs>
      </b-card-body>
    </b-card>
  </DefaultLayout>
</template>


<script setup lang="ts">
import DefaultLayout from "@/layouts/DefaultLayout.vue";
import RegisterLayout from "@/layouts/components/RegisterLayout.vue";


import httpClient from '@/helpers/http-client';
import type { AxiosResponse } from 'axios';
import Swal from "sweetalert2/dist/sweetalert2.js";
type TVueSwalInstance = typeof Swal & typeof Swal.fire;

import { useRoute, useRouter } from "vue-router";
const router = useRouter(); //esto es para redireccionar
const route = useRoute("product.edit");//esto es para redireccionar en la url y poder obtener el id

import { onMounted, ref, watch } from "vue";
import { UNITS, type Product, type ProductCategorie, type ProductConfigResponse, type ProductResponse, type ProductUnit } from "@/types/products";


const title = ref<string>("");
const sku = ref<string>("");
const categorie_id = ref<string>("");
const categories = ref<ProductCategorie[]>([]);
const price_general = ref<number>(0);
const price_company = ref<number>(0);
const description = ref<string>("");
const disponiblidad = ref<number>(1);
const max_discount = ref<number>(1);
const is_icbper = ref<number>(1);
const is_discount = ref<number>(1);
const is_ivap = ref<number>(1);
const is_especial_nota = ref<number>(0);
const percentage_isc = ref<number>(0);
const include_igv = ref<number>(0);
const unidad_medida = ref<string>("NIU");
const units = ref<ProductUnit[]>(UNITS);
const stock = ref<number>(0);
const state = ref<number>(1);
const FILE_IMAGEN = ref<File | undefined>(undefined);
const IMAGEN_PREVIZUALIZA = ref<string | ArrayBuffer | null>(null);

const product_selected = ref<Product | undefined>(undefined);


const tipo_bien_servicio = ref<string>("BIEN");  // 'bien' | 'servicio'
const codigo_detraccion = ref<string>("");      // código del Anexo SUNAT o ''
const aplica_percepcion = ref(false);
const tip_afe_igv_default = ref<string>("10");   // '10' gravado, '20' exonerado, '30' inafecto
const is_isc = ref<boolean>(false);
const tipo_isc = ref<string | null>("01");
const monto_isc_fijo = ref<number>(0);
// litros x unidad — solo régimen '02'.
// PENDIENTE: nombre/alcance limitado a productos líquidos (cerveza, licores).
// Cigarrillos y combustibles también son ISC Específico pero por unidad/galón,
// no por litro — cuando se necesite, renombrar a un factor genérico
// (ej. factor_contenido_isc) y ajustar la etiqueta del form según el producto.
const contenido_neto_litros = ref<number | null>(null);

// Limpia los campos dependientes de ISC al desmarcar "Sí" — de lo contrario
// el v-if solo oculta el DOM pero el modelo conserva valores residuales
// que terminan enviándose al backend (ver bug BOLSA DE PLÁSTICO).
watch(is_isc, (activo) => {
  if (!activo) {
    tipo_isc.value = null;
    percentage_isc.value = 0;
    monto_isc_fijo.value = 0;
    contenido_neto_litros.value = null;
  } else if (tipo_isc.value === null) {
    tipo_isc.value = '01';
  }
});

// contenido_neto_litros solo aplica al régimen Específico ('02') — limpiar
// si el usuario cambia a un régimen porcentual para no dejar residuos.
watch(tipo_isc, (regimen) => {
  if (regimen !== '02') {
    contenido_neto_litros.value = null;
  }
});

// ── Parámetros tributarios (vienen de tax_configs, NO hardcode) ────
const tax_params = ref<Record<string, any[]>>({});
const detracciones = ref<any[]>([]);


// IGV General — primer registro del tipo IGV sin ubigeos (el estándar)
const getIgvTasa = (): number => {
  // Busca primero el marcado como default
  const igvDefault = tax_params.value['IGV']?.find((t: any) => t.es_default);
  // Fallback: el que no tiene ubigeos (comportamiento anterior)
  const igvFallback = tax_params.value['IGV']?.find((t: any) => !t.ubigeos_aplicables);
  return Number(igvDefault?.tasa_porcentaje ?? igvFallback?.tasa_porcentaje ?? 18);
};

// IGV Amazonía — el que tiene ubigeos_aplicables
const getIgvAmazoniaTasa = (): number => {
  const igvAmazonia = tax_params.value['IGV']?.find((t: any) => t.ubigeos_aplicables && !t.es_default);
  return Number(igvAmazonia?.tasa_porcentaje ?? 0);
};

// IVAP
const getIvapTasa = (): number => {
  return Number(tax_params.value['IVAP']?.[0]?.tasa_porcentaje ?? 4);
};

// ICBPER — tasa_porcentaje aquí es monto fijo en S/ (0.50), no un porcentaje
// Protegemos con Number() para garantizar que siempre sea number, nunca undefined
const getIcbperMonto = (): number => {
  return Number(tax_params.value['ICBPER']?.[0]?.tasa_porcentaje ?? 0.50);
};

// ISC — tasa base (0 porque varía por producto)
const getIscTasa = (): number => {
  return Number(tax_params.value['ISC']?.[0]?.tasa_porcentaje ?? 0);
};

// Tasa real que corresponde según la naturaleza tributaria seleccionada
// Tasa actual del producto según su naturaleza
const getTasaActual = (): number => {
  if (is_ivap.value == 2) return getIvapTasa();
  if (['20', '30'].includes(tip_afe_igv_default.value)) return 0;
  return getIgvTasa();
};


// Cuando se marca IVAP, fuerza tip_afe_igv_default a '17' automáticamente
const setIvap = (value: number) => {
  is_ivap.value = value;
  if (value == 2) {
    tip_afe_igv_default.value = '17';
  } else if (tip_afe_igv_default.value === '17') {
    tip_afe_igv_default.value = '10';
  }
};



// ── Precio base — cuando no incluye IGV: usa la tasa real, no 1.18 fijo ────────
// Corrección: tasa ya viene como 18 (porcentaje), dividir entre 100
const getPriceBaseCF = (): string => {
  const precio = Number(price_general.value) || 0;
  if (include_igv.value == 0) return precio.toFixed(6);
  const divisor = 1 + (getTasaActual() / 100);  // 1.18, 1.04, o 1.00
  return (precio / divisor).toFixed(6);
};

const getPriceBaseCE = (): string => {
  const precioCompany = Number(price_company.value) || 0;
  if (include_igv.value == 0) return precioCompany.toFixed(6);
  const divisor = 1 + (getTasaActual() / 100);
  return (precioCompany / divisor).toFixed(6);
};



const show = async () => {
  try {
    const res: AxiosResponse<ProductResponse> = await httpClient.get(`products/${route.params.id}`);
    if (res.data.code == 405) {
      (Swal as TVueSwalInstance).fire(
        "Upps",
        res.data.message,
        "error"
      );
      return;
    }

    product_selected.value = res.data.product;
    title.value = product_selected.value?.title ?? '';
    sku.value = product_selected.value?.sku ?? '';
    categorie_id.value = product_selected.value?.categorie_id + "";
    price_general.value = product_selected.value?.price_general ?? 0;
    price_company.value = product_selected.value?.price_company ?? 0;
    description.value = product_selected.value?.description ?? ''
    disponiblidad.value = product_selected.value?.disponiblidad ?? 1;
    max_discount.value = product_selected.value?.max_discount ?? 0;
    is_icbper.value = product_selected.value?.is_icbper ?? 1;
    is_discount.value = product_selected.value?.is_discount ?? 1;
    is_ivap.value = product_selected.value?.is_ivap ?? 1; //impuesto a la bolsa de plastico
    is_especial_nota.value = product_selected.value?.is_especial_nota ?? 1;
    percentage_isc.value = product_selected.value?.percentage_isc ?? 0;
    is_isc.value = product_selected.value?.is_isc ?? false;
    include_igv.value = product_selected.value?.include_igv ?? 1;
    unidad_medida.value = product_selected.value?.unidad_medida ?? '';
    stock.value = product_selected.value?.stock ?? 0;
    state.value = product_selected.value?.state ?? 1;
    IMAGEN_PREVIZUALIZA.value = product_selected.value?.imagen ?? '';

    tipo_bien_servicio.value = product_selected.value?.tipo_bien_servicio ?? 'BIEN';
    codigo_detraccion.value = product_selected.value?.codigo_detraccion ?? '';
    aplica_percepcion.value = product_selected.value?.aplica_percepcion ?? true;
    tip_afe_igv_default.value = product_selected.value?.tip_afe_igv_default ?? '20';
    tipo_isc.value = product_selected.value?.tipo_isc ?? '01';
    monto_isc_fijo.value = product_selected.value?.monto_isc_fijo ?? 0;
    contenido_neto_litros.value = product_selected.value?.contenido_neto_litros ?? null;
    console.log(res);
  } catch (error) {
    console.log(error);
  }
}



const loadFile = ($event: any) => {
  if ($event.target.files[0].type.indexOf("image") < 0) {// validar que sea una imagen
    IMAGEN_PREVIZUALIZA.value = null;
    (Swal as TVueSwalInstance).fire(// Usar Swal para mostrar alerta
      'Upss!',
      'El archivo seleccionado no es una imagen.',
      'error');
    return;
  }
  FILE_IMAGEN.value = $event.target.files[0];// obtener el archivo y lo asigna a la variable reactiva FILE_IMAGEN
  let reader = new FileReader();// crear un objeto FileReader para leer el archivo como una URL de datos
  if (FILE_IMAGEN.value) {
    reader.readAsDataURL(FILE_IMAGEN.value); // leer el archivo como una URL de datos
    reader.onloadend = () => IMAGEN_PREVIZUALIZA.value = reader.result;// asignar la URL de datos a la variable reactiva IMAGEN_PREVIZUALIZA para previsualizar la imagen
  }
}

const config = async () => {
  try {
    const res: AxiosResponse<ProductConfigResponse> = await httpClient.get('products/config');
    categories.value = res.data.categories;
    // Catálogos tributarios — vienen en la misma llamada, sin segunda request
    tax_params.value = (res.data as any).taxes ?? {};
    detracciones.value = (res.data as any).detracciones ?? [];
  } catch (error) {
    console.log(error);
  }
}

const limpiarForm = () => {
  title.value = '';
  sku.value = '';
  categorie_id.value = '';
  price_general.value = 0;
  price_company.value = 0;
  description.value = '';
  disponiblidad.value = 1;
  max_discount.value = 0;
  is_icbper.value = 1;
  is_discount.value = 1;
  is_ivap.value = 1;
  is_isc.value = false;
  is_especial_nota.value = 1;
  percentage_isc.value = 0;
  include_igv.value = 0;
  unidad_medida.value = '';
  stock.value = 0;
  FILE_IMAGEN.value = undefined;

  tip_afe_igv_default.value = '10';
  codigo_detraccion.value = '';
  percentage_isc.value = 0;
  monto_isc_fijo.value = 0;
  contenido_neto_litros.value = null;
};
const store = async () => {
  try {

    if (!title.value) {
      const swal = Swal as TVueSwalInstance;
      await swal.fire('Error', 'El nombre del producto es obligatorio.', 'error');
      return;
    }

    if (!sku.value) {
      const swal = Swal as TVueSwalInstance;
      await swal.fire('Error', 'El sku del producto es obligatorio.', 'error');
      return;
    }
    if (!categorie_id.value) {
      const swal = Swal as TVueSwalInstance;
      await swal.fire('Error', 'La categoría del producto es obligatorio.', 'error');
      return;
    }
    if (!unidad_medida.value) {
      const swal = Swal as TVueSwalInstance;
      await swal.fire('Error', 'La unidad de medida del producto es obligatorio.', 'error');
      return;
    }
    if (price_general.value < 0) {
      const swal = Swal as TVueSwalInstance;
      await swal.fire('Error', 'El precio general del producto es obligatorio.', 'error');
      return;
    }
    if (!description.value) {
      const swal = Swal as TVueSwalInstance;
      await swal.fire('Error', 'La descripcion del producto es obligatorio.', 'error');
      return;
    }

    if (is_discount.value == 2 && max_discount.value < 0) {
      const swal = Swal as TVueSwalInstance;
      await swal.fire('Error', 'El descuento del producto es obligatorio.', 'error');
      return;
    }
    if (is_isc.value && (tipo_isc.value === '01' || tipo_isc.value === '03') && percentage_isc.value <= 0) {
      monto_isc_fijo.value = 0;
      const swal = Swal as TVueSwalInstance;
      await swal.fire('Error', 'El porcentaje de ISC del producto es obligatorio.', 'error');
      return;
    }
    if (is_isc.value && tipo_isc.value === '02' && monto_isc_fijo.value <= 0) {
      percentage_isc.value = 0;
      const swal = Swal as TVueSwalInstance;
      await Swal.fire('Error', 'El monto fijo de ISC es obligatorio para el régimen específico.', 'error');
      return;
    }

    if (stock.value < 0) {
      const swal = Swal as TVueSwalInstance;
      await swal.fire('Error', 'El stock del producto es obligatorio.', 'error');
      return;
    }
    let formData = new FormData();
    formData.append('title', title.value);
    formData.append('sku', sku.value);
    formData.append('categorie_id', categorie_id.value);
    formData.append('price_general', price_general.value.toString());
    formData.append('price_company', price_company.value.toString());
    formData.append('description', description.value);
    formData.append('disponiblidad', disponiblidad.value.toString());
    formData.append('max_discount', max_discount.value.toString());
    formData.append('is_icbper', is_icbper.value.toString());
    formData.append('is_discount', is_discount.value.toString());
    formData.append('is_ivap', is_ivap.value.toString());
    formData.append('is_isc', is_isc.value ? '1' : '0');
    formData.append('is_especial_nota', is_especial_nota.value.toString());
    formData.append('percentage_isc', percentage_isc.value.toString());
    formData.append('include_igv', include_igv.value.toString());
    formData.append('unidad_medida', unidad_medida.value);
    formData.append('stock', stock.value.toString());
    formData.append('state', state.value.toString());
    if (FILE_IMAGEN.value) {
      formData.append('image', FILE_IMAGEN.value);
    }

    //para impuesto
    formData.append('tipo_bien_servicio', tipo_bien_servicio.value);
    formData.append('codigo_detraccion', codigo_detraccion.value);
    formData.append('aplica_percepcion', aplica_percepcion.value ? '1' : '0');
    formData.append('tip_afe_igv_default', tip_afe_igv_default.value);
    formData.append('monto_isc_fijo', monto_isc_fijo.value.toString());
    formData.append('tipo_isc', tipo_isc.value ?? '');
    formData.append('contenido_neto_litros', (contenido_neto_litros.value ?? '').toString());

    const resp: AxiosResponse<ProductResponse> = await httpClient.post("products/" + route.params.id, formData);

    if (resp.data.code == 405) {
      (Swal as TVueSwalInstance).fire(
        "Upps",
        resp.data.message,
        "error"
      );
    } else {
      (Swal as TVueSwalInstance).fire(
        "Genial!",
        resp.data.message,
        "success"
      );
    }
    console.log(resp);

  } catch (error: any) {
    if (error.response?.data) {
      (Swal as TVueSwalInstance).fire(
        "Upps",
        error.response?.data?.message,
        "error"
      );
      return;
    }

  }


}



function goToList() {//funcion para redirigir al listado
  router.push({ name: "product.index" });
}

onMounted(() => {
  config();
  show();
});

</script>
