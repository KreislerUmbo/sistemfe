<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Badge Examples">
          <b-list-group>
            <b-list-group-item
              ><i class="la la-check text-success me-2"></i>Cras justo
              odio</b-list-group-item
            >
            <b-list-group-item
              ><i class="la la-check text-success me-2"></i>Dapibus ac facilisis
              in</b-list-group-item
            >
            <b-list-group-item
              ><i class="la la-check text-success me-2"></i>Morbi leo
              risus</b-list-group-item
            >
            <b-list-group-item disabled
              ><i class="la la-check text-success me-2"></i
              >Disabled</b-list-group-item
            >
          </b-list-group>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Active Items">
          <b-list-group>
            <b-list-group-item
              ><i class="la la-arrow-right text-secondary me-2"></i>Cras justo
              odio</b-list-group-item
            >
            <b-list-group-item active
              ><i class="la la-arrow-right me-2"></i>Dapibus ac facilisis
              in</b-list-group-item
            >
            <b-list-group-item
              ><i class="la la-arrow-right text-secondary me-2"></i>Morbi leo
              risus</b-list-group-item
            >
            <b-list-group-item
              ><i class="la la-arrow-right text-secondary me-2"></i>Porta ac
              consectetur ac</b-list-group-item
            >
          </b-list-group>
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Links And Buttons">
          <b-list-group>
            <b-list-group-item type="button" active
              ><i class="la la-hand-o-right me-2"></i>Button Active
            </b-list-group-item>
            <b-list-group-item type="button"
              ><i class="la la-hand-o-right text-primary me-2"></i>Button
            </b-list-group-item>
            <b-list-group-item href="#"
              ><i class="la la-hand-o-right text-primary me-2"></i>Link
            </b-list-group-item>
            <b-list-group-item href="#" disabled
              ><i class="la la-hand-o-right text-primary me-2"></i>Link disabled
            </b-list-group-item>
          </b-list-group>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Flush">
          <b-list-group flush>
            <b-list-group-item
              ><i class="la la-angle-double-right text-info me-2"></i>Cras justo
              odio</b-list-group-item
            >
            <b-list-group-item
              ><i class="la la-angle-double-right text-info me-2"></i>Dapibus ac
              facilisis in</b-list-group-item
            >
            <b-list-group-item
              ><i class="la la-angle-double-right text-info me-2"></i>Morbi leo
              risus</b-list-group-item
            >
            <b-list-group-item
              ><i class="la la-angle-double-right text-info me-2"></i>Vestibulum
              at eros</b-list-group-item
            >
          </b-list-group>
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Contextual Classes">
          <b-list-group>
            <b-list-group-item>Dapibus ac facilisis in</b-list-group-item>
            <b-list-group-item variant="primary"
              >A simple primary list group item</b-list-group-item
            >
            <b-list-group-item variant="secondary"
              >A simple secondary list group item</b-list-group-item
            >
            <b-list-group-item variant="success"
              >A simple success list group item</b-list-group-item
            >
            <b-list-group-item variant="danger"
              >A simple danger list group item</b-list-group-item
            >
            <b-list-group-item variant="warning"
              >A simple warning list group item</b-list-group-item
            >
            <b-list-group-item variant="info"
              >A simple info list group item</b-list-group-item
            >
            <b-list-group-item variant="light"
              >A simple light list group item</b-list-group-item
            >
            <b-list-group-item variant="dark"
              >A simple dark list group item</b-list-group-item
            >
          </b-list-group>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Horizontal">
          <b-list-group horizontal="md">
            <b-list-group-item
              ><i class="la la-angle-double-right text-info me-2"></i>Cras
              justo</b-list-group-item
            >
            <b-list-group-item
              ><i class="la la-angle-double-right text-info me-2"></i
              >Dapibus</b-list-group-item
            >
            <b-list-group-item
              ><i class="la la-angle-double-right text-info me-2"></i>Morbi
              leo</b-list-group-item
            >
          </b-list-group>
        </UIComponentCard>

        <UIComponentCard title="With Badges">
          <b-list-group>
            <b-list-group-item
              class="d-flex justify-content-between align-items-center"
            >
              <div>
                <i class="la la-check text-muted font-16 me-2"></i>Cras justo
                odio
              </div>
              <b-badge
                :variant="null"
                class="border border-primary text-primary badge-pill"
              >
                4
              </b-badge>
            </b-list-group-item>
            <b-list-group-item
              class="d-flex justify-content-between align-items-center"
            >
              <div>
                <i class="la la-bell text-muted font-18 me-2"></i>New
                Notifications
              </div>
              <b-badge
                :variant="null"
                class="border border-secondary text-secondary badge-pill"
              >
                New
              </b-badge>
            </b-list-group-item>
            <b-list-group-item
              class="d-flex justify-content-between align-items-center"
            >
              <div>
                <i class="la la-money text-muted font-16 me-2"></i>Payment
                Successfull
              </div>
              <b-badge
                :variant="null"
                class="border border-success text-success badge-pill"
              >
                Successfully
              </b-badge>
            </b-list-group-item>
            <b-list-group-item
              class="d-flex justify-content-between align-items-center"
            >
              <div>
                <i class="la la-warning text-muted font-16 me-2"></i>Payment
                pending
              </div>
              <b-badge
                :variant="null"
                class="border border-warning text-warning badge-pill"
              >
                Pending
              </b-badge>
            </b-list-group-item>
            <b-list-group-item
              class="d-flex justify-content-between align-items-center"
            >
              <div>
                <i class="la la-comments text-muted font-16 me-2"></i>Good
                Morning!
              </div>
              <b-badge
                :variant="null"
                class="border border-info text-info badge-pill"
              >
                1
              </b-badge>
            </b-list-group-item>
          </b-list-group>
        </UIComponentCard>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import DefaultLayout from "@/layouts/DefaultLayout.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
