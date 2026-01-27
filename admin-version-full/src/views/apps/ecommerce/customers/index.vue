<template>
  <DefaultLayout>
    <b-row>
      <b-col cols="12">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <div class="col">
                <b-card-title>Customers</b-card-title>
              </div>
              <div class="col-auto">
                <form class="row g-2">
                  <div class="col-auto">
                    <b-dropdown
                      variant="primary"
                      menu-class="dropdown-menu-start"
                      toggle-class="btn bg-primary-subtle text-primary d-flex align-items-center"
                    >
                      <template #button-content>
                        <i class="iconoir-filter-alt me-1"></i> Filter
                      </template>
                      <b-dropdown-text class="m-0 p-0">
                        <div class="p-2">
                          <div class="form-check mb-2">
                            <input
                              type="checkbox"
                              class="form-check-input"
                              checked
                              id="filter-all"
                            />
                            <label class="form-check-label" for="filter-all">
                              All
                            </label>
                          </div>
                          <div class="form-check mb-2">
                            <input
                              type="checkbox"
                              class="form-check-input"
                              checked
                              id="filter-one"
                            />
                            <label class="form-check-label" for="filter-one">
                              New
                            </label>
                          </div>
                          <div class="form-check mb-2">
                            <input
                              type="checkbox"
                              class="form-check-input"
                              checked
                              id="filter-two"
                            />
                            <label class="form-check-label" for="filter-two">
                              VIP
                            </label>
                          </div>
                          <div class="form-check mb-2">
                            <input
                              type="checkbox"
                              class="form-check-input"
                              checked
                              id="filter-three"
                            />
                            <label class="form-check-label" for="filter-three">
                              Repeat
                            </label>
                          </div>
                          <div class="form-check mb-2">
                            <input
                              type="checkbox"
                              class="form-check-input"
                              checked
                              id="filter-four"
                            />
                            <label class="form-check-label" for="filter-four">
                              Referral
                            </label>
                          </div>
                          <div class="form-check mb-2">
                            <input
                              type="checkbox"
                              class="form-check-input"
                              checked
                              id="filter-five"
                            />
                            <label class="form-check-label" for="filter-five">
                              Inactive
                            </label>
                          </div>
                          <div class="form-check">
                            <input
                              type="checkbox"
                              class="form-check-input"
                              checked
                              id="filter-six"
                            />
                            <label class="form-check-label" for="filter-six">
                              Loyal
                            </label>
                          </div>
                        </div>
                      </b-dropdown-text>
                    </b-dropdown>
                  </div>

                  <div class="col-auto">
                    <b-button type="button" variant="primary">
                      <i class="fa-solid fa-plus me-1"></i> Add Product
                    </b-button>
                  </div>
                </form>
              </div>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <b-table-simple
              responsive
              class="mb-0 checkbox-all text-nowrap"
              id="datatable_1"
            >
              <b-thead class="table-light">
                <b-tr>
                  <b-th style="width: 16px">
                    <div class="form-check mb-0 ms-n1">
                      <input
                        type="checkbox"
                        class="form-check-input"
                        name="select-all"
                        id="select-all"
                      />
                    </div>
                  </b-th>
                  <b-th class="ps-0">Customer</b-th>
                  <b-th>Email</b-th>
                  <b-th>Status</b-th>
                  <b-th>Order</b-th>
                  <b-th>Spent</b-th>
                  <b-th class="text-end">Action</b-th>
                </b-tr>
              </b-thead>
              <b-tbody>
                <b-tr v-for="(item, idx) in customersData" :key="idx">
                  <b-td style="width: 16px">
                    <div class="form-check">
                      <input
                        type="checkbox"
                        class="form-check-input"
                        name="check"
                        :id="item.checkboxId"
                      />
                    </div>
                  </b-td>
                  <b-td class="ps-0">
                    <img
                      :src="item.customer.avatar"
                      alt=""
                      class="thumb-md d-inline rounded-circle me-1"
                    />{{ " " }}
                    <p class="d-inline-block align-middle mb-0">
                      <span class="font-13 fw-medium">{{
                        item.customer.name
                      }}</span>
                    </p>
                  </b-td>
                  <b-td
                    ><a
                      href=""
                      class="d-inline-block align-middle mb-0 text-body"
                      >{{ item.email }}</a
                    >
                  </b-td>
                  <b-td v-if="item.status === 'VIP'">
                    <b-badge
                      :variant="null"
                      class="bg-danger-subtle text-danger"
                    >
                      {{ kebabToTitleCase(item.status) }}
                    </b-badge>
                  </b-td>
                  <b-td v-else-if="item.status === 'inactive'">
                    <b-badge
                      :variant="null"
                      class="bg-secondary-subtle text-secondary"
                    >
                      {{ kebabToTitleCase(item.status) }}
                    </b-badge>
                  </b-td>
                  <b-td v-else-if="item.status === 'repeat'">
                    <b-badge :variant="null" class="bg-blue-subtle text-blue">
                      {{ kebabToTitleCase(item.status) }}
                    </b-badge>
                  </b-td>
                  <b-td v-else>
                    <b-badge
                      :variant="null"
                      class="bg-success-subtle text-success"
                    >
                      {{ kebabToTitleCase(item.status) }}
                    </b-badge>
                  </b-td>
                  <b-td>{{ item.order }}</b-td>
                  <b-td>{{ currency }}{{ item.spent }}</b-td>
                  <b-td class="text-end">
                    <a href="#"
                      ><i class="las la-pen text-secondary fs-18"></i></a
                    >{{ " " }}
                    <a href="#"
                      ><i class="las la-trash-alt text-secondary fs-18"></i
                    ></a>
                  </b-td>
                </b-tr>
              </b-tbody>
            </b-table-simple>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import { onMounted } from "vue";
import { DataTable } from "simple-datatables";
import { currency } from "@/helpers/constants";
import { kebabToTitleCase } from "@/helpers/change-casing";
import { customersData } from "@/views/apps/ecommerce/customers/components/data";

onMounted(() => {
  new DataTable("#datatable_1", {
    searchable: true,
    fixedHeight: false,
    classes: {
      table: "datatable-table mb-0",
    },
  });
});

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
