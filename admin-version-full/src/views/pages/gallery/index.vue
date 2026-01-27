<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col cols="12">
        <UIComponentCard title="Gallery">
          <b-row id="grid" class="g-0">
            <b-col
              md="4"
              lg="3"
              class="picture-item"
              v-for="(img, idx) in cardImg"
              :key="idx"
            >
              <a :href="img" class="lightbox">
                <img :src="img" alt="" class="img-fluid" />
              </a>
            </b-col>
          </b-row>
        </UIComponentCard>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import { onMounted } from "vue";
import Tobii from "tobii";

import img1 from "@/assets/images/extra/card/img-1.jpg";
import img2 from "@/assets/images/extra/card/img-2.jpg";
import img3 from "@/assets/images/extra/card/img-3.jpg";
import img4 from "@/assets/images/extra/card/img-4.jpg";
import img5 from "@/assets/images/extra/card/img-5.jpg";
import img6 from "@/assets/images/extra/card/img-6.jpg";

onMounted(() => {
  new Tobii();
});

const cardImg = [img1, img2, img3, img4, img5, img6, img2, img1];

import DefaultLayout from "@/layouts/DefaultLayout.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
