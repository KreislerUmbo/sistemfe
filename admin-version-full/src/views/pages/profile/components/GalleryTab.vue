<template>
  <b-row id="grid" class="g-0">
    <b-col
      md="6"
      lg="4"
      class="picture-item"
      v-for="(img, idx) in galleryImg"
      :key="idx"
    >
      <a :href="img" class="lightbox">
        <img :src="img" alt="" class="img-fluid" />
      </a>
    </b-col>
  </b-row>
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

const galleryImg = [img1, img2, img3, img4, img5, img6];

onMounted(() => {
  new Tobii();
});
</script>
