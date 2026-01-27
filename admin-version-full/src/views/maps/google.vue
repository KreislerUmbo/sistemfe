<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <b-col>
                <b-card-title>Default Map</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <GoogleMap
              :api-key="api"
              :center="center"
              :zoom="5"
              class="gmaps"
            ></GoogleMap>
          </b-card-body>
        </b-card>
      </b-col>
      <b-col md="6" lg="6">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <b-col>
                <b-card-title>Map With Marker</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <GoogleMap :api-key="api" :center="center" :zoom="3" class="gmaps">
              <Marker
                v-for="(m, index) in markers"
                :key="index"
                :options="m.markerOptions"
                :clickable="true"
                :draggable="true"
              ></Marker>
            </GoogleMap>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <b-col>
                <b-card-title>Overlays Map</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <GoogleMap :api-key="api" :center="center" :zoom="10" class="gmaps">
              <InfoWindow
                :options="{ position: center, content: 'Hello viewer!' }"
              />
            </GoogleMap>
          </b-card-body>
        </b-card>
      </b-col>
      <b-col md="6" lg="6">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <b-col>
                <b-card-title>Polygon Types</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <GoogleMap
              :api-key="api"
              :center="{ lat: 24.886, lng: -70.268 }"
              :zoom="4"
              class="gmaps"
            >
              <Polygon :options="polygonOption" :editable="true"></Polygon>
            </GoogleMap>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col cols="12">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <b-col>
                <b-card-title>Layer Map</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <GoogleMap
              :api-key="api"
              :libraries="['visualization']"
              style="height: 300px"
              :center="{ lat: 37.774546, lng: -122.433523 }"
              :zoom="13"
              class="gmaps"
            >
              <HeatmapLayer :options="heatmapData" />
            </GoogleMap>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import { ref } from "vue";
import "https://maps.googleapis.com/maps/api/js";
import {
  GoogleMap,
  Marker,
  Polygon,
  HeatmapLayer,
  InfoWindow,
} from "vue3-google-map";

const api = ref("");
const center = ref({ lat: -12.043333, lng: -77.028333 });
const markers = ref([
  {
    markerOptions: {
      position: { lat: -12.04, lng: -77.0 },
      label: "Hi",
      title: "Hello from marker",
    },
  },
]);

const polygonOption = {
  paths: [
    { lat: 25.774, lng: -80.19 },
    { lat: 18.466, lng: -66.118 },
    { lat: 32.321, lng: -64.757 },
    { lat: 25.774, lng: -80.19 },
  ],
  strokeColor: "#FF0000",
  strokeOpacity: 0.8,
  strokeWeight: 2,
  fillColor: "#FF0000",
  fillOpacity: 0.35,
};

const heatmapData = {
  data: [
    { location: { lat: 37.782, lng: -122.447 }, weight: 0.5 },
    { lat: 37.782, lng: -122.445 },
    { location: { lat: 37.782, lng: -122.443 }, weight: 2 },
    { location: { lat: 37.782, lng: -122.441 }, weight: 3 },
    { location: { lat: 37.782, lng: -122.439 }, weight: 2 },
    { lat: 37.782, lng: -122.437 },
    { location: { lat: 37.782, lng: -122.435 }, weight: 0.5 },

    { location: { lat: 37.785, lng: -122.447 }, weight: 3 },
    { location: { lat: 37.785, lng: -122.445 }, weight: 2 },
    { lat: 37.785, lng: -122.443 },
    { location: { lat: 37.785, lng: -122.441 }, weight: 0.5 },
    { lat: 37.785, lng: -122.439 },
    { location: { lat: 37.785, lng: -122.437 }, weight: 2 },
    { location: { lat: 37.785, lng: -122.435 }, weight: 3 },
  ],
};

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
