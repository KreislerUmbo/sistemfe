<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Default Example">
          <b-pagination
            v-model="currentPage"
            :total-rows="15"
            :per-page="perPageItem"
            prev-text="Previous"
            next-text="Next"
          />
          <b-pagination
            v-model="currentPage"
            :total-rows="15"
            :per-page="perPageItem"
            first-number
            last-number
          />
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Disabled And Active States">
          <b-pagination
            v-model="currentPage"
            :total-rows="15"
            :per-page="perPageItem"
            prev-text="Previous"
            next-text="Next"
          />
          <b-pagination
            v-model="currentPage"
            :total-rows="15"
            :per-page="perPageItem"
            prev-text="Previous"
            next-text="Next"
          />
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="12">
        <UIComponentCard title="Sizing Example">
          <b-pagination
            v-model="currentPage"
            :total-rows="15"
            :per-page="perPageItem"
            first-number
            last-number
            size="lg"
          />
          <b-pagination
            v-model="currentPage"
            :total-rows="15"
            :per-page="perPageItem"
            first-number
            last-number
            size="sm"
          />
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="12">
        <UIComponentCard title="Alignment Example">
          <b-pagination
            v-model="currentPage"
            :total-rows="15"
            :per-page="perPageItem"
            prev-text="Previous"
            next-text="Next"
          />
          <b-pagination
            v-model="currentPage"
            :total-rows="15"
            :per-page="perPageItem"
            prev-text="Previous"
            next-text="Next"
            class="justify-content-center"
          />
          <b-pagination
            v-model="currentPage"
            :total-rows="15"
            :per-page="perPageItem"
            prev-text="Previous"
            next-text="Next"
            class="justify-content-end"
          />
        </UIComponentCard>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import { ref } from "vue";
const perPageItem = ref(5);
const currentPage = ref(1);

import DefaultLayout from "@/layouts/DefaultLayout.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
