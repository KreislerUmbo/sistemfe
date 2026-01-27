<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col cols="12">
        <UIComponentCard title="Textual Inputs">
          <b-row>
            <b-col lg="6">
              <b-row class="mb-3">
                <label
                  for="example-text-input"
                  class="col-sm-2 col-form-label text-lg-end"
                  >Text</label
                >
                <b-col sm="10">
                  <b-form-input
                    type="text"
                    id="example-text-input"
                    value="Artisanal kale"
                    placeholder="Artisanal kale"
                  />
                </b-col>
              </b-row>
              <b-row class="mb-3">
                <label
                  for="example-email-input"
                  class="col-sm-2 col-form-label text-lg-end"
                  >Email</label
                >
                <b-col sm="10">
                  <b-form-input
                    type="email"
                    id="example-email-input"
                    value="bootstrap@example.com"
                    placeholder="bootstrap@example.com"
                  />
                </b-col>
              </b-row>
              <b-row class="mb-3">
                <label
                  for="example-tel-input"
                  class="col-sm-2 col-form-label text-lg-end"
                  >Telephone</label
                >
                <b-col sm="10">
                  <b-form-input
                    type="tel"
                    id="example-tel-input"
                    value="1-(555)-555-5555"
                    placeholder="1-(555)-555-5555"
                  />
                </b-col>
              </b-row>
              <b-row class="mb-3">
                <label
                  for="example-password-input"
                  class="col-sm-2 col-form-label text-lg-end"
                  >Password</label
                >
                <b-col sm="10">
                  <b-form-input
                    type="password"
                    id="example-password-input"
                    value="hunter2"
                  />
                </b-col>
              </b-row>
              <b-row class="mb-3">
                <label
                  for="example-number-input"
                  class="col-sm-2 col-form-label text-lg-end"
                  >Number</label
                >
                <b-col sm="10">
                  <b-form-input
                    type="number"
                    id="example-number-input"
                    value="42"
                    placeholder="42"
                  />
                </b-col>
              </b-row>
              <b-row class="mb-3">
                <label
                  for="example-datetime-local-input"
                  class="col-sm-2 col-form-label text-lg-end"
                  >Date and time</label
                >
                <b-col sm="10">
                  <b-form-input
                    type="datetime-local"
                    id="example-datetime-local-input"
                    value="2011-08-19T13:45:00"
                    placeholder="2011-08-19T13:45:00"
                  />
                </b-col>
              </b-row>
              <b-row class="mb-3">
                <label
                  for="exampleColorInput"
                  class="col-sm-2 col-form-label text-lg-end"
                  >Color</label
                >
                <b-col sm="10">
                  <b-form-input
                    type="color"
                    id="exampleColorInput"
                    value="#0b51b7"
                    title="Choose your color"
                  />
                </b-col>
              </b-row>
              <b-row class="mb-3">
                <label class="col-sm-2 col-form-label text-lg-end"
                  >Select</label
                >
                <b-col sm="10">
                  <b-form-select model-value="select" id="required">
                    <option value="select">Open this select menu</option>
                    <option value="1">One</option>
                    <option value="2">Two</option>
                    <option value="3">Three</option>
                  </b-form-select>
                </b-col>
              </b-row>
              <b-row class="mb-3">
                <label
                  for="example-text-input-lg"
                  class="col-sm-2 col-form-label text-lg-end"
                  >Large</label
                >
                <b-col sm="10">
                  <b-form-input
                    type="text"
                    id="example-text-input-lg"
                    size="lg"
                    placeholder=".form-control-lg"
                  />
                </b-col>
              </b-row>
              <b-row class="mb-3">
                <label
                  for="example-text-input-sm"
                  class="col-sm-2 col-form-label text-lg-end"
                  >Small</label
                >
                <b-col sm="10">
                  <b-form-input
                    type="text"
                    id="example-text-input-sm"
                    size="sm"
                    placeholder=".form-control-sm"
                  />
                </b-col>
              </b-row>
            </b-col>

            <b-col lg="6">
              <b-row class="mb-3">
                <label
                  for="example-search-input"
                  class="col-sm-2 col-form-label text-lg-end"
                  >Search</label
                >
                <b-col sm="10">
                  <b-form-input
                    type="search"
                    id="example-search-input"
                    value="How do I shoot web"
                    placeholder="How do I shoot web"
                  />
                </b-col>
              </b-row>
              <b-row class="mb-3">
                <label
                  for="example-url-input"
                  class="col-sm-2 col-form-label text-lg-end"
                  >URL</label
                >
                <b-col sm="10">
                  <b-form-input
                    type="url"
                    id="example-url-input"
                    value="https://getbootstrap.com"
                    placeholder="https://getbootstrap.com"
                  />
                </b-col>
              </b-row>
              <b-row class="mb-3">
                <label
                  for="example-date-input"
                  class="col-sm-2 col-form-label text-lg-end"
                  >Date</label
                >
                <b-col sm="10">
                  <b-form-input
                    type="date"
                    id="example-date-input"
                    value="2011-08-19"
                    placeholder="2011-08-19"
                  />
                </b-col>
              </b-row>
              <b-row class="mb-3">
                <label
                  for="example-month-input"
                  class="col-sm-2 col-form-label text-lg-end"
                  >Month</label
                >
                <b-col sm="10">
                  <b-form-input
                    type="month"
                    id="example-month-input"
                    value="2011-08"
                    placeholder="2011-08"
                  />
                </b-col>
              </b-row>
              <b-row class="mb-3">
                <label
                  for="example-week-input"
                  class="col-sm-2 col-form-label text-lg-end"
                  >Week</label
                >
                <b-col sm="10">
                  <b-form-input
                    type="week"
                    id="example-week-input"
                    value="2011-W33"
                    placeholder="2011-W33"
                  />
                </b-col>
              </b-row>
              <b-row class="mb-3">
                <label
                  for="example-time-input"
                  class="col-sm-2 col-form-label text-lg-end"
                  >Time</label
                >
                <b-col sm="10">
                  <b-form-input
                    type="time"
                    id="example-time-input"
                    value="13:45:00"
                    placeholder="13:45:00"
                  />
                </b-col>
              </b-row>
              <b-row class="mb-3 has-warning">
                <label
                  for="inputHorizontalWarning"
                  class="col-sm-2 col-form-label text-lg-end"
                  >Email</label
                >
                <b-col sm="10">
                  <b-form-input
                    type="email"
                    id="inputHorizontalWarning"
                    placeholder="name@example.com"
                  />
                  <small class="form-text text-muted"
                    >Example help text that remains unchanged.</small
                  >
                </b-col>
              </b-row>
              <b-row class="mb-3 has-success">
                <label
                  for="inputHorizontalSuccess"
                  class="col-sm-2 col-form-label text-lg-end"
                  >Email</label
                >
                <b-col sm="10">
                  <b-form-input
                    type="email"
                    id="inputHorizontalSuccess"
                    placeholder="name@example.com"
                    :state="true"
                  />
                  <b-form-valid-feedback>
                    Success! You've done it.
                  </b-form-valid-feedback>
                </b-col>
              </b-row>
              <b-row class="mb-3 has-error">
                <label
                  for="inputHorizontalDnger"
                  class="col-sm-2 col-form-label text-lg-end"
                  >Email</label
                >
                <b-col sm="10">
                  <b-form-input
                    type="email"
                    id="inputHorizontalDnger"
                    placeholder="name@example.com"
                    :state="false"
                  />
                  <b-form-invalid-feedback>
                    Sorry, that username's taken. Try another?
                  </b-form-invalid-feedback>
                </b-col>
              </b-row>
            </b-col>
          </b-row>
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Basic Form">
          <form>
            <b-form-group
              label="Email address"
              label-for="exampleInputEmail1"
              class="mb-3"
            >
              <b-form-input
                type="email"
                placeholder="Enter email"
                id="exampleInputEmail1"
              />
              <small id="emailHelp" class="form-text text-muted"
                >We'll never share your email with anyone else.</small
              >
            </b-form-group>

            <b-form-group
              label="Password"
              label-for="exampleInputPassword1"
              class="mb-3"
            >
              <b-form-input
                type="password"
                placeholder="Password"
                id="exampleInputPassword1"
              />
            </b-form-group>

            <b-form-group class="mb-3">
              <b-form-checkbox id="flexCheckDefaultdemo" name="checkbox-1">
                Check me out
              </b-form-checkbox>
            </b-form-group>

            <b-button type="submit" variant="primary">Submit</b-button>
            {{ " " }}
            <b-button type="button" variant="danger">Cancel</b-button>
          </form>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Horizontal Form">
          <b-form>
            <b-row class="mb-3">
              <label for="horizontalInput1" class="col-sm-2 col-form-label"
                >Email</label
              >
              <b-col sm="10">
                <b-form-input
                  type="email"
                  id="horizontalInput1"
                  placeholder="Enter Email"
                />
              </b-col>
            </b-row>

            <b-row class="mb-3">
              <label for="horizontalInput2" class="col-sm-2 col-form-label"
                >Password</label
              >
              <b-col sm="10">
                <b-form-input
                  type="password"
                  id="horizontalInput2"
                  placeholder="Password"
                />
              </b-col>
            </b-row>

            <b-row>
              <b-col sm="10" class="ms-auto">
                <b-form-checkbox
                  id="flexCheckDefaultdemo2"
                  name="checkbox"
                  class="mb-3"
                >
                  Remember me
                </b-form-checkbox>
              </b-col>
            </b-row>
            <b-row>
              <b-col sm="10" class="ms-auto">
                <b-button type="submit" variant="primary">Submit</b-button>
                {{ " " }}
                <b-button type="button" variant="danger">Cancel</b-button>
              </b-col>
            </b-row>
          </b-form>
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Custom Styles">
          <b-form>
            <b-row>
              <b-col md="4">
                <b-form-group label="Name" label-for="username" class="mb-3">
                  <b-form-input type="text" id="username" required />
                </b-form-group>
              </b-col>
              <b-col md="4">
                <b-form-group
                  label="Email address"
                  label-for="useremail"
                  class="mb-3"
                >
                  <b-form-input type="email" id="useremail" required />
                </b-form-group>
              </b-col>
              <b-col md="4">
                <b-form-group label="Subject" label-for="subject" class="mb-3">
                  <b-form-input type="text" id="subject" required />
                </b-form-group>
              </b-col>
            </b-row>
            <b-row>
              <b-col md="12">
                <b-form-group label="Message" label-for="message" class="mb-3">
                  <b-form-textarea type="textarea" rows="5" id="message" />
                </b-form-group>
              </b-col>
            </b-row>
            <b-row>
              <b-col sm="12" class="text-end">
                <b-button type="submit" variant="primary" class="px-4"
                  >Send Message</b-button
                >
              </b-col>
            </b-row>
          </b-form>
        </UIComponentCard>

        <UIComponentCard title="Inline Form">
          <b-form class="row align-items-center">
            <div class="col-auto">
              <b-input-group>
                <b-input-group-text>@</b-input-group-text>
                <b-form-input type="text" placeholder="Username" />
              </b-input-group>
            </div>
            <div class="col-auto">
              <b-input-group>
                <b-form-select model-value="select" id="required">
                  <option value="select">Choose...</option>
                  <option value="1">One</option>
                  <option value="2">Two</option>
                  <option value="3">Three</option>
                </b-form-select>
              </b-input-group>
            </div>
            <div class="col-auto">
              <b-button type="submit" variant="primary">Submit</b-button>
            </div>
          </b-form>
        </UIComponentCard>

        <UIComponentCard title="Input Size">
          <b-form>
            <b-form-input
              type="text"
              class="mb-2"
              size="lg"
              placeholder=".form-control-lg"
            />
            <b-form-input
              type="text"
              size="sm"
              placeholder=".form-control-sm"
            />
          </b-form>
        </UIComponentCard>

        <UIComponentCard title="File Browser">
          <b-input-group class="mb-3">
            <b-input-group-text>Upload</b-input-group-text>
            <b-form-file />
          </b-input-group>

          <b-input-group class="mb-3">
            <b-form-file />
            <b-input-group-text>Upload</b-input-group-text>
          </b-input-group>

          <b-input-group class="mb-3">
            <b-input-group-text class="btn btn-outline-secondary"
              >Button</b-input-group-text
            >
            <b-form-file />
          </b-input-group>

          <b-input-group>
            <b-form-file />
            <b-input-group-text class="btn btn-outline-secondary"
              >Button</b-input-group-text
            >
          </b-input-group>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Form Controls">
          <form>
            <b-form-group
              label="Email address"
              label-for="exampleFormControlInput1"
              class="mb-3"
            >
              <b-form-input
                type="email"
                id="exampleFormControlInput1"
                placeholder="name@example.com"
              />
            </b-form-group>

            <b-form-group
              label="Example select"
              label-for="exampleFormControlSelect1"
              class="mb-3"
            >
              <b-form-select model-value="1" id="exampleFormControlSelect1">
                <option value="1">1</option>
                <option value="2">2</option>
                <option value="3">3</option>
                <option value="4">4</option>
                <option value="5">5</option>
              </b-form-select>
            </b-form-group>

            <b-form-group
              label="Example multiple select"
              label-for="exampleFormControlSelect2"
              class="mb-3"
            >
              <b-form-select
                model-value="1"
                id="exampleFormControlSelect2"
                multiple
              >
                <option value="1">1</option>
                <option value="2">2</option>
                <option value="3">3</option>
                <option value="4">4</option>
                <option value="5">5</option>
              </b-form-select>
            </b-form-group>

            <b-form-group
              label="Example textarea"
              label-for="exampleFormControlTextarea1"
            >
              <b-form-textarea
                type="textarea"
                rows="3"
                id="exampleFormControlTextarea1"
              />
            </b-form-group>
          </form>
        </UIComponentCard>

        <UIComponentCard title="Range">
          <b-form-group label="Example range" label-for="customRange1">
            <b-form-input type="range" id="customRange1" />
          </b-form-group>
          <b-form-group label="Min and max" label-for="customRange2">
            <b-form-input id="customRange2" min="0" max="5" type="range" />
          </b-form-group>
          <b-form-group label="Steps" label-for="customRange3">
            <b-form-input
              id="customRange3"
              min="0"
              max="5"
              step="0.5"
              type="range"
            />
          </b-form-group>
        </UIComponentCard>

        <UIComponentCard title="Switches">
          <b-row>
            <b-col lg="4">
              <b-form-checkbox switch>Default switch</b-form-checkbox>
              <b-form-checkbox switch disabled>Disabled switch</b-form-checkbox>
            </b-col>
            <b-col lg="4">
              <div class="form-switch-secondary">
                <b-form-checkbox switch checked>Secondary</b-form-checkbox>
              </div>
              <div class="form-switch-success">
                <b-form-checkbox switch checked>Success</b-form-checkbox>
              </div>
              <div class="form-switch-warning">
                <b-form-checkbox switch checked>Warning</b-form-checkbox>
              </div>
              <div class="form-switch-info">
                <b-form-checkbox switch checked>Info</b-form-checkbox>
              </div>
            </b-col>
            <b-col lg="4">
              <div class="form-switch-danger">
                <b-form-checkbox switch checked>Danger</b-form-checkbox>
              </div>

              <div class="form-switch-dark">
                <b-form-checkbox switch checked>Dark</b-form-checkbox>
              </div>

              <div class="form-switch-purple">
                <b-form-checkbox switch checked>Purple</b-form-checkbox>
              </div>

              <div class="form-switch-pink">
                <b-form-checkbox switch checked>Pink</b-form-checkbox>
              </div>
            </b-col>
          </b-row>
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Checkboxs">
          <b-form class="form">
            <b-row class="mb-3">
              <label class="col-md-3 my-2 control-label">Checkboxes</label>
              <b-col md="9">
                <b-form-checkbox>Default checkbox</b-form-checkbox>
                <b-form-checkbox checked>Checked checkbox</b-form-checkbox>
                <b-form-checkbox disabled>Disabled checkbox</b-form-checkbox>
                <b-form-checkbox checked disabled
                  >Disabled checked checkbox</b-form-checkbox
                >
              </b-col>
            </b-row>

            <b-row class="mb-3">
              <label class="col-md-3 my-2 control-label"
                >Inline Checkboxes</label
              >
              <b-col md="9">
                <b-form-checkbox inline>HTML5</b-form-checkbox> {{ " " }}
                <b-form-checkbox inline>Css3</b-form-checkbox> {{ " " }}
                <b-form-checkbox inline>Javascript</b-form-checkbox>
              </b-col>
            </b-row>

            <b-row>
              <label class="col-md-3 my-1 control-label">Button Checkbox</label>
              <b-col md="9">
                <b-form-checkbox
                  :model-value="true"
                  size="sm"
                  button
                  button-variant="outline-primary"
                >
                  Single toggle
                </b-form-checkbox>
              </b-col>
            </b-row>
          </b-form>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Radios">
          <b-form class="form">
            <b-row class="mb-3">
              <label class="col-md-3 my-2 control-label">Radios</label>
              <b-col md="9">
                <b-form-radio name="exampleRadios" value="1" checked
                  >Default radio</b-form-radio
                >
                {{ " " }}
                <b-form-radio name="exampleRadios" value="2"
                  >Second default radio</b-form-radio
                >
                {{ " " }}
                <b-form-radio name="exampleRadios" value="3" disabled
                  >Disabled radio</b-form-radio
                >
              </b-col>
            </b-row>

            <b-row class="mb-3">
              <label class="col-md-3 my-1 control-label">Inline Radios</label>
              <b-col md="9">
                <b-form-radio name="radioDefault" value="1" inline
                  >HTML5</b-form-radio
                >
                <b-form-radio name="radioDefault" value="2" inline
                  >Css</b-form-radio
                >
                <b-form-radio name="radioDefault" value="3" inline
                  >Javascript</b-form-radio
                >
              </b-col>
            </b-row>

            <b-row>
              <label class="col-md-3 my-1 control-label">Button Radios</label>
              <b-col md="9" class="d-flex gap-1">
                <b-form-radio
                  name="radioDefault"
                  value="1"
                  button
                  button-variant="outline-success"
                  size="sm"
                  checked
                  >Checked success radio</b-form-radio
                >
                {{ " " }}
                <b-form-radio
                  name="radioDefault"
                  value="1"
                  button
                  button-variant="outline-danger"
                  size="sm"
                  >Danger radio</b-form-radio
                >
              </b-col>
            </b-row>
          </b-form>
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Input Groups Static">
          <b-input-group class="mb-3">
            <b-input-group-text>@</b-input-group-text>
            <b-form-input type="text" placeholder="Username" />
          </b-input-group>

          <b-input-group class="mb-3">
            <b-form-input type="text" placeholder="Recipient's username" />
            <b-input-group-text>@mannatthemes.com</b-input-group-text>
          </b-input-group>

          <label for="basic-url" class="form-label">Your vanity URL</label>
          <b-input-group class="mb-3">
            <b-input-group-text>https://mannatthemes.com</b-input-group-text>
            <b-form-input type="text" />
          </b-input-group>

          <b-input-group class="mb-3">
            <b-input-group-text>{{ currency }}</b-input-group-text>
            <b-form-input type="text" />
            <b-input-group-text>.00</b-input-group-text>
          </b-input-group>

          <b-input-group class="mb-3">
            <b-form-input type="text" placeholder="Username" />
            <b-input-group-text>@</b-input-group-text>
            <b-form-input type="text" placeholder="Server" />
          </b-input-group>

          <b-input-group>
            <b-input-group-text>With textarea</b-input-group-text>
            <b-form-textarea />
          </b-input-group>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Input Groups Buttons">
          <form>
            <b-row class="mb-3 g-2">
              <b-col md="6">
                <b-input-group>
                  <b-input-group-text is-text>
                    <input
                      type="checkbox"
                      class="form-check-input mt-0"
                      aria-label="Checkbox for following text input"
                    />
                  </b-input-group-text>
                  <b-form-input aria-label="Text input with checkbox" />
                </b-input-group>
              </b-col>
              <b-col md="6">
                <b-input-group>
                  <b-input-group-text is-text>
                    <input
                      type="radio"
                      class="form-check-input mt-0"
                      aria-label="Checkbox for following text input"
                    />
                  </b-input-group-text>
                  <b-form-input aria-label="Text input with checkbox" />
                </b-input-group>
              </b-col>
            </b-row>
            <b-input-group class="mb-3">
              <b-input-group-text class="btn btn-secondary"
                ><i class="fas fa-search"></i
              ></b-input-group-text>
              <b-form-input type="text" />
            </b-input-group>

            <b-input-group class="mb-3">
              <b-form-input type="text" placeholder="Search" />
              <b-input-group-text class="btn btn-secondary"
                >Go!</b-input-group-text
              >
            </b-input-group>

            <b-input-group class="mb-3">
              <b-form-input type="text" placeholder="Email" />
              <b-input-group-text class="btn btn-secondary"
                >Submit</b-input-group-text
              >
            </b-input-group>

            <b-input-group>
              <b-form-select model-value="select" id="required">
                <option value="select">Choose...</option>
                <option value="1">One</option>
                <option value="2">Two</option>
                <option value="3">Three</option>
              </b-form-select>
              <b-input-group-text class="btn btn-secondary"
                >Button</b-input-group-text
              >
            </b-input-group>
          </form>
        </UIComponentCard>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import { currency } from "@/helpers/constants";
import DefaultLayout from "@/layouts/DefaultLayout.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
