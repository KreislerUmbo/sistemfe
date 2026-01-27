<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="12">
        <UIComponentCard title="Basic Navbar">
          <b-navbar variant="light" toggleable="lg" class="navbar-light">
            <b-navbar-brand href="#">Navbar</b-navbar-brand>
            <b-navbar-toggle target="navbarSupportedContent">
              <span class="navbar-toggler-icon"></span>
            </b-navbar-toggle>
            <b-collapse id="navbarSupportedContent" is-nav>
              <b-navbar-nav class="me-auto mb-2 mb-lg-0">
                <b-nav-item href="#" active>Home</b-nav-item>
                <b-nav-item href="#"> Link </b-nav-item>
                <b-nav-item-dropdown>
                  <template #button-content>
                    Dropdown <i class="la la-angle-down"></i>
                  </template>
                  <b-dropdown-item href="#">Action</b-dropdown-item>
                  <b-dropdown-item href="#">Another action</b-dropdown-item>
                  <b-dropdown-divider />
                  <b-dropdown-item href="#"
                    >Something else here</b-dropdown-item
                  >
                </b-nav-item-dropdown>
                <b-nav-item href="#" disabled> Disabled </b-nav-item>
              </b-navbar-nav>
              <form class="d-flex">
                <div class="input-group">
                  <input
                    type="text"
                    class="form-control"
                    placeholder="Search"
                    aria-label="Recipient's username"
                    aria-describedby="basic-addon2"
                  />
                  <b-button
                    :variant="null"
                    class="btn-soft-secondary"
                    type="button"
                    id="button-addon2"
                  >
                    <i class="fas fa-search"></i>
                  </b-button>
                </div>
              </form>
            </b-collapse>
          </b-navbar>
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="12">
        <UIComponentCard title="Navbar With Brand Image">
          <b-navbar variant="light" toggleable="lg" class="navbar-light">
            <b-navbar-brand href="#">
              <img :src="logoSm" height="26" alt="" class="me-1" />
            </b-navbar-brand>
            <b-navbar-toggle target="navbarSupportedContent2">
              <span class="navbar-toggler-icon"></span>
            </b-navbar-toggle>
            <b-collapse id="navbarSupportedContent2" is-nav>
              <b-navbar-nav class="me-auto mb-2 mb-lg-0">
                <b-nav-item href="#" active>Home</b-nav-item>
                <b-nav-item href="#"> Link </b-nav-item>
                <b-nav-item-dropdown>
                  <template #button-content>
                    Dropdown <i class="la la-angle-down"></i>
                  </template>
                  <b-dropdown-item href="#">Action</b-dropdown-item>
                  <b-dropdown-item href="#">Another action</b-dropdown-item>
                  <b-dropdown-divider />
                  <b-dropdown-item href="#"
                    >Something else here</b-dropdown-item
                  >
                </b-nav-item-dropdown>
                <b-nav-item href="#" disabled> Disabled </b-nav-item>
              </b-navbar-nav>
              <form class="d-flex">
                <div class="input-group">
                  <input
                    type="text"
                    class="form-control"
                    placeholder="Search"
                    aria-label="Recipient's username"
                    aria-describedby="basic-addon2"
                  />
                  <b-button
                    :variant="null"
                    class="btn-soft-secondary"
                    type="button"
                    id="button-addon2"
                  >
                    <i class="fas fa-search"></i>
                  </b-button>
                </div>
              </form>
            </b-collapse>
          </b-navbar>

          <b-navbar variant="light" toggleable="lg" class="navbar-light mt-2">
            <b-navbar-brand href="#">
              <img :src="logoSm" height="26" alt="" class="me-2" />{{ " " }}
              <img :src="logoDark" height="16" alt="" />
            </b-navbar-brand>
            <b-navbar-toggle target="navbarSupportedContent3">
              <span class="navbar-toggler-icon"></span>
            </b-navbar-toggle>
            <b-collapse id="navbarSupportedContent3" is-nav>
              <b-navbar-nav class="me-auto mb-2 mb-lg-0">
                <b-nav-item href="#" active>Home</b-nav-item>
                <b-nav-item href="#"> Link </b-nav-item>
                <b-nav-item-dropdown>
                  <template #button-content>
                    Dropdown <i class="la la-angle-down"></i>
                  </template>
                  <b-dropdown-item href="#">Action</b-dropdown-item>
                  <b-dropdown-item href="#">Another action</b-dropdown-item>
                  <b-dropdown-divider />
                  <b-dropdown-item href="#"
                    >Something else here</b-dropdown-item
                  >
                </b-nav-item-dropdown>
                <b-nav-item href="#" disabled> Disabled </b-nav-item>
              </b-navbar-nav>
              <form class="d-flex">
                <div class="input-group">
                  <input
                    type="text"
                    class="form-control"
                    placeholder="Search"
                    aria-label="Recipient's username"
                    aria-describedby="basic-addon2"
                  />
                  <b-button
                    :variant="null"
                    class="btn-soft-secondary"
                    type="button"
                    id="button-addon2"
                  >
                    <i class="fas fa-search"></i>
                  </b-button>
                </div>
              </form>
            </b-collapse>
          </b-navbar>
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="12">
        <UIComponentCard title="Theme Colors">
          <b-navbar variant="primary" toggleable="lg" class="navbar-dark mb-2">
            <b-navbar-brand href="#"> Navbar </b-navbar-brand>
            <b-navbar-toggle target="navbarSupportedContent4">
              <span class="navbar-toggler-icon"></span>
            </b-navbar-toggle>
            <b-collapse id="navbarSupportedContent4" is-nav>
              <b-navbar-nav class="me-auto mb-2 mb-lg-0">
                <b-nav-item href="#" active>Home</b-nav-item>
                <b-nav-item href="#"> Link </b-nav-item>
                <b-nav-item-dropdown>
                  <template #button-content>
                    Dropdown <i class="la la-angle-down"></i>
                  </template>
                  <b-dropdown-item href="#">Action</b-dropdown-item>
                  <b-dropdown-item href="#">Another action</b-dropdown-item>
                  <b-dropdown-divider />
                  <b-dropdown-item href="#"
                    >Something else here</b-dropdown-item
                  >
                </b-nav-item-dropdown>
                <b-nav-item href="#" disabled> Disabled </b-nav-item>
              </b-navbar-nav>
              <form class="d-flex">
                <div class="input-group">
                  <input
                    type="text"
                    class="form-control bg-white bg-opacity-25 border-white border-opacity-25"
                    placeholder="Search"
                    aria-label="Recipient's username"
                    aria-describedby="basic-addon2"
                  />
                  <b-button variant="dark" type="button" id="button-addon2">
                    <i class="fas fa-search"></i>
                  </b-button>
                </div>
              </form>
            </b-collapse>
          </b-navbar>

          <b-navbar
            variant="secondary"
            toggleable="lg"
            class="navbar-dark mb-2"
          >
            <b-navbar-brand href="#"> Navbar </b-navbar-brand>
            <b-navbar-toggle target="navbarSupportedContent4">
              <span class="navbar-toggler-icon"></span>
            </b-navbar-toggle>
            <b-collapse id="navbarSupportedContent4" is-nav>
              <b-navbar-nav class="me-auto mb-2 mb-lg-0">
                <b-nav-item href="#" active>Home</b-nav-item>
                <b-nav-item href="#"> Link </b-nav-item>
                <b-nav-item-dropdown>
                  <template #button-content>
                    Dropdown <i class="la la-angle-down"></i>
                  </template>
                  <b-dropdown-item href="#">Action</b-dropdown-item>
                  <b-dropdown-item href="#">Another action</b-dropdown-item>
                  <b-dropdown-divider />
                  <b-dropdown-item href="#"
                    >Something else here</b-dropdown-item
                  >
                </b-nav-item-dropdown>
                <b-nav-item href="#" disabled> Disabled </b-nav-item>
              </b-navbar-nav>
              <form class="d-flex">
                <div class="input-group">
                  <input
                    type="text"
                    class="form-control bg-white bg-opacity-25 border-white border-opacity-25"
                    placeholder="Search"
                    aria-label="Recipient's username"
                    aria-describedby="basic-addon2"
                  />
                  <b-button variant="dark" type="button" id="button-addon2">
                    <i class="fas fa-search"></i>
                  </b-button>
                </div>
              </form>
            </b-collapse>
          </b-navbar>

          <b-navbar variant="dark" toggleable="lg" class="navbar-dark">
            <b-navbar-brand href="#"> Navbar </b-navbar-brand>
            <b-navbar-toggle target="navbarSupportedContent">
              <span class="navbar-toggler-icon"></span>
            </b-navbar-toggle>
            <b-collapse id="navbarSupportedContent" is-nav>
              <b-navbar-nav class="me-auto mb-2 mb-lg-0">
                <b-nav-item href="#" active>Home</b-nav-item>
                <b-nav-item href="#"> Link </b-nav-item>
                <b-nav-item-dropdown>
                  <template #button-content>
                    Dropdown <i class="la la-angle-down"></i>
                  </template>
                  <b-dropdown-item href="#">Action</b-dropdown-item>
                  <b-dropdown-item href="#">Another action</b-dropdown-item>
                  <b-dropdown-divider />
                  <b-dropdown-item href="#"
                    >Something else here</b-dropdown-item
                  >
                </b-nav-item-dropdown>
                <b-nav-item href="#" disabled> Disabled </b-nav-item>
              </b-navbar-nav>
              <form class="d-flex">
                <div class="input-group">
                  <input
                    type="text"
                    class="form-control bg-white bg-opacity-25 border-white border-opacity-25"
                    placeholder="Search"
                    aria-label="Recipient's username"
                    aria-describedby="basic-addon2"
                  />
                  <b-button
                    variant="secondary"
                    type="button"
                    id="button-addon2"
                  >
                    <i class="fas fa-search"></i>
                  </b-button>
                </div>
              </form>
            </b-collapse>
          </b-navbar>
        </UIComponentCard>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import logoSm from "@/assets/images/logo-sm.png";
import logoDark from "@/assets/images/logo-dark.png";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
