<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Ratio Video 16:9">
          <div class="ratio ratio-16x9">
            <iframe
              src="https://www.youtube.com/embed/-GfNEDs3ERw"
              title="YouTube video"
              allowfullscreen
            ></iframe>
          </div>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Ratio Video 21:9">
          <div class="ratio ratio-21x9">
            <iframe
              src="https://www.youtube.com/embed/-GfNEDs3ERw"
              title="YouTube video"
              allowfullscreen
            ></iframe>
          </div>
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Ratio Video 4:3">
          <div class="ratio ratio-4x3">
            <iframe
              src="https://www.youtube.com/embed/-GfNEDs3ERw"
              title="YouTube video"
              allowfullscreen
            ></iframe>
          </div>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Ratio Video 1:1">
          <div class="ratio ratio-1x1">
            <iframe
              src="https://www.youtube.com/embed/-GfNEDs3ERw"
              title="YouTube video"
              allowfullscreen
            ></iframe>
          </div>
        </UIComponentCard>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import DefaultLayout from "@/layouts/DefaultLayout.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
