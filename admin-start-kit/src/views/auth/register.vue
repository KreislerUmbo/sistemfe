<template>
  <AuthLayout>
    <b-col lg="4" class="mx-auto">
      <b-card no-body>
        <b-card-body class="p-0 bg-black auth-header-box rounded-top">
          <div class="text-center p-3">
            <router-link to="/" class="logo logo-admin">
              <img :src="logoSm" height="50" alt="logo" class="auth-logo" />
            </router-link>
            <h4 class="mt-3 mb-1 fw-semibold text-white fs-18">
              Create an account
            </h4>
            <p class="text-muted fw-medium mb-0">
              Enter your detail to Create your account today.
            </p>
          </div>
        </b-card-body>
        <b-card-body class="pt-0">
          <b-form class="my-4" action="/">
            <b-form-group class="mb-2" label="Username" label-for="username">
              <b-form-input
                type="text"
                name="username"
                placeholder="Enter username"
                id="username"
              />
            </b-form-group>

            <b-form-group class="mb-2" label="Email" label-for="useremail">
              <b-form-input
                type="email"
                name="useremail"
                placeholder="Enter email"
                id="useremail"
              />
            </b-form-group>

            <b-form-group
              class="mb-2"
              label="Password"
              label-for="userpassword"
            >
              <b-form-input
                type="password"
                name="password"
                placeholder="Enter password"
                id="userpassword"
              />
            </b-form-group>

            <b-form-group
              class="mb-2"
              label="Confirm Password"
              label-for="Confirmpassword"
            >
              <b-form-input
                type="password"
                name="password"
                placeholder="Enter Confirm password"
                id="Confirmpassword"
              />
            </b-form-group>

            <b-form-group
              class="mb-2"
              label="Mobile Number"
              label-for="mobileNo"
            >
              <b-form-input
                type="text"
                name="mobileNo"
                placeholder="Enter Mobile Number"
                id="mobileNo"
              />
            </b-form-group>

            <b-form-group class="row mt-3">
              <div class="form-switch-success">
                <b-form-checkbox switch>
                  By registering you agree to the Rizz
                  <a href="#" class="text-primary">Terms of Use</a>
                </b-form-checkbox>
              </div>
            </b-form-group>

            <b-form-group class="mb-0 row">
              <b-col cols="12">
                <div class="d-grid mt-3">
                  <b-button variant="primary" type="button"
                    >Register <i class="fas fa-sign-in-alt ms-1"></i
                  ></b-button>
                </div>
              </b-col>
            </b-form-group>
          </b-form>
          <div class="text-center">
            <p class="text-muted">
              Already have an account ?
              <router-link to="/auth/login" class="text-primary ms-2"
                >Log in
              </router-link>
            </p>
          </div>
        </b-card-body>
      </b-card>
    </b-col>
  </AuthLayout>
</template>
<script setup lang="ts">
import logoSm from "@/assets/images/logo-sm.png";
import AuthLayout from "@/layouts/AuthLayout.vue";
</script>
