<template>
  <b-col md="6" lg="6">
    <UIComponentCard title="Have More Questions">
      <b-form>
        <div class="form-group row">
          <b-col lg="6">
            <b-form-input
              type="text"
              id="name"
              placeholder="Name"
              class="mb-2"
            />
          </b-col>
          <b-col lg="6">
            <b-form-input
              type="email"
              id="example-email-input3"
              placeholder="Email"
              class="mb-2"
            />
          </b-col>
        </div>

        <b-form-group class="row">
          <b-col cols="12">
            <b-form-input
              type="text"
              class="mb-2"
              placeholder="Subject"
              id="subject2"
            />
          </b-col>
        </b-form-group>
        <b-form-group
          label-for="exampleFormControlTextarea1"
          label-class="mb-2"
        >
          <b-form-textarea
            type="textarea"
            rows="4"
            id="exampleFormControlTextarea1"
            placeholder="Your message"
          />
        </b-form-group>

        <b-button type="submit" variant="primary" class="btn-block px-4 mt-2"
          >Send Email</b-button
        >
      </b-form>
    </UIComponentCard>
  </b-col>
</template>
<script setup lang="ts">
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
