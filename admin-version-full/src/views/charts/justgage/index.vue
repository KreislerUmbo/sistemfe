<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Defaults">
          <p class="text-muted mb-3"></p>
          <b-row>
            <b-col md="6">
              <JustGage id="gg1" :options="justgageChart1" />
            </b-col>
            <b-col md="6">
              <JustGage id="gg2" :options="justgageChart2" />
            </b-col>
          </b-row>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <div class="col">
                <b-card-title>Counter</b-card-title>
              </div>
              <div class="col-auto">
                <JustGage id="Counter_2_refresh" :options="counterRefresh" />
              </div>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <div id="Counter_2" class="gauge mt-2"></div>
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
                <b-card-title>Animation Events</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <JustGage
              id="Animation_Events"
              className="mt-3"
              :options="animationEvents"
            />
            <p class="text-muted">
              After every animation end, function will log it below.
            </p>
            <p id="log"></p>
          </b-card-body>
        </b-card>
      </b-col>
      <b-col md="6" lg="6">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <b-col>
                <b-card-title>Text Avatars Square</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <JustGage id="Counter" className="mt-3" :options="counter" />
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
                <b-card-title>Icon Avatars Circle</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <JustGage
              id="Custom_wether"
              className="mt-3"
              :options="customWether"
            />
          </b-card-body>
        </b-card>
      </b-col>
      <b-col md="6" lg="6">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <b-col>
                <b-card-title>Icon Avatars Square</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <JustGage id="font_option" className="mt-3" :options="fontOption" />
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
                <b-card-title>Justgage Half Size</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <b-row class="mt-3">
              <b-col md="4">
                <div class="size-3">
                  <JustGage id="jg1" :options="jg1Option" />
                </div>
              </b-col>
              <b-col md="4">
                <div class="size-2">
                  <JustGage id="jg2" :options="jg2Option" />
                </div>
              </b-col>
              <b-col md="4">
                <div class="size-1">
                  <JustGage id="jg3" :options="jg3Option" />
                </div>
              </b-col>
            </b-row>
          </b-card-body>
        </b-card>
      </b-col>
      <b-col md="6" lg="6">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <b-col>
                <b-card-title>Justgage Full Size</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <b-row class="mt-3">
              <b-col md="4">
                <div class="size-1">
                  <JustGage id="jg4" :options="jg4Option" />
                </div>
              </b-col>
              <b-col md="4">
                <div class="size-2">
                  <JustGage id="jg5" :options="jg5Option" />
                </div>
              </b-col>
              <b-col md="4">
                <div class="size-3">
                  <JustGage id="jg6" :options="jg6Option" />
                </div>
              </b-col>
            </b-row>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import JustGage from "@/components/JustGage.vue";
import {
  justgageChart1,
  justgageChart2,
  counterRefresh,
  animationEvents,
  counter,
  customWether,
  fontOption,
  jg1Option,
  jg2Option,
  jg3Option,
  jg4Option,
  jg5Option,
  jg6Option,
} from "@/views/charts/justgage/components/data";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
