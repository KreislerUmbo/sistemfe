<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Clipboard Examples">
          <div class="input-group">
            <b-form-input type="text" id="clipboardInput" v-model="inputEle1" />
            <b-button
              variant="secondary"
              type="button"
              @click="(e: any) => doCopy(inputEle, e)"
            >
              <i class="far fa-copy me-2" />Copy
            </b-button>
          </div>
        </UIComponentCard>

        <UIComponentCard title="Paragraph Examples">
          <p id="clipboardParagraph" class="border rounded p-3">
            {{ textareaEle }}
          </p>
          <div class="mt-3">
            <b-button
              type="button"
              variant="secondary"
              class="btn-clipboard"
              @click="(e: any) => doCopy(textareaEle, e)"
            >
              <i class="far fa-copy me-2"></i>Copy
            </b-button>
          </div>
        </UIComponentCard>
      </b-col>

      <b-col md="6" lg="6">
        <UIComponentCard title="Textarea Examples">
          <b-form-textarea v-model="inputEle" rows="3" id="clipboardTextarea" />
          <div class="mt-3">
            <b-button
              type="button"
              variant="secondary"
              class="btn-clipboard me-1"
              @click="(e: any) => doCopy(inputEle, e)"
            >
              <i class="far fa-copy me-2" />Copy
            </b-button>
          </div>
        </UIComponentCard>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import { ref } from "vue";
import { copyText } from "vue3-clipboard";

const inputEle = ref("Welcome to Rizz !");
const inputEle1 = ref("Welcome to Rizz !");
const textareaEle = ref(
  "Contrary to popular belief, Lorem Ipsum is not simply random text. It has roots in a piece of classical Latin literature from 45 BC, making it over 2000 years old. Richard McClintock, a Latin professor at Hampden-Sydney College in Virginia, looked",
);

const doCopy = (text: string, e: any) => {
  copyText(text, undefined, (error: string) => {
    if (error) {
      console.log(error);
      return;
    } else {
      const text = e.srcElement.innerText;
      e.srcElement.innerText = "Copied!";
      setTimeout(() => {
        e.srcElement.innerText = text;
      }, 3000);
    }
  });
};

import DefaultLayout from "@/layouts/DefaultLayout.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
