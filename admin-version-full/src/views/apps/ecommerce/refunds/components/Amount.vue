<template>
  <b-card no-body>
    <b-card-body>
      <b-form>
        <b-form-group label="Amount" label-for="amount" class="mb-3">
          <b-form-input type="text" placeholder="Amount" id="amount" />
        </b-form-group>
        <b-form-group
          label="Message"
          label-for="customer-message"
          label-class="mt-2"
          class="mb-3"
        >
          <b-form-textarea
            type="textarea"
            rows="3"
            id="customer-message"
            placeholder="writing here.."
          />
        </b-form-group>
        <b-button type="submit" variant="primary">Refund</b-button>{{ " " }}
        <b-button type="button" variant="danger">Decline</b-button>
      </b-form>
    </b-card-body>
  </b-card>
</template>
