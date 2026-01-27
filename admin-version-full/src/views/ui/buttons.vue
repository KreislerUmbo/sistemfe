<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Default Buttons">
          <div class="d-flex flex-wrap gap-2">
            <b-button type="button" variant="primary">Primary</b-button>
            <b-button type="button" variant="secondary">Secondary</b-button>
            <b-button type="button" variant="success">Success</b-button>
            <b-button type="button" variant="warning">Warning</b-button>
            <b-button type="button" variant="info">Info</b-button>
            <b-button type="button" variant="danger">Danger</b-button>
            <b-button type="button" variant="dark">Dark</b-button>
            <b-button type="button" variant="light">Light</b-button>
          </div>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Outline Buttons">
          <div class="d-flex flex-wrap gap-2">
            <b-button type="button" variant="outline-primary">Primary</b-button>
            <b-button type="button" variant="outline-secondary"
              >Secondary</b-button
            >
            <b-button type="button" variant="outline-success">Success</b-button>
            <b-button type="button" variant="outline-warning">Warning</b-button>
            <b-button type="button" variant="outline-info">Info</b-button>
            <b-button type="button" variant="outline-danger">Danger</b-button>
            <b-button type="button" variant="outline-dark">Dark</b-button>
            <b-button type="button" variant="outline-light">Light</b-button>
          </div>
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Round Buttons">
          <div class="d-flex flex-wrap gap-2">
            <b-button type="button" pill variant="primary">Primary</b-button>
            <b-button type="button" pill variant="secondary"
              >Secondary</b-button
            >
            <b-button type="button" pill variant="success">Success</b-button>
            <b-button type="button" pill variant="warning">Warning</b-button>
            <b-button type="button" pill variant="info">Info</b-button>
            <b-button type="button" pill variant="danger">Danger</b-button>
            <b-button type="button" pill variant="dark">Dark</b-button>
            <b-button type="button" pill variant="light">Light</b-button>
          </div>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Round Outline Buttons">
          <div class="d-flex flex-wrap gap-2">
            <b-button type="button" pill variant="outline-primary"
              >Primary</b-button
            >
            <b-button type="button" pill variant="outline-secondary"
              >Secondary</b-button
            >
            <b-button type="button" pill variant="outline-success"
              >Success</b-button
            >
            <b-button type="button" pill variant="outline-warning"
              >Warning</b-button
            >
            <b-button type="button" pill variant="outline-info">Info</b-button>
            <b-button type="button" pill variant="outline-danger"
              >Danger</b-button
            >
            <b-button type="button" pill variant="outline-dark">Dark</b-button>
            <b-button type="button" pill variant="outline-light"
              >Light</b-button
            >
          </div>
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Buttons Sizes">
          <div class="d-flex flex-wrap align-items-center gap-2">
            <b-button type="button" size="lg" variant="primary"
              >Primary</b-button
            >
            <b-button type="button" variant="secondary">Secondary</b-button>
            <b-button type="button" size="sm" variant="dark">Dark</b-button>
          </div>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Outline Buttons Sizes">
          <div class="d-flex flex-wrap align-items-center gap-2">
            <b-button type="button" size="lg" variant="outline-primary"
              >Primary</b-button
            >
            <b-button type="button" variant="outline-secondary"
              >Secondary</b-button
            >
            <b-button type="button" size="sm" variant="outline-dark"
              >Dark</b-button
            >
          </div>
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Disabled Buttons">
          <div class="d-flex flex-wrap gap-2">
            <b-button type="button" variant="primary" disabled
              >Primary</b-button
            >
            <b-button type="button" variant="secondary" disabled
              >Secondary</b-button
            >
          </div>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Disabled Outline Buttons">
          <div class="d-flex flex-wrap gap-2">
            <b-button type="button" variant="outline-primary" disabled
              >Primary</b-button
            >
            <b-button type="button" variant="outline-secondary" disabled
              >Secondary</b-button
            >
          </div>
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Block Buttons">
          <div class="d-flex flex-wrap gap-2">
            <b-button type="button" variant="primary" class="w-100"
              >Primary</b-button
            >
            <b-button type="button" variant="secondary" class="w-100"
              >Secondary</b-button
            >
          </div>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Block Outline Buttons">
          <div class="d-flex flex-wrap gap-2">
            <b-button type="button" variant="outline-primary" class="w-100"
              >Primary</b-button
            >
            <b-button type="button" variant="outline-secondary" class="w-100"
              >Secondary</b-button
            >
          </div>
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Check And Radio Buttons">
          <b-button-group class="mb-2 mb-lg-0">
            <input
              type="checkbox"
              class="btn-check"
              id="btncheck1"
              autocomplete="off"
            />
            <label class="btn btn-outline-primary" for="btncheck1"
              >Checkbox 1</label
            >

            <input
              type="checkbox"
              class="btn-check"
              id="btncheck2"
              autocomplete="off"
            />
            <label class="btn btn-outline-primary" for="btncheck2"
              >Checkbox 2</label
            >

            <input
              type="checkbox"
              class="btn-check"
              id="btncheck3"
              autocomplete="off"
            />
            <label class="btn btn-outline-primary" for="btncheck3"
              >Checkbox 3</label
            >
          </b-button-group>
          <b-button-group class="float-end">
            <input
              type="radio"
              class="btn-check"
              name="btnradio"
              id="btnradio1"
              autocomplete="off"
              checked
            />
            <label class="btn btn-outline-secondary" for="btnradio1"
              >Radio 1</label
            >

            <input
              type="radio"
              class="btn-check"
              name="btnradio"
              id="btnradio2"
              autocomplete="off"
            />
            <label class="btn btn-outline-secondary" for="btnradio2"
              >Radio 2</label
            >

            <input
              type="radio"
              class="btn-check"
              name="btnradio"
              id="btnradio3"
              autocomplete="off"
            />
            <label class="btn btn-outline-secondary" for="btnradio3"
              >Radio 3</label
            >
          </b-button-group>
        </UIComponentCard>

        <UIComponentCard title="Button Group">
          <div class="btn-group-vertical">
            <b-button type="button" variant="primary">Button 1</b-button>
            <b-button type="button" variant="primary">Button 2</b-button>
            <b-dropdown text="Button 3" variant="primary">
              <b-dropdown-item>Dropdown link</b-dropdown-item>
              <b-dropdown-item>Dropdown link</b-dropdown-item>
            </b-dropdown>
          </div>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Button Group">
          <div class="mb-3">
            <b-button-group>
              <b-button type="button" variant="outline-secondary"
                ><i class="fa fa-align-left"></i
              ></b-button>
              <b-button type="button" variant="outline-secondary"
                ><i class="fa fa-align-center"></i
              ></b-button>
              <b-button type="button" variant="outline-secondary"
                ><i class="fa fa-align-right"></i
              ></b-button>
            </b-button-group>
          </div>
          <div class="mb-3">
            <b-button-group>
              <b-button type="button" variant="outline-secondary"
                ><i class="las la-play"></i
              ></b-button>
              <b-button type="button" variant="outline-primary" active
                ><i class="las la-pause"></i
              ></b-button>
              <b-button type="button" variant="outline-secondary"
                ><i class="las la-stop"></i
              ></b-button>
            </b-button-group>
          </div>
          <div class="mb-3">
            <b-button-group>
              <b-button type="button" variant="outline-secondary"
                >Left</b-button
              >
              <b-button type="button" variant="outline-secondary"
                >Middle</b-button
              >
              <b-button type="button" variant="outline-secondary"
                >Right</b-button
              >
            </b-button-group>
          </div>
          <div class="mb-3">
            <b-button-group>
              <b-button type="button" variant="outline-secondary">1</b-button>
              <b-button type="button" variant="outline-secondary">2</b-button>
              <b-button type="button" variant="primary" active>3</b-button>
              <b-button type="button" variant="outline-secondary"
                >4</b-button
              > </b-button-group
            >{{ " " }}
            <b-button-group>
              <b-button type="button" variant="outline-secondary">5</b-button>
              <b-button type="button" variant="primary" active>6</b-button>
              <b-button type="button" variant="outline-secondary"
                >7</b-button
              > </b-button-group
            >{{ " " }}
            <b-button-group>
              <b-button type="button" variant="outline-secondary">8</b-button>
            </b-button-group>
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
