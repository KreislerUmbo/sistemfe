<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Default Slider">
          <div id="slider"></div>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Only showing tooltips when sliding handles">
          <div id="slider_2"></div>
        </UIComponentCard>
      </b-col>
    </b-row>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Using HTML5 input elements">
          <div id="html5" class="mb-3"></div>
          <b-row>
            <b-col cols="3">
              <select class="form-select" id="input-select"></select>
            </b-col>
            <b-col cols="3">
              <input
                type="number"
                class="form-control"
                min="-20"
                max="40"
                step="1"
                id="input-number"
              />
            </b-col>
          </b-row>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Working with dates">
          <div id="slider-date" class="mb-2"></div>
          <span class="example-val d-block fw-semibold" id="event-start"></span>
          <span class="example-val d-block fw-semibold" id="event-end"></span>
        </UIComponentCard>
      </b-col>
    </b-row>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Skipping steps">
          <div id="skipstep" class="mb-2"></div>
          <span
            class="example-val d-block fw-semibold"
            id="skip-value-lower"
          ></span>
          <span
            class="example-val d-block fw-semibold"
            id="skip-value-upper"
          ></span>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <b-col>
                <b-card-title>Moving the slider by clicking pips</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0 pb-5">
            <div id="slider-pips"></div>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>
    <b-row>
      <b-col md="6" lg="6">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <b-col>
                <b-card-title>Soft limits</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0 pb-5">
            <div id="soft"></div>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>

<script setup lang="ts">
import { onMounted } from "vue";
import noUiSlider from "nouislider";
import type { Options } from "nouislider";
import wNumb from "wnumb";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";

onMounted(() => {
  //  default
  const slider = document.getElementById("slider");
  if (slider) {
    noUiSlider.create(slider, {
      start: [5],
      step: 1,
      range: {
        min: [0],
        max: [10],
      },
    });
  }

  // date slider
  const hidingTooltipSlider = document.getElementById("slider_2");

  if (hidingTooltipSlider) {
    noUiSlider.create(hidingTooltipSlider, {
      start: [20, 80],
      connect: true,
      range: {
        min: 0,
        max: 100,
      },
      tooltips: true,
    });
  }

  // date
  // Create a new date from a string, return as a timestamp.
  function timestamp(str: string) {
    return new Date(str).getTime();
  }
  // Create a list of day and month names.
  const weekdays = [
    "Sunday",
    "Monday",
    "Tuesday",
    "Wednesday",
    "Thursday",
    "Friday",
    "Saturday",
  ];

  const months = [
    "January",
    "February",
    "March",
    "April",
    "May",
    "June",
    "July",
    "August",
    "September",
    "October",
    "November",
    "December",
  ];

  // Append a suffix to dates.
  function nth(d: number) {
    if (d > 3 && d < 21) return "th";
    switch (d % 10) {
      case 1:
        return "st";
      case 2:
        return "nd";
      case 3:
        return "rd";
      default:
        return "th";
    }
  }

  // Create a string representation of the date.
  function formatDate(date: Date) {
    return (
      weekdays[date.getDay()] +
      ", " +
      date.getDate() +
      nth(date.getDate()) +
      " " +
      months[date.getMonth()] +
      " " +
      date.getFullYear()
    );
  }
  const dateSlider = document.getElementById("slider-date") as any;

  if (dateSlider) {
    noUiSlider.create(dateSlider, {
      // Create two timestamps to define a range.
      range: {
        min: timestamp("2010"),
        max: timestamp("2016"),
      },

      // Steps of one week
      step: 7 * 24 * 60 * 60 * 1000,

      // Two more timestamps indicate the handle starting positions.
      start: [timestamp("2011"), timestamp("2015")],

      // No decimals
      format: wNumb({
        decimals: 0,
      }),
    });
    let dateValues = [
      document.getElementById("event-start"),
      document.getElementById("event-end"),
    ];
    dateSlider.noUiSlider.on("update", function (values: any, handle: any) {
      dateValues[handle]!.innerHTML = formatDate(new Date(+values[handle]));
    });
  }

  // HTML
  const select = document.getElementById("input-select") as any;
  // Append the option elements
  if (select) {
    for (let i = -20; i <= 40; i++) {
      let option = document.createElement("option");
      option.text = i.toString();
      option.value = i.toString();

      select.appendChild(option);
    }

    const html5Slider = document.getElementById("html5") as any;
    if (html5Slider) {
      noUiSlider.create(html5Slider, {
        start: [10, 30],
        connect: true,
        range: {
          min: -20,
          max: 40,
        },
      });

      const inputNumber = document.getElementById("input-number") as any;

      html5Slider.noUiSlider.on("update", function (values: any, handle: any) {
        const value = values[handle];

        if (handle) {
          inputNumber.value = value;
        } else {
          select.value = Math.round(value);
        }
      });
    }
  }

  // Skipstep

  const skipSlider = document.getElementById("skipstep") as any;
  if (skipSlider) {
    noUiSlider.create(skipSlider, {
      range: {
        min: 0,
        "10%": 10,
        "20%": 20,
        "30%": 30,
        // Nope, 40 is no fun.
        "50%": 50,
        "60%": 60,
        "70%": 70,
        // I never liked 80.
        "90%": 90,
        max: 100,
      },
      snap: true,
      start: [20, 90],
    });

    const skipValues = [
      document.getElementById("skip-value-lower"),
      document.getElementById("skip-value-upper"),
    ];

    skipSlider.noUiSlider.on("update", function (values: any, handle: any) {
      skipValues[handle]!.innerHTML = values[handle];
    });
  }

  // Slider pips

  const pipsSlider = document.getElementById("slider-pips") as any;
  if (pipsSlider) {
    noUiSlider.create(pipsSlider, {
      range: {
        min: 0,
        max: 100,
      },
      start: [50],
      pips: { mode: "count", values: 5 },
    } as Options);
    const pips = pipsSlider.querySelectorAll(".noUi-value");

    for (let i = 0; i < pips.length; i++) {
      // For this example. Do this in CSS!
      pips[i].style.cursor = "pointer";
    }
  }

  // Soft

  const softSlider = document.getElementById("soft") as any;
  if (softSlider) {
    noUiSlider.create(softSlider, {
      start: 50,
      range: {
        min: 0,
        max: 100,
      },
      pips: {
        mode: "values",
        values: [20, 80],
        density: 4,
      },
      tooltips: true,
    } as Options);
    softSlider.noUiSlider.on("change", function (values: any, handle: any) {
      if (values[handle] < 20) {
        softSlider.noUiSlider.set(20);
      } else if (values[handle] > 80) {
        softSlider.noUiSlider.set(80);
      }
    });
  }
});
</script>
