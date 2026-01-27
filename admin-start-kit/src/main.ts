import { createApp } from "vue";
import { createPinia } from "pinia";

import App from "./App.vue";
import router from "./router";

import { createBootstrap } from "bootstrap-vue-next";
import VueApexCharts from "vue3-apexcharts";

import jQuery from "jquery";
// @ts-ignore
window.$ = window.jQuery = jQuery;

import "simplebar";
import "cropperjs/dist/cropper.css";
import "huebee/dist/huebee.min.css";
import "sweetalert2/dist/sweetalert2.css";
import "magic.css/dist/magic.css";
import "tobii/dist/css/tobii.min.css";
import "starability/starability-css/starability-all.css";
import "leaflet/dist/leaflet.css";
import "simple-datatables/dist/style.css";
import "nouislider/dist/nouislider.css";
import "@vueup/vue-quill/dist/vue-quill.snow.css";
import "uppy/dist/uppy.min.css";
import "flatpickr/dist/flatpickr.css";
import "mobius1-selectr/dist/selectr.min.css";
import "listree/dist/listree.min.css";
import "bootstrap-vue-next/dist/bootstrap-vue-next.css";
import "@/assets/scss/app.scss";
import "@/assets/scss/icons.scss";

const app = createApp(App);

app.use(createPinia());
app.use(router);
app.use(createBootstrap({ components: true, directives: true }));
app.use(VueApexCharts);

app.mount("#app");
