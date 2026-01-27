<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col cols="12">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <b-col>
                <b-card-title>World Map Markers</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <JsVectorMap id="map_1" :height="350" :options="worldMapOptions" />
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
                <b-card-title>World Map With Marker Lines</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <JsVectorMap
              id="map_2"
              :height="350"
              :options="worldMapLinesOptions"
            />
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import DefaultLayout from "@/layouts/DefaultLayout.vue";
import JsVectorMap from "@/components/JsVectorMap.vue";

const worldMapOptions = {
  map: "world",
  selector: "#map_1",
  zoomOnScroll: false,
  zoomButtons: false,
  // markersSelectable: true,
  markers: [
    { name: "Greenland", coords: [72, -42] },
    { name: "Canada", coords: [56.1304, -106.3468] },
    { name: "Russia", coords: [61, 105] },
    { name: "Palestine", coords: [31.9474, 35.2272] },
  ],
  markerStyle: {
    initial: {
      r: 5,
      fill: "#22c55e",
      fillOpacity: 1,
      stroke: "#FFF",
      strokeWidth: 1,
      strokeOpacity: 0.65,
    },
    selected: { fill: "blue" },
    selectedHover: { fill: "red" },
  },
  labels: {
    markers: {
      render: (marker: any) => marker.name,
    },
  },
  regionStyle: {
    initial: {
      fill: "rgba(169,183,197, 0.3)",
      fillOpacity: 1,
    },
  },
};

const worldMapLinesOptions = {
  map: "world",
  selector: "#map_2",
  mapBgColor: "#F7F8F9",
  zoomOnScroll: false,
  zoomButtons: false,
  markers: [
    { name: "Greenland", coords: [72, -42] },
    { name: "Canada", coords: [56.1304, -106.3468] },
    { name: "Brazil", coords: [-14.235, -51.9253] },
    { name: "Egypt", coords: [26.8206, 30.8025] },
    { name: "Russia", coords: [61, 105] },
    { name: "China", coords: [35.8617, 104.1954] },
    { name: "United States", coords: [37.0902, -95.7129] },
    { name: "Norway", coords: [60.472024, 8.468946] },
    { name: "Ukraine", coords: [48.379433, 31.16558] },
  ],
  lines: [
    { from: "Canada", to: "Egypt" },
    { from: "Russia", to: "Egypt" },
    { from: "Greenland", to: "Egypt" },
    { from: "Brazil", to: "Egypt" },
    { from: "United States", to: "Egypt" },
    { from: "China", to: "Egypt" },
    { from: "Norway", to: "Egypt" },
    { from: "Ukraine", to: "Egypt" },
  ],
  labels: {
    markers: {
      render: (marker: any) => marker.name,
    },
  },
  lineStyle: {
    animation: true,
    strokeDasharray: "6 3 6",
  },
  regionStyle: {
    initial: {
      fill: "rgba(169,183,197, 0.3)",
      fillOpacity: 1,
    },
  },
  markerStyle: {
    initial: {
      r: 5, // Marker width
      fill: "#22c55e", // Marker color
      fillOpacity: 1, // The opacity of the marker shape
      stroke: "#FFF", // Stroke
      strokeWidth: 1, // the stroke width
      strokeOpacity: 0.65, // The stroke opacity
    },
    // All options in initial object can be overitten in hover, selected, selectedHover object as well.
    hover: {
      stroke: "black",
      cursor: "pointer",
      strokeWidth: 2,
    },
    selected: {
      fill: "blue",
    },
    selectedHover: {
      fill: "red",
    },
  },
};
</script>
