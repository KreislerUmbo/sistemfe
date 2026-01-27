<template>
  <b-card-title class="my-4 fs-15">Bank Details</b-card-title>
  <b-row>
    <b-col md="6">
      <div class="form-group row mb-2">
        <label for="txtNameCard" class="col-lg-3 col-form-label text-lg-end"
          >Name on Card</label
        >
        <b-col lg="9">
          <b-form-input type="text" id="txtNameCard" name="txtNameCard" />
        </b-col>
      </div>
    </b-col>
    <b-col md="6">
      <div class="form-group row mb-2">
        <label
          for="ddlCreditCardType"
          class="col-lg-3 col-form-label text-lg-end"
          >Credit Card Type</label
        >
        <b-col lg="9">
          <b-form-select
            model-value="Hourly"
            id="ddlCreditCardType"
            name="ddlCreditCardType"
          >
            <option value="Hourly">--Please Select--</option>
            <option value="AE">American Express</option>
            <option value="VI">Visa</option>
            <option value="MC">MasterCard</option>
            <option value="DI">Discover</option>
          </b-form-select>
        </b-col>
      </div>
    </b-col>
  </b-row>
  <b-row>
    <b-col md="6">
      <div class="form-group row mb-2">
        <label
          for="txtCreditCardNumber"
          class="col-lg-3 col-form-label text-lg-end"
          >Credit Card Number</label
        >
        <b-col lg="9">
          <b-form-input
            type="text"
            id="txtCreditCardNumber"
            name="txtCreditCardNumber"
          />
        </b-col>
      </div>
    </b-col>
    <b-col md="6">
      <div class="form-group row mb-2">
        <label
          for="txtCardVerificationNumber"
          class="col-lg-3 col-form-label text-lg-end"
          >Card Verification Number</label
        >
        <b-col lg="9">
          <b-form-input
            type="text"
            id="txtCardVerificationNumber"
            name="txtCardVerificationNumber"
          />
        </b-col>
      </div>
    </b-col>
  </b-row>
  <b-row>
    <b-col md="6">
      <div class="form-group row mb-2">
        <label
          for="txtExpirationDate"
          class="col-lg-3 col-form-label text-lg-end"
          >Expiration Date</label
        >
        <b-col lg="9">
          <b-form-input
            type="text"
            id="txtExpirationDate"
            name="txtExpirationDate"
          />
        </b-col>
      </div>
    </b-col>
  </b-row>
  <div>
    <b-button
      type="button"
      id="step3Prev"
      variant="secondary"
      class="float-start mt-2"
      @click="previousPage"
      >Previous</b-button
    >
    <b-button
      type="button"
      id="step3Next"
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
