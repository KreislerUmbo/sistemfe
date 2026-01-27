<template>
  <b-card no-body>
    <b-card-header>
      <b-row class="align-items-center">
        <b-col>
          <b-card-title>Personal Information</b-card-title>
        </b-col>
      </b-row>
    </b-card-header>
    <b-card-body class="pt-0">
      <div class="b-form-group mb-3 row">
        <label
          class="col-xl-3 col-lg-3 text-end mb-lg-0 align-self-center form-label"
          >First Name</label
        >
        <b-col lg="9" xl="8">
          <b-form-input type="text" value="Rosa" placeholder="Rosa" />
        </b-col>
      </div>
      <div class="b-form-group mb-3 row">
        <label
          class="col-xl-3 col-lg-3 text-end mb-lg-0 align-self-center form-label"
          >Last Name</label
        >
        <b-col lg="9" xl="8">
          <b-form-input type="text" value="Dodson" placeholder="Dodson" />
        </b-col>
      </div>
      <div class="b-form-group mb-3 row">
        <label
          class="col-xl-3 col-lg-3 text-end mb-lg-0 align-self-center form-label"
          >Company Name</label
        >
        <b-col lg="9" xl="8">
          <b-form-input
            type="text"
            value="MannatThemes"
            placeholder="MannatThemes"
          />
          <span class="form-text text-muted font-12"
            >We'll never share your email with anyone else.</span
          >
        </b-col>
      </div>
      <div class="b-form-group mb-3 row">
        <label
          class="col-xl-3 col-lg-3 text-end mb-lg-0 align-self-center form-label"
          >Contact Phone</label
        >
        <b-col lg="9" xl="8">
          <b-input-group>
            <b-input-group-text
              ><i class="las la-phone"></i
            ></b-input-group-text>
            <b-form-input
              type="text"
              value="+123456789"
              placeholder="+123456789"
            />
          </b-input-group>
        </b-col>
      </div>
      <div class="b-form-group mb-3 row">
        <label
          class="col-xl-3 col-lg-3 text-end mb-lg-0 align-self-center form-label"
          >Email Address</label
        >
        <b-col lg="9" xl="8">
          <b-input-group>
            <b-input-group-text><i class="las la-at"></i></b-input-group-text>
            <b-form-input
              type="email"
              value="rosa.dodson@demo.com"
              placeholder="rosa.dodson@demo.com"
            />
          </b-input-group>
        </b-col>
      </div>
      <div class="b-form-group mb-3 row">
        <label
          class="col-xl-3 col-lg-3 text-end mb-lg-0 align-self-center form-label"
          >Website Link</label
        >
        <b-col lg="9" xl="8">
          <b-input-group>
            <b-input-group-text><i class="la la-globe"></i></b-input-group-text>
            <b-form-input
              type="text"
              value="https://mannatthemes.com/"
              placeholder="https://mannatthemes.com"
            />
          </b-input-group>
        </b-col>
      </div>
      <div class="b-form-group mb-3 row">
        <label
          class="col-xl-3 col-lg-3 text-end mb-lg-0 align-self-center form-label"
          >Country</label
        >
        <b-col lg="9" xl="8">
          <b-form-select model-value="select" id="priority">
            <option value="london">London</option>
            <option value="india">India</option>
            <option value="usa">USA</option>
            <option value="canada">Canada</option>
            <option value="thailand">Thailand</option>
          </b-form-select>
        </b-col>
      </div>
      <div class="b-form-group row">
        <b-col lg="9" xl="8" class="offset-lg-3">
          <b-button type="submit" variant="primary">Submit</b-button>{{ " " }}
          <b-button type="button" variant="danger">Cancel</b-button>
        </b-col>
      </div>
    </b-card-body>
  </b-card>
  <b-card no-body>
    <b-card-header>
      <b-card-title>Change Password</b-card-title>
    </b-card-header>
    <b-card-body class="pt-0">
      <div class="form-group mb-3 row">
        <label
          class="col-xl-3 col-lg-3 text-end mb-lg-0 align-self-center form-label"
          >Current Password</label
        >
        <b-col lg="9" xl="8">
          <b-form-input type="password" placeholder="Password" />
          <a href="#" class="text-primary font-12">Forgot password ?</a>
        </b-col>
      </div>
      <div class="form-group mb-3 row">
        <label
          class="col-xl-3 col-lg-3 text-end mb-lg-0 align-self-center form-label"
          >New Password</label
        >
        <b-col lg="9" xl="8">
          <b-form-input type="password" placeholder="New Password" />
        </b-col>
      </div>
      <div class="form-group mb-3 row">
        <label
          class="col-xl-3 col-lg-3 text-end mb-lg-0 align-self-center form-label"
          >Confirm Password</label
        >
        <b-col lg="9" xl="8">
          <b-form-input type="password" placeholder="Re-Password" />
        </b-col>
      </div>
      <div class="form-group row">
        <b-col lg="9" xl="8" class="offset-lg-3">
          <b-button type="submit" variant="primary">Change Password</b-button
          >{{ " " }}
          <b-button type="button" variant="danger">Cancel</b-button>
        </b-col>
      </div>
    </b-card-body>
  </b-card>
  <b-card no-body>
    <b-card-header>
      <b-card-title>Other Settings</b-card-title>
    </b-card-header>
    <b-card-body class="pt-0">
      <div class="form-check">
        <input
          class="form-check-input"
          type="checkbox"
          value=""
          id="Email_Notifications"
          checked
        />
        <label class="form-check-label" for="Email_Notifications">
          Email Notifications
        </label>
        <span class="form-text text-muted fs-12 mt-0">Do you need them?</span>
      </div>
      <div class="form-check mt-2">
        <input
          class="form-check-input"
          type="checkbox"
          value=""
          id="API_Access"
        />
        <label class="form-check-label" for="API_Access"> API Access </label>
        <span class="form-text text-muted font-12 mt-0"
          >Enable/Disable access</span
        >
      </div>
    </b-card-body>
  </b-card>
</template>
<script setup lang="ts"></script>
