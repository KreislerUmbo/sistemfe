<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <b-col>
                <b-card-title>Leaflet Quick Start Guide</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <div id="Leaf_Default" class="" style="height: 400px"></div>
          </b-card-body>
        </b-card>
      </b-col>
      <b-col md="6" lg="6">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <b-col>
                <b-card-title>Bounds Extend</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <div id="Bounds_Extend" class="" style="height: 400px"></div>
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
                <b-card-title>Vector Bounds</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <div id="Vector_bounds" class="" style="height: 400px"></div>
          </b-card-body>
        </b-card>
      </b-col>
      <b-col md="6" lg="6">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <b-col>
                <b-card-title>Moov Canvas</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <div id="Moov_Canvas" class="" style="height: 400px"></div>
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
                <b-card-title>Array Map</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <div id="Array_Map" class="" style="height: 400px"></div>
          </b-card-body>
        </b-card>
      </b-col>
      <b-col md="6" lg="6">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <b-col>
                <b-card-title>Simple Vector Map</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <div id="V_Simple" class="" style="height: 400px"></div>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import { onMounted } from "vue";
import DefaultLayout from "@/layouts/DefaultLayout.vue";
import L, { type CircleMarkerOptions } from "leaflet";

onMounted(() => {
  //  Map-1
  const map1 = L.map("Leaf_Default").setView([51.505, -0.09], 13);

  L.tileLayer(
    "https://api.mapbox.com/styles/v1/{id}/tiles/{z}/{x}/{y}?access_token=pk.eyJ1IjoibWFwYm94IiwiYSI6ImNpejY4NXVycTA2emYycXBndHRqcmZ3N3gifQ.rJcFIG214AriISLbB6B5aw",
    {
      maxZoom: 18,
      attribution:
        'Map data &copy; <a href="https://www.openstreetmap.org/">OpenStreetMap</a> contributors, ' +
        '<a href="https://creativecommons.org/licenses/by-sa/2.0/">CC-BY-SA</a>, ' +
        'Imagery © <a href="https://www.mapbox.com/">Mapbox</a>',
      id: "mapbox/streets-v11",
    },
  ).addTo(map1);

  // L.marker([51.5, -0.09]).addTo(map1).bindPopup("<b>Hello world!</b><br />I am a popup.").openPopup();

  L.circle([51.508, -0.11], 500, {
    color: "red",
    fillColor: "#f03",
    fillOpacity: 0.5,
  } as CircleMarkerOptions)
    .addTo(map1)
    .bindPopup("I am a circle.");

  L.polygon([
    [51.509, -0.08],
    [51.503, -0.06],
    [51.51, -0.047],
  ])
    .addTo(map1)
    .bindPopup("I am a polygon.");

  const popup = L.popup();

  function onMapClick(e: any) {
    popup
      .setLatLng(e.latlng)
      .setContent("You clicked the map at " + e.latlng.toString())
      .openOn(map1);
  }

  map1.on("click", onMapClick);

  //  Map-2
  const osmUrl = "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png";
  const osm = new L.TileLayer(osmUrl, { maxZoom: 18 });

  const latLng = new L.LatLng(54.18815548107151, -7.657470703124999);

  const bounds1 = new L.LatLngBounds(
    new L.LatLng(54.559322, -5.767822),
    new L.LatLng(56.1210604, -3.02124),
  );
  const bounds2 = new L.LatLngBounds(
    new L.LatLng(56.56023925701561, -2.076416015625),
    new L.LatLng(57.01158038001565, -0.9777832031250001),
  );
  let bounds3: any;

  const map2 = new L.Map("Bounds_Extend", {
    layers: [osm],
    center: bounds1.getCenter(),
    zoom: 7,
  });

  const rectangle1 = new L.Rectangle(bounds1);
  const rectangle2 = new L.Rectangle(bounds2);
  let rectangle3: any;

  const marker = new L.Marker(latLng);

  map2.addLayer(rectangle1).addLayer(rectangle2);

  function boundsExtendBounds() {
    if (rectangle3) {
      map2.removeLayer(rectangle3);
      rectangle3 = null;
    }
    if (bounds3) {
      bounds3 = null;
    }
    bounds3 = new L.LatLngBounds(
      bounds1.getSouthWest(),
      bounds1.getNorthEast(),
    );
    bounds3.extend(bounds2);
    rectangle3 = new L.Rectangle(bounds3, {
      color: "#ff0000",
      weight: 1,
      opacity: 1,
      fillOpacity: 0,
    });

    map2.addLayer(rectangle3);
  }

  function boundsExtendLatLng() {
    if (rectangle3) {
      map2.removeLayer(rectangle3);
      rectangle3 = null;
    }
    if (bounds3) {
      bounds3 = null;
    }
    bounds3 = new L.LatLngBounds(
      bounds1.getSouthWest(),
      bounds1.getNorthEast(),
    );
    bounds3.extend(marker.getLatLng());
    rectangle3 = new L.Rectangle(bounds3, {
      color: "#ff0000",
      weight: 1,
      opacity: 1,
      fillOpacity: 0,
    });

    map2.addLayer(rectangle3);
  }

  // Map-3
  const osmAttrib =
    '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';
  const osm3 = L.tileLayer(osmUrl, { maxZoom: 18, attribution: osmAttrib });

  const poly_points = [
    [39.70348880963439, -104.98603820800781],
    [39.69926245589766, -104.95582580566406],
    [39.67918374111695, -104.94483947753906],
    [39.663856582926165, -104.95307922363281],
    [39.66279941218785, -104.98672485351562],
    [39.70348880963439, -104.98603820800781],
  ];

  const path_points = [
    [39.72567292003209, -104.98672485351562],
    [39.717222671644635, -104.96612548828124],
    [39.71405356154611, -104.95513916015625],
    [39.70982785491674, -104.94758605957031],
    [39.70454535762547, -104.93247985839844],
    [39.696092520737224, -104.91874694824217],
    [39.687638648548635, -104.90432739257812],
    [39.67759833072648, -104.89471435546875],
  ];

  let latlngs = [];
  for (let i = 0, len = path_points.length; i < len; i++) {
    latlngs.push(new L.LatLng(path_points[i][0], path_points[i][1]));
  }
  const path = new L.Polyline(latlngs);

  let latlngs2 = [];
  for (let i = 0, len = poly_points.length; i < len; i++) {
    latlngs2.push(new L.LatLng(poly_points[i][0], poly_points[i][1]));
  }
  const poly = new L.Polygon(latlngs2);

  const map3 = new L.Map("Vector_bounds", {
    layers: [osm3],
    center: new L.LatLng(39.69596043694606, -104.95084762573242),
    zoom: 12,
  });

  map3.addLayer(path);
  map3.addLayer(poly);

  path.bindPopup("Hello world");

  // Map-4
  const osmUrl4 =
    "https://api.mapbox.com/styles/v1/mapbox/light-v9/tiles/{z}/{x}/{y}?access_token=pk.eyJ1IjoibWFwYm94IiwiYSI6ImNpejY4NXVycTA2emYycXBndHRqcmZ3N3gifQ.rJcFIG214AriISLbB6B5aw";
  const osmAttrib4 =
    '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';
  const osm4 = L.tileLayer(osmUrl4, { maxZoom: 18, attribution: osmAttrib4 });

  var map4 = L.map("Moov_Canvas", { preferCanvas: true })
    .setView([50.5, 30.51], 15)
    .addLayer(osm4);

  const markers4: any[] = [];
  const colors = ["red", "green", "blue", "purple", "cyan", "yellow"];
  for (let i = 0; i < 20; i++) {
    markers4.push(
      L.circleMarker([50.5, 30.51], {
        color: colors[i % colors.length],
      } as CircleMarkerOptions).addTo(map4),
    );
  }

  function update() {
    const t = new Date().getTime() / 1000;
    markers4.forEach(function (marker, i) {
      const v = t * (1 + i / 10) + ((12.5 * i) / 180) * Math.PI;
      marker.setLatLng([
        50.5 + (i % 2 ? 1 : -1) * Math.sin(v) * 0.005,
        30.51 + (i % 3 ? 1 : -1) * Math.cos(v) * 0.005,
      ]);
    });

    L.Util.requestAnimFrame(update);
  }

  update();

  // Map-5
  const map5 = L.map("Array_Map", {
    center: [20, 20],
    zoom: 3,
    preferCanvas: true,
  });
  L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    minZoom: 1,
    maxZoom: 17,
    // label: 'open street map'
  }).addTo(map5);
  const points: any = [
    [0, 0],
    [0, 42],
    [42, 42],
    [0, 0],
  ];
  L.polygon([points, []], { dashArray: "5, 5" }).addTo(map5);
  L.circleMarker([42, 0], { color: "red" } as CircleMarkerOptions).addTo(map5);

  // Map-6
  const map6 = L.map("V_Simple");

  map6.setView([51.505, -0.09], 13);

  // const marker6 = L.marker([51.5, -0.09]).bindPopup("<b>Hello world!</b><br />I am a popup.").addTo(map6);

  const circle = L.circle([51.508, -0.11], 500, {
    color: "#f03",
    opacity: 0.7,
  } as CircleMarkerOptions)
    .bindPopup("I am a circle.")
    .addTo(map6);

  const polygon = L.polygon([
    [51.509, -0.08],
    [51.503, -0.06],
    [51.51, -0.047],
  ])
    .bindPopup("I am a polygon.")
    .addTo(map6);

  const osmUrl6 = "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png";
  const osmAttrib6 =
    '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';
  const osm6 = L.tileLayer(osmUrl6, {
    maxZoom: 18,
    attribution: osmAttrib6,
  }).addTo(map6);
});
</script>
