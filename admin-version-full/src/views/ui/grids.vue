<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="12" lg="12">
        <UIComponentCard title="Grids">
          <b-table-simple responsive bordered striped class="table-centered">
            <b-thead>
              <b-tr>
                <b-th scope="col"></b-th>
                <b-th scope="col">
                  xs<br />
                  <span class="fw-normal">&lt;576px</span>
                </b-th>
                <b-th scope="col">
                  sm<br />
                  <span class="fw-normal">≥576px</span>
                </b-th>
                <b-th scope="col">
                  md<br />
                  <span class="fw-normal">≥768px</span>
                </b-th>
                <b-th scope="col">
                  lg<br />
                  <span class="fw-normal">≥992px</span>
                </b-th>
                <b-th scope="col">
                  xl<br />
                  <span class="fw-normal">≥1200px</span>
                </b-th>
                <b-th scope="col">
                  xxl<br />
                  <span class="fw-normal">≥1400px</span>
                </b-th>
              </b-tr>
            </b-thead>
            <b-tbody>
              <b-tr>
                <b-th class="text-nowrap" scope="row"
                  >Container <code class="fw-normal">max-width</code></b-th
                >
                <b-td>None (auto)</b-td>
                <b-td>540px</b-td>
                <b-td>720px</b-td>
                <b-td>960px</b-td>
                <b-td>1140px</b-td>
                <b-td>1320px</b-td>
              </b-tr>
              <b-tr>
                <b-th class="text-nowrap" scope="row">Class prefix</b-th>
                <b-td><code>.col-</code></b-td>
                <b-td><code>.col-sm-</code></b-td>
                <b-td><code>.col-md-</code></b-td>
                <b-td><code>.col-lg-</code></b-td>
                <b-td><code>.col-xl-</code></b-td>
                <b-td><code>.col-xxl-</code></b-td>
              </b-tr>
              <b-tr>
                <b-th class="text-nowrap" scope="row"># of columns</b-th>
                <b-td colspan="6">12</b-td>
              </b-tr>
              <b-tr>
                <b-th class="text-nowrap" scope="row">Gutter width</b-th>
                <b-td colspan="6">1.5rem (.75rem on left and right)</b-td>
              </b-tr>
              <b-tr>
                <b-th class="text-nowrap" scope="row">Custom gutters</b-th>
                <b-td colspan="6"><a href="#">Yes</a></b-td>
              </b-tr>
              <b-tr>
                <b-th class="text-nowrap" scope="row">Nestable</b-th>
                <b-td colspan="6"><a href="#">Yes</a></b-td>
              </b-tr>
              <b-tr>
                <b-th class="text-nowrap" scope="row">Column ordering</b-th>
                <b-td colspan="6"><a href="#">Yes</a></b-td>
              </b-tr>
            </b-tbody>
          </b-table-simple>
        </UIComponentCard>
      </b-col>
    </b-row>
    <b-row class="justify-content-center">
      <b-col md="12" lg="12">
        <UIComponentCard title="Grid-Example">
          <b-row class="text-center">
            <b-col sm="3"
              ><span class="border py-2 bg-light d-block mb-2 mb-lg-0"
                >.col-sm-3</span
              ></b-col
            >
            <b-col sm="3"
              ><span class="border py-2 bg-light d-block mb-2 mb-lg-0"
                >.col-sm-3</span
              ></b-col
            >
            <b-col sm="3"
              ><span class="border py-2 bg-light d-block mb-2 mb-lg-0"
                >.col-sm-3</span
              ></b-col
            >
            <b-col sm="3"
              ><span class="border py-2 bg-light d-block"
                >.col-sm-3</span
              ></b-col
            >
          </b-row>
        </UIComponentCard>
      </b-col>
    </b-row>
    <b-row class="justify-content-center">
      <b-col md="12" lg="12">
        <UIComponentCard title="Grid-Example">
          <b-row class="text-center">
            <b-col md="3"
              ><span class="border py-2 bg-light d-block mb-2 mb-lg-0"
                >.col-md-3</span
              ></b-col
            >
            <b-col md="6"
              ><span class="border py-2 bg-light d-block mb-2 mb-lg-0"
                >.col-md-6</span
              ></b-col
            >
            <b-col md="3"
              ><span class="border py-2 bg-light d-block"
                >.col-md-3</span
              ></b-col
            >
          </b-row>
        </UIComponentCard>
      </b-col>
    </b-row>
    <b-row class="justify-content-center">
      <b-col md="12" lg="12">
        <UIComponentCard title="Grid-Example">
          <b-row class="text-center">
            <b-col lg="6"
              ><span class="border py-2 bg-light d-block mb-2 mb-lg-0"
                >.col-lg-6</span
              ></b-col
            >
            <b-col lg="6"
              ><span class="border py-2 bg-light d-block"
                >.col-lg-6</span
              ></b-col
            >
          </b-row>
        </UIComponentCard>
      </b-col>
    </b-row>
    <b-row class="justify-content-center">
      <b-col md="12" lg="12">
        <UIComponentCard title="Grid-Example">
          <b-row class="text-center">
            <b-col lg="4"
              ><span class="border py-2 bg-light d-block mb-2 mb-lg-0"
                >.col-xl-4</span
              ></b-col
            >
            <b-col lg="4"
              ><span class="border py-2 bg-light d-block mb-2 mb-lg-0"
                >.col-xl-4</span
              ></b-col
            >
            <b-col lg="4"
              ><span class="border py-2 bg-light d-block"
                >.col-xl-4</span
              ></b-col
            >
          </b-row>
        </UIComponentCard>
      </b-col>
    </b-row>
    <b-row class="justify-content-center">
      <b-col md="12" lg="12">
        <UIComponentCard title="Grid-Example">
          <b-row class="text-center">
            <b-col xxl="6"
              ><span class="border py-2 bg-light d-block mb-2 mb-lg-0"
                >.col-xxl-6</span
              ></b-col
            >
            <b-col xxl="6"
              ><span class="border py-2 bg-light d-block"
                >.col-xxl-6</span
              ></b-col
            >
          </b-row>
        </UIComponentCard>
      </b-col>
    </b-row>
    <b-row class="justify-content-center">
      <b-col md="12" lg="12">
        <UIComponentCard title="Grid-Example">
          <div class="text-center">
            <b-row>
              <b-col sm="1"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-1</span
                ></b-col
              >
              <b-col sm="1"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-1</span
                ></b-col
              >
              <b-col sm="1"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-1</span
                ></b-col
              >
              <b-col sm="1"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-1</span
                ></b-col
              >
              <b-col sm="1"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-1</span
                ></b-col
              >
              <b-col sm="1"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-1</span
                ></b-col
              >
              <b-col sm="1"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-1</span
                ></b-col
              >
              <b-col sm="1"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-1</span
                ></b-col
              >
              <b-col sm="1"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-1</span
                ></b-col
              >
              <b-col sm="1"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-1</span
                ></b-col
              >
              <b-col sm="1"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-1</span
                ></b-col
              >
              <b-col sm="1"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-1</span
                ></b-col
              >
            </b-row>

            <b-row>
              <b-col sm="2"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-2</span
                ></b-col
              >
              <b-col sm="2"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-2</span
                ></b-col
              >
              <b-col sm="2"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-2</span
                ></b-col
              >
              <b-col sm="2"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-2</span
                ></b-col
              >
              <b-col sm="2"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-2</span
                ></b-col
              >
              <b-col sm="2"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-2</span
                ></b-col
              >
            </b-row>

            <b-row>
              <b-col sm="3"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-3</span
                ></b-col
              >
              <b-col sm="3"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-3</span
                ></b-col
              >
              <b-col sm="3"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-3</span
                ></b-col
              >
              <b-col sm="3"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-3</span
                ></b-col
              >
            </b-row>

            <b-row>
              <b-col sm="4"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-4</span
                ></b-col
              >
              <b-col sm="4"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-4</span
                ></b-col
              >
              <b-col sm="4"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-4</span
                ></b-col
              >
            </b-row>

            <b-row>
              <b-col sm="6"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-6</span
                ></b-col
              >
              <b-col sm="6"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-6</span
                ></b-col
              >
            </b-row>

            <b-row>
              <b-col sm="12"
                ><span class="border py-2 bg-light d-block"
                  >.col-sm-12</span
                ></b-col
              >
            </b-row>
          </div>
        </UIComponentCard>
      </b-col>
    </b-row>
    <b-row class="justify-content-center">
      <b-col md="12" lg="12">
        <UIComponentCard title="Grid-Example">
          <div class="text-center">
            <b-row>
              <b-col sm="8" class="offset-sm-2"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-8 offset-sm-2</span
                ></b-col
              >
            </b-row>
            <b-row>
              <b-col sm="3" class="ml-auto"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-3 ml-auto</span
                ></b-col
              >
              <b-col sm="3" class="ml-auto"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-3 ml-auto</span
                ></b-col
              >
            </b-row>
            <b-row>
              <b-col sm="3"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-3</span
                ></b-col
              >
              <b-col sm="3" class="offset-sm-3"
                ><span class="border py-2 bg-light d-block mb-2"
                  >.col-sm-3 offset-sm-3</span
                ></b-col
              >
            </b-row>
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
