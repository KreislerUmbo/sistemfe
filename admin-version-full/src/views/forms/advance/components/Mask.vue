<template>
  <b-col md="6" lg="6">
    <UIComponentCard title="Mask">
      <div class="mb-2">
        <label class="mb-2"
          >RegExp (Russian postal code)
          <span class="desc ms-2 font-11 text-muted">/^[1-6]\d{0,5}$/</span>
        </label>
        <InputMask id="regexp-mask" mask="9-99-99-99" />
      </div>

      <div class="mb-2">
        <label class="mb-2"
          >Type here against mask
          <span class="desc ms-2 font-11 text-muted">+{7}(000)000-00-00</span>
        </label>
        <InputMask id="start-phone-mask" mask="(999) 999-99-99" />
      </div>
      <div class="mb-2">
        <label class="mb-2">Mask in overwrite and autofix modes</label>
        <InputMask id="date-overwrite-mask" mask="99.99.9999" />
      </div>
      <div class="mb-2">
        <label class="mb-2">IP Address</label>
        <InputMask id="ip" mask="999.999.999.999" />
      </div>
      <div>
        <label class="mb-2">Date and time</label>
        <InputMask id="moment-mask" mask="99/99/9999 99:99" />
      </div>
    </UIComponentCard>
  </b-col>
</template>
<script setup lang="ts">
import InputMask from "@/components/InputMask.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
