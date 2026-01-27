<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Border spinner">
          <b-spinner variant="primary" />
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Growing spinner">
          <b-spinner variant="primary" type="grow" />
        </UIComponentCard>
      </b-col>
    </b-row>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Colors">
          <div class="hstack gap-1">
            <b-spinner variant="primary" />
            <b-spinner variant="secondary" />
            <b-spinner variant="success" />
            <b-spinner variant="danger" />
            <b-spinner variant="warning" />
            <b-spinner variant="info" />
            <b-spinner :variant="null" class="text-purple" />
            <b-spinner variant="light" />
            <b-spinner variant="dark" />
          </div>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Colors Growing">
          <div class="hstack gap-1">
            <b-spinner type="grow" variant="primary" />
            <b-spinner type="grow" variant="secondary" />
            <b-spinner type="grow" variant="success" />
            <b-spinner type="grow" variant="danger" />
            <b-spinner type="grow" variant="warning" />
            <b-spinner type="grow" variant="info" />
            <b-spinner type="grow" :variant="null" class="text-purple" />
            <b-spinner type="grow" variant="light" />
            <b-spinner type="grow" variant="dark" />
          </div>
        </UIComponentCard>
      </b-col>
    </b-row>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Alignment">
          <div class="d-flex justify-content-center">
            <b-spinner variant="primary" />
          </div>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Placement">
          <div class="d-flex align-items-center">
            <strong>Loading...</strong>
            <b-spinner variant="primary" class="text-primary ms-auto" />
          </div>
        </UIComponentCard>
      </b-col>
    </b-row>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Size">
          <b-row>
            <b-col lg="4" class="d-flex gap-1">
              <b-spinner style="width: 3rem; height: 3rem" variant="primary" />
              <b-spinner style="width: 3rem; height: 3rem" type="grow" />
            </b-col>

            <b-col lg="4" class="d-flex gap-1">
              <b-spinner variant="primary" />
              <b-spinner type="grow" />
            </b-col>

            <b-col lg="4" class="d-flex gap-1">
              <b-spinner variant="primary" small />
              <b-spinner type="grow" small />
            </b-col>
          </b-row>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Buttons spinners">
          <div class="d-flex flex-wrap gap-2">
            <b-button variant="primary" type="button" disabled>
              <b-spinner small />
            </b-button>
            <b-button variant="primary" type="button" disabled>
              <b-spinner small />
              Loading...
            </b-button>
            <b-button variant="primary" type="button" disabled>
              <b-spinner type="grow" small />
            </b-button>
            <b-button variant="primary" type="button" disabled>
              <b-spinner type="grow" small />
              Loading...
            </b-button>
          </div>
        </UIComponentCard>
      </b-col>
    </b-row>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Custom spinners">
          <div class="hstack gap-1">
            <b-spinner
              :variant="null"
              class="spinner-border spinner-border-custom-1 text-primary"
            />
            <b-spinner
              :variant="null"
              class="spinner-border spinner-border-custom-2 text-secondary"
            />
            <b-spinner
              :variant="null"
              class="spinner-border spinner-border-custom-3 border-success"
            />
            <b-spinner
              :variant="null"
              class="spinner-border spinner-border-custom-4 text-warning"
            />
            <b-spinner
              :variant="null"
              class="spinner-border spinner-border-custom-5 border-info"
            />
          </div>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Custom Icon Spinners">
          <b-row>
            <b-col md="3">
              <div class="text-center">
                <i
                  class="la la-spinner text-primary la-spin progress-icon-spin"
                ></i>
              </div>
            </b-col>
            <b-col md="3">
              <div class="text-center">
                <i
                  class="la la-refresh text-secondary la-spin progress-icon-spin"
                ></i>
              </div>
            </b-col>
            <b-col md="3">
              <div class="text-center">
                <i
                  class="la la-sun-o text-warning la-spin progress-icon-spin"
                ></i>
              </div>
            </b-col>
            <b-col md="3">
              <div class="text-center">
                <i class="la la-cog la-spin progress-icon-spin"></i>
              </div>
            </b-col>
          </b-row>
        </UIComponentCard>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import DefaultLayout from "@/layouts/DefaultLayout.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
