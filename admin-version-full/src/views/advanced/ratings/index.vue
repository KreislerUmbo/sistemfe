<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Default star rating">
          <form>
            <div class="starability-basic">
              <input type="radio" id="rate5" name="rating" value="5" />
              <label for="rate5" title="Amazing">5 stars</label>

              <input type="radio" id="rate4" name="rating" value="4" />
              <label for="rate4" title="Very good">4 stars</label>

              <input type="radio" id="rate3" name="rating" value="3" />
              <label for="rate3" title="Average">3 stars</label>

              <input type="radio" id="rate2" name="rating" value="2" />
              <label for="rate2" title="Not good">2 stars</label>

              <input type="radio" id="rate1" name="rating" value="1" />
              <label for="rate1" title="Terrible">1 star</label>
            </div>
          </form>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Slot machine rating">
          <form>
            <div class="starability-slot">
              <input type="radio" id="slot-rate5" name="rating" value="5" />
              <label for="slot-rate5" title="Amazing">5 stars</label>

              <input type="radio" id="slot-rate4" name="rating" value="4" />
              <label for="slot-rate4" title="Very good">4 stars</label>

              <input type="radio" id="slot-rate3" name="rating" value="3" />
              <label for="slot-rate3" title="Average">3 stars</label>

              <input type="radio" id="slot-rate2" name="rating" value="2" />
              <label for="slot-rate2" title="Not good">2 stars</label>

              <input type="radio" id="slot-rate1" name="rating" value="1" />
              <label for="slot-rate1" title="Terrible">1 star</label>
            </div>
          </form>
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Growing star rating">
          <form>
            <div class="starability-grow">
              <input type="radio" id="growing-rate5" name="rating" value="5" />
              <label for="growing-rate5" title="Amazing">5 stars</label>

              <input type="radio" id="growing-rate4" name="rating" value="4" />
              <label for="growing-rate4" title="Very good">4 stars</label>

              <input type="radio" id="growing-rate3" name="rating" value="3" />
              <label for="growing-rate3" title="Average">3 stars</label>

              <input type="radio" id="growing-rate2" name="rating" value="2" />
              <label for="growing-rate2" title="Not good">2 stars</label>

              <input type="radio" id="growing-rate1" name="rating" value="1" />
              <label for="growing-rate1" title="Terrible">1 star</label>
            </div>
          </form>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Growing & rotating star rating">
          <form>
            <div class="starability-growRotate">
              <input
                type="radio"
                id="growing-rotating-rate5"
                name="rating"
                value="5"
              />
              <label for="growing-rotating-rate5" title="Amazing"
                >5 stars</label
              >

              <input
                type="radio"
                id="growing-rotating-rate4"
                name="rating"
                value="4"
              />
              <label for="growing-rotating-rate4" title="Very good"
                >4 stars</label
              >

              <input
                type="radio"
                id="growing-rotating-rate3"
                name="rating"
                value="3"
              />
              <label for="growing-rotating-rate3" title="Average"
                >3 stars</label
              >

              <input
                type="radio"
                id="growing-rotating-rate2"
                name="rating"
                value="2"
              />
              <label for="growing-rotating-rate2" title="Not good"
                >2 stars</label
              >

              <input
                type="radio"
                id="growing-rotating-rate1"
                name="rating"
                value="1"
              />
              <label for="growing-rotating-rate1" title="Terrible"
                >1 star</label
              >
            </div>
          </form>
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Fading star rating">
          <form>
            <div class="starability-fade">
              <input type="radio" id="fading-rate5" name="rating" value="5" />
              <label for="fading-rate5" title="Amazing">5 stars</label>

              <input type="radio" id="fading-rate4" name="rating" value="4" />
              <label for="fading-rate4" title="Very good">4 stars</label>

              <input type="radio" id="fading-rate3" name="rating" value="3" />
              <label for="fading-rate3" title="Average">3 stars</label>

              <input type="radio" id="fading-rate2" name="rating" value="2" />
              <label for="fading-rate2" title="Not good">2 stars</label>

              <input type="radio" id="fading-rate1" name="rating" value="1" />
              <label for="fading-rate1" title="Terrible">1 star</label>
            </div>
          </form>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Checkmark rating">
          <form>
            <div class="starability-checkmark">
              <input
                type="radio"
                id="checkmark-rate5"
                name="rating"
                value="5"
              />
              <label for="checkmark-rate5" title="Amazing">5 stars</label>

              <input
                type="radio"
                id="checkmark-rate4"
                name="rating"
                value="4"
              />
              <label for="checkmark-rate4" title="Very good">4 stars</label>

              <input
                type="radio"
                id="checkmark-rate3"
                name="rating"
                value="3"
              />
              <label for="checkmark-rate3" title="Average">3 stars</label>

              <input
                type="radio"
                id="checkmark-rate2"
                name="rating"
                value="2"
              />
              <label for="checkmark-rate2" title="Not good">2 stars</label>

              <input
                type="radio"
                id="checkmark-rate1"
                name="rating"
                value="1"
              />
              <label for="checkmark-rate1" title="Terrible">1 star</label>
            </div>
          </form>
        </UIComponentCard>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>

<script setup lang="ts">
import DefaultLayout from "@/layouts/DefaultLayout.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
