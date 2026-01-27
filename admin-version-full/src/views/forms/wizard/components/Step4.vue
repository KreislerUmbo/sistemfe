<template>
  <b-card-title class="my-4 fs-15">Confirm Detail</b-card-title>
  <div class="form-check my-4 text-center">
    <img :src="task" class="mb-3" height="60" alt="" />
    <h4 class="mb-1 fs-16">You are all set!</h4>
    <p class="text-muted">Now you can access your account anytime anywhere</p>
    <div>
      <b-form-checkbox
        class="float-none"
        id="flexCheckDefault"
        name="flexCheckDefault"
      >
        {{ " " }}
        I agree with the Terms and Conditions.
      </b-form-checkbox>
    </div>
  </div>
  <div>
    <b-button
      type="button"
      id="step4Prev"
      variant="secondary"
      class="float-start mt-2"
      @click="previousPage"
      >Previous</b-button
    >
    <b-button
      type="button"
      id="step4Finish"
      variant="danger"
      class="float-end mt-2"
      >Finish</b-button
    >
  </div>
</template>
<script setup lang="ts">
import task from "@/assets/images/extra/task.png";
defineProps(["previousPage"]);
</script>
