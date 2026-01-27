<template>
  <b-card-title class="my-4 fs-15">Company Document</b-card-title>
  <b-row>
    <b-col md="6">
      <div class="form-group row mb-2">
        <label
          for="txtFirstNameShipping"
          class="col-lg-3 col-form-label text-lg-end"
          >PAN Card</label
        >
        <div class="col-lg-9">
          <b-form-input
            type="text"
            id="txtFirstNameShipping"
            name="txtFirstNameShipping"
          />
        </div>
      </div>
    </b-col>
    <b-col md="6">
      <div class="form-group row mb-2">
        <label
          for="txtLastNameShipping"
          class="col-lg-3 col-form-label text-lg-end"
          >VAT/TIN No.</label
        >
        <div class="col-lg-9">
          <b-form-input
            type="text"
            id="txtLastNameShipping"
            name="txtLastNameShipping"
          />
        </div>
      </div>
    </b-col>
  </b-row>
  <b-row>
    <b-col md="6">
      <div class="form-group row mb-2">
        <label
          for="txtCompanyShipping"
          class="col-lg-3 col-form-label text-lg-end"
          >CST No.</label
        >
        <div class="col-lg-9">
          <b-form-input
            type="text"
            id="txtCompanyShipping"
            name="txtCompanyShipping"
          />
        </div>
      </div>
    </b-col>
    <b-col md="6">
      <div class="form-group row mb-2">
        <label
          for="txtEmailAddressShipping"
          class="col-lg-3 col-form-label text-lg-end"
          >Service Tax No.</label
        >
        <div class="col-lg-9">
          <b-form-input
            type="text"
            id="txtEmailAddressShipping"
            name="txtEmailAddressShipping"
          />
        </div>
      </div>
    </b-col>
  </b-row>
  <b-row>
    <b-col md="6">
      <div class="form-group row mb-2">
        <label for="txtCityShipping" class="col-lg-3 col-form-label text-lg-end"
          >Company UIN</label
        >
        <div class="col-lg-9">
          <b-form-input
            type="text"
            id="txtCityShipping"
            name="txtCityShipping"
          />
        </div>
      </div>
    </b-col>
    <b-col md="6">
      <div class="form-group row mb-2">
        <label
          for="txtStateProvinceShipping"
          class="col-lg-3 col-form-label text-lg-end"
          >Declaration</label
        >
        <div class="col-lg-9">
          <b-form-input
            type="text"
            id="txtStateProvinceShipping"
            name="txtStateProvinceShipping"
          />
        </div>
      </div>
    </b-col>
  </b-row>
  <div>
    <b-button
      type="button"
      id="step2Prev"
      variant="secondary"
      class="float-start mt-2"
      @click="previousPage"
      >Previous</b-button
    >
    <b-button
      type="button"
      id="step2Next"
      variant="primary"
      class="float-end mt-2"
      @click="nextPage"
      >Next</b-button
    >
  </div>
</template>
<script setup lang="ts">
defineProps(["nextPage", "previousPage"]);
</script>
