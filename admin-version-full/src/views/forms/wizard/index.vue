<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col cols="12">
        <UIComponentCard title="Custom Steps Wizard">
          <b-form action="" method="post" id="custom-step">
            <nav>
              <div class="nav nav-tabs" id="nav-tab">
                <a
                  class="nav-link py-2"
                  :class="wizardShow === 1 && 'active'"
                  id="step1-tab"
                  @click="wizardShow = 1"
                  >Seller Details</a
                >
                <a
                  class="nav-link py-2"
                  :class="wizardShow === 2 && 'active'"
                  id="step2-tab"
                  @click="wizardShow = 2"
                  >Company Document</a
                >
                <a
                  class="nav-link py-2"
                  :class="wizardShow === 3 && 'active'"
                  id="step3-tab"
                  @click="wizardShow = 3"
                  >Bank Details</a
                >
                <a
                  class="nav-link py-2"
                  :class="wizardShow === 4 && 'active'"
                  id="step4-tab"
                  @click="wizardShow = 4"
                  >Confirm Detail</a
                >
              </div>
            </nav>
            <div class="tab-content" id="nav-tabContent">
              <div
                class="tab-pane"
                :class="wizardShow === 1 && 'active'"
                id="step1"
              >
                <Step1 :nextPage="nextPage" />
              </div>
              <div
                class="tab-pane"
                :class="wizardShow === 2 && 'active'"
                id="step2"
              >
                <Step2 :nextPage="nextPage" :previousPage="previousPage" />
              </div>
              <div
                class="tab-pane"
                :class="wizardShow === 3 && 'active'"
                id="step3"
              >
                <Step3 :nextPage="nextPage" :previousPage="previousPage" />
              </div>
              <div
                class="tab-pane"
                :class="wizardShow === 4 && 'active'"
                id="step4"
              >
                <Step4 :previousPage="previousPage" />
              </div>
            </div>
          </b-form>
        </UIComponentCard>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import { ref } from "vue";
import Step1 from "@/views/forms/wizard/components/Step1.vue";
import Step2 from "@/views/forms/wizard/components/Step2.vue";
import Step3 from "@/views/forms/wizard/components/Step3.vue";
import Step4 from "@/views/forms/wizard/components/Step4.vue";

const wizardShow = ref(1);

const nextPage = () => {
  wizardShow.value = wizardShow.value + 1;
};

const previousPage = () => {
  wizardShow.value = wizardShow.value - 1;
};

import DefaultLayout from "@/layouts/DefaultLayout.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
