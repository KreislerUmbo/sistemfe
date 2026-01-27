<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="12">
        <UIComponentCard title="Optional Sizes">
          <b-table-simple responsive bordered striped class="mb-0">
            <b-thead>
              <b-tr>
                <b-th>Size</b-th>
                <b-th>Class</b-th>
                <b-th>Modal max-width</b-th>
                <b-th>Modal Demo</b-th>
              </b-tr>
            </b-thead>
            <b-tbody>
              <b-tr>
                <b-td>Small</b-td>
                <b-td><code>.modal-sm</code></b-td>
                <b-td><code>300px</code></b-td>
                <b-td>
                  <b-button
                    type="button"
                    size="sm"
                    variant="primary"
                    @click="smModal = !smModal"
                  >
                    Launch demo modal
                  </b-button>
                </b-td>
              </b-tr>
              <b-tr>
                <b-td>Default</b-td>
                <b-td class="text-muted">None</b-td>
                <b-td><code>500px</code></b-td>
                <b-td>
                  <b-button
                    type="button"
                    size="sm"
                    variant="primary"
                    @click="defaultModal = !defaultModal"
                  >
                    Launch demo modal
                  </b-button>
                </b-td>
              </b-tr>
              <b-tr>
                <b-td>Large</b-td>
                <b-td><code>.modal-lg</code></b-td>
                <b-td><code>800px</code></b-td>
                <b-td>
                  <b-button
                    type="button"
                    size="sm"
                    variant="primary"
                    @click="largeModal = !largeModal"
                  >
                    Launch demo modal
                  </b-button>
                </b-td>
              </b-tr>
              <b-tr>
                <b-td>Extra large</b-td>
                <b-td><code>.modal-xl</code></b-td>
                <b-td><code>1140px</code></b-td>
                <b-td>
                  <b-button
                    type="button"
                    size="sm"
                    variant="primary"
                    @click="extralargeModal = !extralargeModal"
                  >
                    Launch demo modal
                  </b-button>
                </b-td>
              </b-tr>
              <b-tr>
                <b-td>Default (Center Modal)</b-td>
                <b-td class="text-muted"
                  ><code>.modal-dialog-centered</code></b-td
                >
                <b-td><code>500px</code></b-td>
                <b-td>
                  <b-button
                    type="button"
                    size="sm"
                    variant="primary"
                    @click="centerModal = !centerModal"
                  >
                    Launch demo modal
                  </b-button>
                </b-td>
              </b-tr>
              <b-tr>
                <b-td>Default (Scroll Modal)</b-td>
                <b-td class="text-muted"
                  ><code>.modal-dialog-scrollable</code></b-td
                >
                <b-td><code>500px</code></b-td>
                <b-td>
                  <b-button
                    type="button"
                    size="sm"
                    variant="primary"
                    @click="scrollableModal = !scrollableModal"
                  >
                    Launch demo modal
                  </b-button>
                </b-td>
              </b-tr>
            </b-tbody>
          </b-table-simple>
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="12">
        <UIComponentCard title="Fullscreen Modal">
          <b-table-simple responsive bordered striped class="mb-0">
            <b-thead>
              <b-tr>
                <b-th>Class</b-th>
                <b-th>Availability</b-th>
                <b-th>Modal Demo</b-th>
              </b-tr>
            </b-thead>
            <b-tbody>
              <b-tr>
                <b-td><code>.modal-fullscreen</code></b-td>
                <b-td>Always</b-td>
                <b-td>
                  <b-button
                    type="button"
                    size="sm"
                    variant="primary"
                    @click="fsModal = !fsModal"
                    >Full screen</b-button
                  >
                </b-td>
              </b-tr>
              <b-tr>
                <b-td><code>.modal-fullscreen-sm-down</code></b-td>
                <b-td>Below <code>576px</code></b-td>
                <b-td>
                  <b-button
                    type="button"
                    size="sm"
                    variant="primary"
                    @click="() => toggleFSModal('sm')"
                    >Full screen below sm</b-button
                  >
                </b-td>
              </b-tr>
              <b-tr>
                <b-td><code>.modal-fullscreen-md-down</code></b-td>
                <b-td>Below <code>768px</code></b-td>
                <b-td>
                  <b-button
                    type="button"
                    size="sm"
                    variant="primary"
                    @click="() => toggleFSModal('md')"
                    >Full screen below md</b-button
                  >
                </b-td>
              </b-tr>
              <b-tr>
                <b-td><code>.modal-fullscreen-lg-down</code></b-td>
                <b-td>Below <code>992px</code></b-td>
                <b-td>
                  <b-button
                    type="button"
                    size="sm"
                    variant="primary"
                    @click="() => toggleFSModal('lg')"
                    >Full screen below lg</b-button
                  >
                </b-td>
              </b-tr>
              <b-tr>
                <b-td><code>.modal-fullscreen-xl-down</code></b-td>
                <b-td>Below <code>1200px</code></b-td>
                <b-td>
                  <b-button
                    type="button"
                    size="sm"
                    variant="primary"
                    @click="() => toggleFSModal('xl')"
                    >Full screen below xl</b-button
                  >
                </b-td>
              </b-tr>
              <b-tr>
                <b-td><code>.modal-fullscreen-xxl-down</code></b-td>
                <b-td>Below <code>1400px</code></b-td>
                <b-td>
                  <b-button
                    type="button"
                    size="sm"
                    variant="primary"
                    @click="() => toggleFSModal('xxl')"
                    >Full screen below xxl</b-button
                  >
                </b-td>
              </b-tr>
            </b-tbody>
          </b-table-simple>
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="12">
        <UIComponentCard title="Theme Color Modal">
          <div class="button-items d-flex flex-wrap gap-2">
            <b-button
              type="button"
              variant="primary"
              @click="toggleModal('primary')"
              >Primary</b-button
            >

            <b-button
              type="button"
              variant="secondary"
              @click="toggleModal('secondary')"
              >Secondary</b-button
            >

            <b-button
              type="button"
              variant="success"
              @click="toggleModal('success')"
              >Success</b-button
            >

            <b-button
              type="button"
              variant="warning"
              @click="toggleModal('warning')"
              >Warning</b-button
            >

            <b-button type="button" variant="info" @click="toggleModal('info')"
              >Info</b-button
            >

            <b-button
              type="button"
              variant="danger"
              @click="toggleModal('danger')"
              >Danger</b-button
            >

            <b-button type="button" variant="dark" @click="toggleModal('dark')"
              >Dark</b-button
            >
          </div>
        </UIComponentCard>
      </b-col>
    </b-row>

    <!-- Small Modal -->
    <b-modal
      v-model="smModal"
      title="Small Modal"
      size="sm"
      ok-title="Save changes"
      cancel-title="Close"
      hideFooter
      bodyClass="p-0 m-0"
    >
      <div class="text-center py-3">
        <img
          :src="avatar5"
          alt=""
          class="thumb-lg rounded-circle d-block mx-auto mb-1"
        />
        <h5 class="mb-1">Good Morning!</h5>
        <p class="mb-0 text-muted">Hi, Aaron Gish ! Congratulations.</p>
      </div>
      <div class="modal-footer">
        <b-button
          type="button"
          variant="secondary"
          size="sm"
          data-bs-dismiss="modal"
          >Close</b-button
        >
        <b-button type="button" variant="primary" size="sm"
          >Save changes</b-button
        >
      </div>
    </b-modal>

    <!-- Default -->
    <b-modal
      v-model="defaultModal"
      title="Default Modal"
      ok-title="Save changes"
      cancel-title="Close"
    >
      <b-row>
        <b-col lg="3" class="text-center align-self-center">
          <img :src="btc" alt="" class="img-fluid" />
        </b-col>
        <b-col lg="9">
          <h5>Crypto Market Services</h5>
          <b-badge variant="light" class="text-dark">
            Disable Services
          </b-badge>
          <small class="text-muted ms-2">07 Oct 2024</small>
          <ul class="mt-2 mb-0">
            <li>Lorem Ipsum is dummy text.</li>
            <li>It is a long established reader.</li>
            <li>Contrary to popular belief, Lorem simply.</li>
          </ul>
        </b-col>
      </b-row>
    </b-modal>

    <!-- Large -->
    <b-modal v-model="largeModal" title="Large Modal" size="lg" hide-footer>
      <b-row>
        <b-col lg="4" class="text-center">
          <img :src="find" alt="" class="img-fluid" />
        </b-col>
        <b-col lg="8" class="align-self-center">
          <div class="error-content text-center">
            <h1 class="fw-bold display-5">404!</h1>
            <h4 class="mb-3">Looks like you've got lost...</h4>
            <b-button type="button" variant="primary"
              >Back to Dashboard</b-button
            >
          </div>
        </b-col>
      </b-row>
    </b-modal>

    <!-- Extra large -->
    <b-modal
      v-model="extralargeModal"
      title="Extra Large Modal"
      size="xl"
      ok-title="Save changes"
      cancel-title="Close"
    >
      <h5 class="mt-0">Overflowing text to show scroll behavior</h5>
      <p>
        Cras mattis consectetur purus sit amet fermentum. Cras justo odio,
        dapibus ac facilisis in, egestas eget quam. Morbi leo risus, porta ac
        consectetur ac, vestibulum at eros.
      </p>
      <p>
        Praesent commodo cursus magna, vel scelerisque nisl consectetur et.
        Vivamus sagittis lacus vel augue laoreet rutrum faucibus dolor auctor.
      </p>
      <p>
        Aenean lacinia bibendum nulla sed consectetur. Praesent commodo cursus
        magna, vel scelerisque nisl consectetur et. Donec sed odio dui. Donec
        ullamcorper nulla non metus auctor fringilla.
      </p>
      <p>
        Cras mattis consectetur purus sit amet fermentum. Cras justo odio,
        dapibus ac facilisis in, egestas eget quam. Morbi leo risus, porta ac
        consectetur ac, vestibulum at eros.
      </p>
      <p>
        Praesent commodo cursus magna, vel scelerisque nisl consectetur et.
        Vivamus sagittis lacus vel augue laoreet rutrum faucibus dolor auctor.
      </p>
      <p>
        Aenean lacinia bibendum nulla sed consectetur. Praesent commodo cursus
        magna, vel scelerisque nisl consectetur et. Donec sed odio dui. Donec
        ullamcorper nulla non metus auctor fringilla.
      </p>
      <p>
        Cras mattis consectetur purus sit amet fermentum. Cras justo odio,
        dapibus ac facilisis in, egestas eget quam. Morbi leo risus, porta ac
        consectetur ac, vestibulum at eros.
      </p>
      <p>
        Praesent commodo cursus magna, vel scelerisque nisl consectetur et.
        Vivamus sagittis lacus vel augue laoreet rutrum faucibus dolor auctor.
      </p>
      <p>
        Aenean lacinia bibendum nulla sed consectetur. Praesent commodo cursus
        magna, vel scelerisque nisl consectetur et. Donec sed odio dui. Donec
        ullamcorper nulla non metus auctor fringilla.
      </p>
    </b-modal>

    <!-- Default (Center Modal) -->
    <b-modal
      v-model="centerModal"
      title="Center Modal"
      ok-title="Save changes"
      cancel-title="Close"
      centered
    >
      <b-row>
        <b-col lg="3" class="text-center align-self-center">
          <img :src="btc" alt="" class="img-fluid" />
        </b-col>
        <b-col lg="9">
          <h5>Crypto Market Services</h5>
          <b-badge variant="light" class="text-dark">
            Disable Services
          </b-badge>
          <small class="text-muted ms-2">07 Oct 2024</small>
          <ul class="mt-2 mb-0">
            <li>Lorem Ipsum is dummy text.</li>
            <li>It is a long established reader.</li>
            <li>Contrary to popular belief, Lorem simply.</li>
          </ul>
        </b-col>
      </b-row>
    </b-modal>

    <!-- Default (Scroll Modal) -->
    <b-modal
      v-model="scrollableModal"
      title="Center Modal"
      ok-title="Save changes"
      cancel-title="Close"
      :scrollable="true"
    >
      <p>
        Cras mattis consectetur purus sit amet fermentum. Cras justo odio,
        dapibus ac facilisis in, egestas eget quam. Morbi leo risus, porta ac
        consectetur ac, vestibulum at eros.
      </p>
      <p>
        Cras mattis consectetur purus sit amet fermentum. Cras justo odio,
        dapibus ac facilisis in, egestas eget quam. Morbi leo risus, porta ac
        consectetur ac, vestibulum at eros.
      </p>
      <p>
        Cras mattis consectetur purus sit amet fermentum. Cras justo odio,
        dapibus ac facilisis in, egestas eget quam. Morbi leo risus, porta ac
        consectetur ac, vestibulum at eros.
      </p>
      <p>
        Cras mattis consectetur purus sit amet fermentum. Cras justo odio,
        dapibus ac facilisis in, egestas eget quam. Morbi leo risus, porta ac
        consectetur ac, vestibulum at eros.
      </p>
      <p>
        Cras mattis consectetur purus sit amet fermentum. Cras justo odio,
        dapibus ac facilisis in, egestas eget quam. Morbi leo risus, porta ac
        consectetur ac, vestibulum at eros.
      </p>
      <p>
        Cras mattis consectetur purus sit amet fermentum. Cras justo odio,
        dapibus ac facilisis in, egestas eget quam. Morbi leo risus, porta ac
        consectetur ac, vestibulum at eros.
      </p>
      <p>
        Cras mattis consectetur purus sit amet fermentum. Cras justo odio,
        dapibus ac facilisis in, egestas eget quam. Morbi leo risus, porta ac
        consectetur ac, vestibulum at eros.
      </p>
      <p>
        Cras mattis consectetur purus sit amet fermentum. Cras justo odio,
        dapibus ac facilisis in, egestas eget quam. Morbi leo risus, porta ac
        consectetur ac, vestibulum at eros.
      </p>
      <p>
        Cras mattis consectetur purus sit amet fermentum. Cras justo odio,
        dapibus ac facilisis in, egestas eget quam. Morbi leo risus, porta ac
        consectetur ac, vestibulum at eros.
      </p>
      <p>
        Cras mattis consectetur purus sit amet fermentum. Cras justo odio,
        dapibus ac facilisis in, egestas eget quam. Morbi leo risus, porta ac
        consectetur ac, vestibulum at eros.
      </p>
      <p>
        Cras mattis consectetur purus sit amet fermentum. Cras justo odio,
        dapibus ac facilisis in, egestas eget quam. Morbi leo risus, porta ac
        consectetur ac, vestibulum at eros.
      </p>
      <p>
        Cras mattis consectetur purus sit amet fermentum. Cras justo odio,
        dapibus ac facilisis in, egestas eget quam. Morbi leo risus, porta ac
        consectetur ac, vestibulum at eros.
      </p>
      <p>
        Cras mattis consectetur purus sit amet fermentum. Cras justo odio,
        dapibus ac facilisis in, egestas eget quam. Morbi leo risus, porta ac
        consectetur ac, vestibulum at eros.
      </p>
      <p>
        Cras mattis consectetur purus sit amet fermentum. Cras justo odio,
        dapibus ac facilisis in, egestas eget quam. Morbi leo risus, porta ac
        consectetur ac, vestibulum at eros.
      </p>
      <p>
        Cras mattis consectetur purus sit amet fermentum. Cras justo odio,
        dapibus ac facilisis in, egestas eget quam. Morbi leo risus, porta ac
        consectetur ac, vestibulum at eros.
      </p>
      <p>
        Cras mattis consectetur purus sit amet fermentum. Cras justo odio,
        dapibus ac facilisis in, egestas eget quam. Morbi leo risus, porta ac
        consectetur ac, vestibulum at eros.
      </p>
    </b-modal>

    <!-- Fullscreen Modal -->
    <b-modal
      v-model="fsModal"
      title="Full screen modal"
      ok-only
      ok-title="Close"
      ok-variant="secondary"
      fullscreen
    >
      <p>
        Cras mattis consectetur purus sit amet fermentum. Cras justo odio,
        dapibus ac facilisis in, egestas eget quam. Morbi leo risus, porta ac
        consectetur ac, vestibulum at eros.
      </p>
      <p>
        Praesent commodo cursus magna, vel scelerisque nisl consectetur et.
        Vivamus sagittis lacus vel augue laoreet rutrum faucibus dolor auctor.
      </p>
      <p>
        Aenean lacinia bibendum nulla sed consectetur. Praesent commodo cursus
        magna, vel scelerisque nisl consectetur et. Donec sed odio dui. Donec
        ullamcorper nulla non metus auctor fringilla.
      </p>
      <p>
        Cras mattis consectetur purus sit amet fermentum. Cras justo odio,
        dapibus ac facilisis in, egestas eget quam. Morbi leo risus, porta ac
        consectetur ac, vestibulum at eros.
      </p>
      <p>
        Praesent commodo cursus magna, vel scelerisque nisl consectetur et.
        Vivamus sagittis lacus vel augue laoreet rutrum faucibus dolor auctor.
      </p>
      <p>
        Aenean lacinia bibendum nulla sed consectetur. Praesent commodo cursus
        magna, vel scelerisque nisl consectetur et. Donec sed odio dui. Donec
        ullamcorper nulla non metus auctor fringilla.
      </p>
      <p>
        Cras mattis consectetur purus sit amet fermentum. Cras justo odio,
        dapibus ac facilisis in, egestas eget quam. Morbi leo risus, porta ac
        consectetur ac, vestibulum at eros.
      </p>
      <p>
        Praesent commodo cursus magna, vel scelerisque nisl consectetur et.
        Vivamus sagittis lacus vel augue laoreet rutrum faucibus dolor auctor.
      </p>
      <p>
        Aenean lacinia bibendum nulla sed consectetur. Praesent commodo cursus
        magna, vel scelerisque nisl consectetur et. Donec sed odio dui. Donec
        ullamcorper nulla non metus auctor fringilla.
      </p>
      <p>
        Cras mattis consectetur purus sit amet fermentum. Cras justo odio,
        dapibus ac facilisis in, egestas eget quam. Morbi leo risus, porta ac
        consectetur ac, vestibulum at eros.
      </p>
      <p>
        Praesent commodo cursus magna, vel scelerisque nisl consectetur et.
        Vivamus sagittis lacus vel augue laoreet rutrum faucibus dolor auctor.
      </p>
      <p>
        Aenean lacinia bibendum nulla sed consectetur. Praesent commodo cursus
        magna, vel scelerisque nisl consectetur et. Donec sed odio dui. Donec
        ullamcorper nulla non metus auctor fringilla.
      </p>
      <p>
        Cras mattis consectetur purus sit amet fermentum. Cras justo odio,
        dapibus ac facilisis in, egestas eget quam. Morbi leo risus, porta ac
        consectetur ac, vestibulum at eros.
      </p>
      <p>
        Praesent commodo cursus magna, vel scelerisque nisl consectetur et.
        Vivamus sagittis lacus vel augue laoreet rutrum faucibus dolor auctor.
      </p>
      <p>
        Aenean lacinia bibendum nulla sed consectetur. Praesent commodo cursus
        magna, vel scelerisque nisl consectetur et. Donec sed odio dui. Donec
        ullamcorper nulla non metus auctor fringilla.
      </p>
      <p>
        Cras mattis consectetur purus sit amet fermentum. Cras justo odio,
        dapibus ac facilisis in, egestas eget quam. Morbi leo risus, porta ac
        consectetur ac, vestibulum at eros.
      </p>
      <p>
        Praesent commodo cursus magna, vel scelerisque nisl consectetur et.
        Vivamus sagittis lacus vel augue laoreet rutrum faucibus dolor auctor.
      </p>
      <p>
        Aenean lacinia bibendum nulla sed consectetur. Praesent commodo cursus
        magna, vel scelerisque nisl consectetur et. Donec sed odio dui. Donec
        ullamcorper nulla non metus auctor fringilla.
      </p>
    </b-modal>

    <b-modal
      v-model="fsBSMModal"
      :title="`Full screen below ${fsBSMModalVariant}`"
      ok-only
      ok-title="Close"
      ok-variant="secondary"
      :fullscreen="fsBSMModalVariant"
    >
      <p class="mb-0">
        Cras mattis consectetur purus sit amet fermentum. Cras justo odio,
        dapibus ac facilisis in, egestas eget quam. Morbi leo risus, porta ac
        consectetur ac, vestibulum at eros.
      </p>
    </b-modal>

    <!-- Theme Color Modal -->
    <b-modal
      v-model="themeColorModal"
      :title="`${themeColor} Modal`"
      :header-class="`bg-${themeColor}`"
      title-class="m-0 text-white"
      ok-title="Save changes"
      :ok-variant="themeColor"
      cancel-title="Close"
    >
      <b-row>
        <b-col lg="3" class="text-center align-self-center">
          <img :src="btc" alt="" class="img-fluid" />
        </b-col>
        <b-col lg="9">
          <h5>Crypto Market Services</h5>
          <b-badge variant="light" class="text-dark">
            Disable Services
          </b-badge>
          <small class="text-muted ms-2">07 Oct 2024</small>
          <ul class="mt-2 mb-0">
            <li>Lorem Ipsum is dummy text.</li>
            <li>It is a long established reader.</li>
            <li>Contrary to popular belief, Lorem simply.</li>
          </ul>
        </b-col>
      </b-row>
    </b-modal>
  </DefaultLayout>
</template>
<script setup lang="ts">
import { ref } from "vue";

const show = ref(false);
const smModal = ref(false);
const defaultModal = ref(false);
const largeModal = ref(false);
const extralargeModal = ref(false);
const centerModal = ref(false);
const scrollableModal = ref(false);

const fsModal = ref(false);
const fsBSMModal = ref(false);
const fsBSMModalVariant = ref<any>("sm");

const themeColorModal = ref(false);
const themeColor = ref<any>("primary");

const toggleModal = (e: string) => {
  themeColorModal.value = !themeColorModal.value;
  themeColor.value = e;
};

const toggleFSModal = (e: string) => {
  fsBSMModal.value = !fsBSMModal.value;
  fsBSMModalVariant.value = e;
};

import btc from "@/assets/images/extra/card/btc.png";
import find from "@/assets/images/extra/card/find.png";
import avatar5 from "@/assets/images/users/avatar-5.jpg";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
