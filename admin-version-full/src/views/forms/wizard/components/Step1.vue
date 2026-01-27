<template>
  <b-card-title class="my-4 fs-15">Seller Details</b-card-title>
  <b-row>
    <b-col md="6">
      <div class="form-group row mb-2">
        <label
          for="txtFirstNameBilling"
          class="col-lg-3 col-form-label text-lg-end"
          >Contact Person</label
        >
        <b-col lg="9">
          <b-form-input
            type="text"
            id="txtFirstNameBilling"
            name="txtFirstNameBilling"
          />
        </b-col>
      </div>
    </b-col>
    <b-col md="6">
      <div class="form-group row mb-2">
        <label
          for="txtLastNameBilling"
          class="col-lg-3 col-form-label text-lg-end"
          >Mobile No.</label
        >
        <b-col lg="9">
          <b-form-input
            type="text"
            id="txtLastNameBilling"
            name="txtLastNameBilling"
          />
        </b-col>
      </div>
    </b-col>
  </b-row>
  <b-row>
    <b-col md="6">
      <div class="form-group row mb-2">
        <label
          for="txtCompanyBilling"
          class="col-lg-3 col-form-label text-lg-end"
          >Landline No.</label
        >
        <b-col lg="9">
          <b-form-input
            type="text"
            id="txtCompanyBilling"
            name="txtCompanyBilling"
          />
        </b-col>
      </div>
    </b-col>
    <b-col md="6">
      <div class="form-group row mb-2">
        <label
          for="txtEmailAddressBilling"
          class="col-lg-3 col-form-label text-lg-end"
          >Email Address</label
        >
        <b-col lg="9">
          <b-form-input
            type="text"
            id="txtEmailAddressBilling"
            name="txtEmailAddressBilling"
          />
        </b-col>
      </div>
    </b-col>
  </b-row>
  <b-row>
    <b-col md="6">
      <div class="form-group row mb-2">
        <label
          for="txtAddress1Billing"
          class="col-lg-3 col-form-label text-lg-end"
          >Address 1</label
        >
        <b-col lg="9">
          <b-form-textarea
            type="textarea"
            rows="4"
            id="txtAddress1Billing"
            name="txtAddress1Billing"
          />
        </b-col>
      </div>
    </b-col>
    <b-col md="6">
      <div class="form-group row mb-2">
        <label
          for="txtAddress2Billing"
          class="col-lg-3 col-form-label text-lg-end"
          >Warehouse Address</label
        >
        <b-col lg="9">
          <b-form-textarea
            type="textarea"
            rows="4"
            id="txtAddress2Billing"
            name="txtAddress2Billing"
          />
        </b-col>
      </div>
    </b-col>
  </b-row>
  <b-row>
    <b-col md="6">
      <div class="form-group row mb-2">
        <label for="txtCityBilling" class="col-lg-3 col-form-label text-lg-end"
          >Company Type</label
        >
        <b-col lg="9">
          <b-form-input type="text" id="txtCityBilling" name="txtCityBilling" />
        </b-col>
      </div>
    </b-col>
    <b-col md="6">
      <div class="form-group row mb-2">
        <label
          for="txtStateProvinceBilling"
          class="col-lg-3 col-form-label text-lg-end"
          >Live Market A/C</label
        >
        <b-col lg="9">
          <b-form-input
            type="text"
            id="txtStateProvinceBilling"
            name="txtStateProvinceBilling"
          />
        </b-col>
      </div>
    </b-col>
  </b-row>
  <b-row>
    <b-col md="6">
      <div class="form-group row mb-2">
        <label
          for="txtTelephoneBilling"
          class="col-lg-3 col-form-label text-lg-end"
          >Product Category</label
        >
        <b-col lg="9">
          <b-form-input
            type="text"
            id="txtTelephoneBilling"
            name="txtTelephoneBilling"
          />
        </b-col>
      </div>
    </b-col>
    <b-col md="6">
      <div class="form-group row mb-2">
        <label for="txtFaxBilling" class="col-lg-3 col-form-label text-lg-end"
          >Product Sub Category</label
        >
        <b-col lg="9">
          <b-form-input type="text" id="txtFaxBilling" name="txtFaxBilling" />
        </b-col>
      </div>
    </b-col>
  </b-row>
  <div class="mt-2">
    <b-button
      type="button"
      id="step1Next"
      variant="primary"
      class="float-end"
      @click="nextPage"
      >Next</b-button
    >
  </div>
</template>
<script setup lang="ts">
defineProps(["nextPage"]);
</script>
