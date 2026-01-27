import type defaultsPlugin from "bootstrap-vue-next/src/plugins/defaultsPlugin.js";

const dflt = {
  min: 0,
  max: 200,
  donut: true,
  gaugeWidthScale: 0.6,
  counter: true,
  hideInnerShadow: true,
  gaugeColor: ["rgba(42, 118, 244, .1)"],
  levelColors: ["#22c55e"],
};

const defs1 = {
  label: "label",
  value: 65,
  min: 0,
  max: 100,
  decimals: 0,
  gaugeWidthScale: 0.6,
  pointer: true,
  gaugeColor: ["rgba(42, 118, 244, .1)"],
  levelColors: ["#22c55e"],
  pointerOptions: {
    toplength: 10,
    bottomlength: 10,
    bottomwidth: 2,
  },
  counter: true,
};

const defs2 = {
  label: "label",
  value: 35,
  min: 0,
  max: 100,
  decimals: 0,
  gaugeWidthScale: 0.6,
  pointer: true,
  gaugeColor: ["rgba(42, 118, 244, .1)"],
  levelColors: ["#22c55e"],
  pointerOptions: {
    toplength: 5,
    bottomlength: 15,
    bottomwidth: 2,
  },
  counter: true,
  donut: true,
};

export const justgageChart1 = {
  value: 125,
  title: "javascript call",
  defaults: dflt,
};

export const justgageChart2 = {
  value: 25,
  title: "data-attributes",
  defaults: dflt,
};

export const counterRefresh = {
  id: "Counter_2",
  value: 72,
  min: 0,
  max: 100,
  donut: true,
  gaugeWidthScale: 0.6,
  counter: true,
  hideInnerShadow: true,
  gaugeColor: ["rgba(42, 118, 244, .1)"],
  levelColors: ["#22c55e"],
};

export const animationEvents = {
  id: "Animation_Events",
  value: 45,
  min: 0,
  max: 100,
  symbol: "%",
  pointer: true,
  gaugeColor: ["rgba(42, 118, 244, .1)"],
  levelColors: ["#22c55e"],
  pointerOptions: {
    toplength: -15,
    bottomlength: 10,
    bottomwidth: 12,
    color: "#ff5da0",
    stroke: "#ffffff",
    stroke_width: 3,
    stroke_linecap: "round",
  },
  gaugeWidthScale: 0.6,
  counter: true,
};

export const counter = {
  id: "Counter",
  value: 40960,
  min: 1024,
  max: 1000000,
  gaugeWidthScale: 0.6,
  counter: true,
  formatNumber: true,
  gaugeColor: ["rgba(42, 118, 244, .1)"],
  levelColors: ["#22c55e"],
};

export const customWether = {
  id: "Custom_wether",
  value: 50,
  min: 0,
  max: 100,
  title: "Target",
  label: "temperature",
  pointer: true,
  gaugeColor: ["rgba(42, 118, 244, .1)"],
  levelColors: ["#22c55e"],
  textRenderer: function (val: number) {
    if (val < 50) {
      return "Cold";
    } else if (val > 50) {
      return "Hot";
    } else if (val === 50) {
      return "OK";
    }
  },
  onAnimationEnd: function () {
    console.log("f: onAnimationEnd()");
  },
};

export const fontOption = {
  id: "font_option",
  title: "Font Options",
  value: 72,
  min: 0,
  minTxt: "min",
  max: 100,
  maxTxt: "max",
  gaugeWidthScale: 0.6,
  counter: true,
  gaugeColor: ["rgba(42, 118, 244, .1)"],
  levelColors: ["#22c55e"],
  titleFontColor: "red",
  titleFontFamily: "Georgia",
  titlePosition: "below",
  valueFontColor: "blue",
  valueFontFamily: "Georgia",
};

export const jg1Option = {
  id: "jg1",
  defaults: defs1,
};

export const jg2Option = {
  id: "jg2",
  defaults: defs1,
};

export const jg3Option = {
  id: "jg3",
  defaults: defs1,
};

export const jg4Option = {
  id: "jg4",
  defaults: defs2,
};

export const jg5Option = {
  id: "jg5",
  defaults: defs2,
};

export const jg6Option = {
  id: "jg6",
  defaults: defs2,
};
