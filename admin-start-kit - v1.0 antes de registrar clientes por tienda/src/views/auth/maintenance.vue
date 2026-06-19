<template>
  <AuthLayout>
    <b-col lg="6" class="mx-auto">
      <b-card no-body>
        <b-card-body class="p-5">
          <div class="text-center">
            <img :src="maintenance" alt="" class="img-fluid mb-3" />
            <h6 class="mb-2 fw-medium text-dark fs-24">
              We are down on maintenance right now
            </h6>
            <p class="text-muted mb-0 text-wrap fs-15">
              This website is currently undergoing Scheduled maintenance.
            </p>
            <div class="mt-3 text-center">
              <router-link class="btn btn-primary w-50" to="/"
                >Back to Dashboard <i class="fas fa-redo ms-1"></i>
              </router-link>
            </div>
          </div>
        </b-card-body>
      </b-card>
    </b-col>
  </AuthLayout>
</template>
<script setup lang="ts">
import maintenance from "@/assets/images/extra/card/maintenance.png";
import AuthLayout from "@/layouts/AuthLayout.vue";
</script>
