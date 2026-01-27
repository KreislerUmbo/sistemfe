<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Default Dropdown">
          <div class="d-flex flex-wrap gap-2">
            <b-dropdown variant="primary">
              <template #button-content>
                Primary <i class="las la-angle-down ms-1"></i>
              </template>
              <b-dropdown-item>Action</b-dropdown-item>
              <b-dropdown-item>Another action</b-dropdown-item>
              <b-dropdown-item>Something else here</b-dropdown-item>
              <b-dropdown-divider />
              <b-dropdown-item>Separated link</b-dropdown-item>
            </b-dropdown>
            <b-dropdown variant="secondary">
              <template #button-content>
                Secondary <i class="las la-angle-down ms-1"></i>
              </template>
              <b-dropdown-item>Action</b-dropdown-item>
              <b-dropdown-item>Another action</b-dropdown-item>
              <b-dropdown-item>Something else here</b-dropdown-item>
              <b-dropdown-divider />
              <b-dropdown-item>Separated link</b-dropdown-item>
            </b-dropdown>
            <b-dropdown variant="warning">
              <template #button-content>
                Warning <i class="las la-angle-down ms-1"></i>
              </template>
              <b-dropdown-item>Action</b-dropdown-item>
              <b-dropdown-item>Another action</b-dropdown-item>
              <b-dropdown-item>Something else here</b-dropdown-item>
              <b-dropdown-divider />
              <b-dropdown-item>Separated link</b-dropdown-item>
            </b-dropdown>
            <b-dropdown variant="info">
              <template #button-content>
                Info <i class="las la-angle-down ms-1"></i>
              </template>
              <b-dropdown-item>Action</b-dropdown-item>
              <b-dropdown-item>Another action</b-dropdown-item>
              <b-dropdown-item>Something else here</b-dropdown-item>
              <b-dropdown-divider />
              <b-dropdown-item>Separated link</b-dropdown-item>
            </b-dropdown>
            <b-dropdown variant="danger">
              <template #button-content>
                Danger <i class="las la-angle-down ms-1"></i>
              </template>
              <b-dropdown-item>Action</b-dropdown-item>
              <b-dropdown-item>Another action</b-dropdown-item>
              <b-dropdown-item>Something else here</b-dropdown-item>
              <b-dropdown-divider />
              <b-dropdown-item>Separated link</b-dropdown-item>
            </b-dropdown>
            <b-dropdown variant="dark">
              <template #button-content>
                Dark <i class="las la-angle-down ms-1"></i>
              </template>
              <b-dropdown-item>Action</b-dropdown-item>
              <b-dropdown-item>Another action</b-dropdown-item>
              <b-dropdown-item>Something else here</b-dropdown-item>
              <b-dropdown-divider />
              <b-dropdown-item>Separated link</b-dropdown-item>
            </b-dropdown>
            <b-dropdown variant="light">
              <template #button-content>
                Light <i class="las la-angle-down ms-1"></i>
              </template>
              <b-dropdown-item>Action</b-dropdown-item>
              <b-dropdown-item>Another action</b-dropdown-item>
              <b-dropdown-item>Something else here</b-dropdown-item>
              <b-dropdown-divider />
              <b-dropdown-item>Separated link</b-dropdown-item>
            </b-dropdown>
          </div>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Outline Dropdown">
          <div class="d-flex flex-wrap gap-2">
            <b-dropdown variant="outline-primary">
              <template #button-content>
                Primary <i class="las la-angle-down ms-1"></i>
              </template>
              <b-dropdown-item>Action</b-dropdown-item>
              <b-dropdown-item>Another action</b-dropdown-item>
              <b-dropdown-item>Something else here</b-dropdown-item>
              <b-dropdown-divider />
              <b-dropdown-item>Separated link</b-dropdown-item>
            </b-dropdown>
            <b-dropdown variant="outline-secondary">
              <template #button-content>
                Secondary <i class="las la-angle-down ms-1"></i>
              </template>
              <b-dropdown-item>Action</b-dropdown-item>
              <b-dropdown-item>Another action</b-dropdown-item>
              <b-dropdown-item>Something else here</b-dropdown-item>
              <b-dropdown-divider />
              <b-dropdown-item>Separated link</b-dropdown-item>
            </b-dropdown>
            <b-dropdown variant="outline-warning">
              <template #button-content>
                Warning <i class="las la-angle-down ms-1"></i>
              </template>
              <b-dropdown-item>Action</b-dropdown-item>
              <b-dropdown-item>Another action</b-dropdown-item>
              <b-dropdown-item>Something else here</b-dropdown-item>
              <b-dropdown-divider />
              <b-dropdown-item>Separated link</b-dropdown-item>
            </b-dropdown>
            <b-dropdown variant="outline-info">
              <template #button-content>
                Info <i class="las la-angle-down ms-1"></i>
              </template>
              <b-dropdown-item>Action</b-dropdown-item>
              <b-dropdown-item>Another action</b-dropdown-item>
              <b-dropdown-item>Something else here</b-dropdown-item>
              <b-dropdown-divider />
              <b-dropdown-item>Separated link</b-dropdown-item>
            </b-dropdown>
            <b-dropdown variant="outline-danger">
              <template #button-content>
                Danger <i class="las la-angle-down ms-1"></i>
              </template>
              <b-dropdown-item>Action</b-dropdown-item>
              <b-dropdown-item>Another action</b-dropdown-item>
              <b-dropdown-item>Something else here</b-dropdown-item>
              <b-dropdown-divider />
              <b-dropdown-item>Separated link</b-dropdown-item>
            </b-dropdown>
            <b-dropdown variant="outline-dark">
              <template #button-content>
                Dark <i class="las la-angle-down ms-1"></i>
              </template>
              <b-dropdown-item>Action</b-dropdown-item>
              <b-dropdown-item>Another action</b-dropdown-item>
              <b-dropdown-item>Something else here</b-dropdown-item>
              <b-dropdown-divider />
              <b-dropdown-item>Separated link</b-dropdown-item>
            </b-dropdown>
            <b-dropdown variant="outline-light">
              <template #button-content>
                Light <i class="las la-angle-down ms-1"></i>
              </template>
              <b-dropdown-item>Action</b-dropdown-item>
              <b-dropdown-item>Another action</b-dropdown-item>
              <b-dropdown-item>Something else here</b-dropdown-item>
              <b-dropdown-divider />
              <b-dropdown-item>Separated link</b-dropdown-item>
            </b-dropdown>
          </div>
        </UIComponentCard>
      </b-col>
    </b-row>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Dropdwn Rounded">
          <div class="d-flex flex-wrap gap-2">
            <b-dropdown variant="primary" toggleClass="rounded-pill">
              <template #button-content>
                Primary <i class="las la-angle-down ms-1"></i>
              </template>
              <b-dropdown-item>Action</b-dropdown-item>
              <b-dropdown-item>Another action</b-dropdown-item>
              <b-dropdown-item>Something else here</b-dropdown-item>
              <b-dropdown-divider />
              <b-dropdown-item>Separated link</b-dropdown-item>
            </b-dropdown>

            <b-dropdown variant="secondary" toggleClass="rounded-pill">
              <template #button-content>
                Secondary <i class="las la-angle-down ms-1"></i>
              </template>
              <b-dropdown-item>Action</b-dropdown-item>
              <b-dropdown-item>Another action</b-dropdown-item>
              <b-dropdown-item>Something else here</b-dropdown-item>
              <b-dropdown-divider />
              <b-dropdown-item>Separated link</b-dropdown-item>
            </b-dropdown>
          </div>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Dropdown Outline Rounded">
          <div class="d-flex flex-wrap gap-2">
            <b-dropdown variant="outline-primary" toggleClass="rounded-pill">
              <template #button-content>
                Primary <i class="las la-angle-down ms-1"></i>
              </template>
              <b-dropdown-item>Action</b-dropdown-item>
              <b-dropdown-item>Another action</b-dropdown-item>
              <b-dropdown-item>Something else here</b-dropdown-item>
              <b-dropdown-divider />
              <b-dropdown-item>Separated link</b-dropdown-item>
            </b-dropdown>

            <b-dropdown variant="outline-secondary" toggleClass="rounded-pill">
              <template #button-content>
                Secondary <i class="las la-angle-down ms-1"></i>
              </template>
              <b-dropdown-item>Action</b-dropdown-item>
              <b-dropdown-item>Another action</b-dropdown-item>
              <b-dropdown-item>Something else here</b-dropdown-item>
              <b-dropdown-divider />
              <b-dropdown-item>Separated link</b-dropdown-item>
            </b-dropdown>
          </div>
        </UIComponentCard>
      </b-col>
    </b-row>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Dropdwn Size">
          <div class="d-flex flex-wrap gap-2">
            <b-dropdown variant="primary" size="lg">
              <template #button-content>
                Primary <i class="las la-angle-down ms-1"></i>
              </template>
              <b-dropdown-item>Action</b-dropdown-item>
              <b-dropdown-item>Another action</b-dropdown-item>
              <b-dropdown-item>Something else here</b-dropdown-item>
              <b-dropdown-divider />
              <b-dropdown-item>Separated link</b-dropdown-item>
            </b-dropdown>

            <b-dropdown variant="secondary">
              <template #button-content>
                Secondary <i class="las la-angle-down ms-1"></i>
              </template>
              <b-dropdown-item>Action</b-dropdown-item>
              <b-dropdown-item>Another action</b-dropdown-item>
              <b-dropdown-item>Something else here</b-dropdown-item>
              <b-dropdown-divider />
              <b-dropdown-item>Separated link</b-dropdown-item>
            </b-dropdown>

            <b-dropdown variant="dark" size="sm">
              <template #button-content>
                Dark <i class="las la-angle-down ms-1"></i>
              </template>
              <b-dropdown-item>Action</b-dropdown-item>
              <b-dropdown-item>Another action</b-dropdown-item>
              <b-dropdown-item>Something else here</b-dropdown-item>
              <b-dropdown-divider />
              <b-dropdown-item>Separated link</b-dropdown-item>
            </b-dropdown>
          </div>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Dropdwn Button & Link">
          <b-row class="g-2">
            <div class="col-sm-auto">
              <b-dropdown variant="outline-primary">
                <template #button-content>
                  Dropdown button <i class="las la-angle-down ms-1"></i>
                </template>
                <b-dropdown-item>Action</b-dropdown-item>
                <b-dropdown-item>Another action</b-dropdown-item>
                <b-dropdown-item>Something else here</b-dropdown-item>
              </b-dropdown>
            </div>

            <div class="col-sm-auto">
              <b-dropdown variant="outline-secondary">
                <template #button-content>
                  Dropdown link <i class="las la-angle-down ms-1"></i>
                </template>
                <b-dropdown-item>Action</b-dropdown-item>
                <b-dropdown-item>Another action</b-dropdown-item>
                <b-dropdown-item>Something else here</b-dropdown-item>
              </b-dropdown>
            </div>
          </b-row>
        </UIComponentCard>
      </b-col>
    </b-row>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Dropup Variation">
          <div class="d-flex flex-wrap gap-2 dropup">
            <b-dropdown dropup variant="primary">
              <template #button-content>
                Primary <i class="las la-angle-up ms-1"></i>
              </template>
              <b-dropdown-item>Action</b-dropdown-item>
              <b-dropdown-item>Another action</b-dropdown-item>
              <b-dropdown-item>Something else here</b-dropdown-item>
              <b-dropdown-divider />
              <b-dropdown-item>Separated link</b-dropdown-item>
            </b-dropdown>

            <DropDown>
              <div class="btn-group dropup">
                <button type="button" class="btn btn-secondary me-0">
                  Split dropup
                </button>
                <button
                  type="button"
                  class="btn btn-secondary dropdown-toggle dropdown-toggle-split"
                  data-bs-toggle="dropdown"
                  aria-expanded="false"
                >
                  <i class="las la-angle-up"></i>
                </button>
                <div class="dropdown-menu">
                  <a class="dropdown-item" href="#">Action</a>
                  <a class="dropdown-item" href="#">Another action</a>
                  <a class="dropdown-item" href="#">Something else here</a>
                  <div class="dropdown-divider"></div>
                  <a class="dropdown-item" href="#">Separated link</a>
                </div>
              </div>
            </DropDown>
          </div>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Dropend Variation">
          <div class="d-flex flex-wrap gap-2 dropend">
            <b-dropdown dropend variant="primary">
              <template #button-content>
                Primary <i class="las la-angle-right ms-1"></i>
              </template>
              <b-dropdown-item>Action</b-dropdown-item>
              <b-dropdown-item>Another action</b-dropdown-item>
              <b-dropdown-item>Something else here</b-dropdown-item>
              <b-dropdown-divider />
              <b-dropdown-item>Separated link</b-dropdown-item>
            </b-dropdown>

            <DropDown>
              <div class="btn-group dropend">
                <button type="button" class="btn btn-secondary me-0">
                  Split dropend
                </button>
                <button
                  type="button"
                  class="btn btn-secondary dropdown-toggle dropdown-toggle-split"
                  data-bs-toggle="dropdown"
                  aria-expanded="false"
                >
                  <i class="las la-angle-right"></i>
                </button>
                <div class="dropdown-menu">
                  <a class="dropdown-item" href="#">Action</a>
                  <a class="dropdown-item" href="#">Another action</a>
                  <a class="dropdown-item" href="#">Something else here</a>
                  <div class="dropdown-divider"></div>
                  <a class="dropdown-item" href="#">Separated link</a>
                </div>
              </div>
            </DropDown>
          </div>
        </UIComponentCard>
      </b-col>
    </b-row>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Menu Alignment">
          <b-dropdown variant="primary" menuClass="dropdown-menu-end">
            <template #button-content>
              Right-aligned menu example <i class="las la-angle-down ms-1"></i>
            </template>
            <b-dropdown-item>Action</b-dropdown-item>
            <b-dropdown-item>Another action</b-dropdown-item>
            <b-dropdown-item>Something else here</b-dropdown-item>
          </b-dropdown>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Dropstart Variation">
          <div class="d-flex flex-wrap gap-2 dropstart">
            <b-dropdown dropstart variant="primary">
              <template #button-content>
                <i class="las la-angle-left me-1"></i> Primary
              </template>
              <b-dropdown-item>Action</b-dropdown-item>
              <b-dropdown-item>Another action</b-dropdown-item>
              <b-dropdown-item>Something else here</b-dropdown-item>
              <b-dropdown-divider />
              <b-dropdown-item>Separated link</b-dropdown-item>
            </b-dropdown>
            <DropDown>
              <div class="btn-group dropstart">
                <button
                  type="button"
                  class="btn btn-secondary dropdown-toggle dropdown-toggle-split"
                  data-bs-toggle="dropdown"
                  aria-expanded="false"
                >
                  <i class="las la-angle-left"></i>
                </button>
                <div class="dropdown-menu">
                  <a class="dropdown-item" href="#">Action</a>
                  <a class="dropdown-item" href="#">Another action</a>
                  <a class="dropdown-item" href="#">Something else here</a>
                  <div class="dropdown-divider"></div>
                  <a class="dropdown-item" href="#">Separated link</a>
                </div>
                <button type="button" class="btn btn-secondary ms-0">
                  Split dropstart
                </button>
              </div>
            </DropDown>
          </div>
        </UIComponentCard>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import DropDown from "@/components/DropDown.vue";
import DefaultLayout from "@/layouts/DefaultLayout.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
