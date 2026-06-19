import type { ApexChartType, VisitType } from "@/types";
import type { StatType } from "@/views/dashboards/analytics/components/types";

import chrome from "@/assets/images/logos/chrome.png";
import microEdge from "@/assets/images/logos/micro-edge.png";
import explorer from "@/assets/images/logos/in-explorer.png";
import opera from "@/assets/images/logos/opera.png";

import usFlag from "@/assets/images/flags/us_flag.jpg";
import germanyFlag from "@/assets/images/flags/germany_flag.jpg";
import spainFlag from "@/assets/images/flags/spain_flag.jpg";
import bahaFlag from "@/assets/images/flags/baha_flag.jpg";

export const audienceOverviewChart: ApexChartType = {
  height: 280,
  type: "area",
  series: [
    {
      name: "Income",
      data: [31, 40, 28, 51, 31, 40, 28, 51, 31, 40, 28, 51],
    },
    {
      name: "Expenses",
      data: [0, 30, 10, 40, 30, 60, 50, 80, 70, 100, 90, 130],
    },
  ],
  options: {
    chart: {
      height: 280,
      type: "area",
      toolbar: {
        show: false,
      },
      dropShadow: {
        enabled: true,
        top: 12,
        left: 0,
        // bottom: 0,
        // right: 0,
        blur: 2,
        color: "rgba(132, 145, 183, 0.3)",
        opacity: 0.35,
      },
    },
    annotations: {
      xaxis: [
        {
          x: 312,
          strokeDashArray: 4,
          borderWidth: 1,
          borderColor: "var(--bs-secondary)",
        },
      ],
      points: [
        {
          x: 312,
          y: 52,
          marker: {
            size: 6,
            fillColor: "var(--bs-primary)",
            strokeColor: "var(--bs-card-bg)",
            strokeWidth: 4,
            radius: 5,
          },
          label: {
            borderWidth: 1,
            offsetY: -110,
            text: "50k",
            style: {
              background: "var(--bs-primary)",
              fontSize: "14px",
              fontWeight: "600",
            },
          },
        },
      ],
    },
    colors: ["#22c55e", "rgba(106, 155, 155, 0.3)"],
    dataLabels: {
      enabled: false,
    },
    stroke: {
      show: true,
      curve: "smooth",
      width: [3, 3],
      dashArray: [0, 0],
      lineCap: "round",
    },
    labels: [
      "Jan",
      "Feb",
      "Mar",
      "Apr",
      "May",
      "Jun",
      "Jul",
      "Aug",
      "Sep",
      "Oct",
      "Nov",
      "Dec",
    ],

    yaxis: {
      labels: {
        offsetX: -12,
        offsetY: 0,
        formatter: function (value) {
          return "$" + value;
        },
      },
    },
    grid: {
      strokeDashArray: 3,
      xaxis: {
        lines: {
          show: true,
        },
      },
      yaxis: {
        lines: {
          show: false,
        },
      },
    },
    legend: {
      show: false,
    },

    fill: {
      type: "gradient",
      gradient: {
        type: "vertical",
        shadeIntensity: 1,
        inverseColors: !1,
        opacityFrom: 0.05,
        opacityTo: 0.05,
        stops: [45, 100],
      },
    },
  },
};

export const visitorsChart: ApexChartType = {
  height: 230,
  type: "bar",
  series: [
    {
      name: "Visitors",
      data: [20, 38, 38, 72, 55, 63, 43],
    },
  ],
  options: {
    chart: {
      height: 230,
      type: "bar",
      toolbar: {
        show: false,
      },
    },
    fill: {
      type: "gradient",
      gradient: {
        shadeIntensity: 1,
        opacityFrom: 0.7,
        opacityTo: 1,
        colorStops: [
          {
            offset: 0,
            color: "rgba(106, 155, 155, 0.4)",
            opacity: 1,
          },
          {
            offset: 100,
            color: "rgba(106, 155, 155, 0.4)",
            opacity: 1,
          },
        ],
      },
    },

    plotOptions: {
      bar: {
        columnWidth: "55%",
        // endingShape: "rounded",
        borderRadius: 5,
      },
    },
    dataLabels: {
      enabled: false,
    },
    legend: {
      show: false,
    },
    yaxis: {
      labels: {
        show: false,
      },
    },
    grid: {
      strokeDashArray: 3,
      xaxis: {
        lines: {
          show: false,
        },
      },
      yaxis: {
        lines: {
          show: false,
        },
      },
    },
    xaxis: {
      // type: "week",
      categories: ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"],
      axisBorder: {
        show: false,
        color: "rgba(119, 119, 142, 0.05)",
        offsetX: 0,
        offsetY: 0,
      },
      axisTicks: {
        show: false,
        borderType: "solid",
        color: "rgba(119, 119, 142, 0.05)",
        // width: 6,
        offsetX: 0,
        offsetY: 0,
      },
      labels: {
        rotate: -90,
        style: {
          colors: "rgb(107 ,114 ,128)",
          fontSize: "12px",
        },
      },
    },
  },
};

export const trafficSourcesChart: ApexChartType = {
  height: 325,
  type: "radialBar",
  series: [76],
  options: {
    chart: {
      height: "325",
      type: "radialBar",
      offsetY: -20,
      sparkline: {
        enabled: true,
      },
    },
    plotOptions: {
      radialBar: {
        startAngle: -90,
        endAngle: 90,
        hollow: {
          size: "75%",
          position: "front",
        },
        track: {
          background: ["rgba(42, 118, 244, .18)"],
          strokeWidth: "80%",
          opacity: 0.5,
          margin: 5,
        },
        dataLabels: {
          name: {
            show: false,
          },
          value: {
            offsetY: -2,
            fontSize: "20px",
          },
        },
      },
    },
    stroke: {
      lineCap: "butt",
    },
    colors: ["#95a0c5"],
    grid: {
      padding: {
        top: -10,
      },
    },

    labels: ["Average Results"],
    responsive: [
      {
        breakpoint: 1150,
        options: {
          chart: {
            height: "150",
          },
        },
      },
    ],
  },
};

export const statData: StatType[] = [
  {
    title: "Sessions",
    stat: "24k",
    icon: "iconoir-hexagon-dice",
    change: "8.5%",
    subTitle: "New Sessions Today",
    variant: "success",
  },
  {
    title: "Avg.Sessions",
    stat: "00:18",
    icon: "iconoir-clock",
    change: "1.5%",
    subTitle: "Weekly Avg.Sessions",
    variant: "success",
  },
  {
    title: "Bounce Rate",
    stat: "36.45%",
    icon: "iconoir-percentage-circle",
    change: "8%",
    subTitle: "Up Bounce Rate Weekly",
    variant: "danger",
  },
];

export const visitsList: VisitType[] = [
  {
    channel: "Organic search",
    sessions: {
      count: 10853,
      percentage: 52,
    },
    prevPeriod: {
      count: 566,
      percentage: 92,
    },
    change: {
      percentage: 52.8,
      trend: "up",
    },
  },
  {
    channel: "Direct",
    sessions: {
      count: 2545,
      percentage: 47,
    },
    prevPeriod: {
      count: 498,
      percentage: 81,
    },
    change: {
      percentage: -17.2,
      trend: "down",
    },
  },
  {
    channel: "Referal",
    sessions: {
      count: 1836,
      percentage: 38,
    },
    prevPeriod: {
      count: 455,
      percentage: 74,
    },
    change: {
      percentage: 41.12,
      trend: "up",
    },
  },
  {
    channel: "Email",
    sessions: {
      count: 1958,
      percentage: 31,
    },
    prevPeriod: {
      count: 361,
      percentage: 61,
    },
    change: {
      percentage: -8.24,
      trend: "down",
    },
  },
  {
    channel: "Social",
    sessions: {
      count: 1566,
      percentage: 26,
    },
    prevPeriod: {
      count: 299,
      percentage: 49,
    },
    change: {
      percentage: 29.33,
      trend: "up",
    },
  },
];

export const browserData = [
  {
    image: chrome,
    browser: "Chrome",
    sessions: "10853 (52%)",
    bounceRate: "52.80%",
    transactions: "566 (92%)",
  },
  {
    image: microEdge,
    browser: "Microsoft Edge",
    sessions: "2545 (47%)",
    bounceRate: "47.54%",
    transactions: "498 (81%)",
  },
  {
    image: explorer,
    browser: "Internet-Explorer",
    sessions: "1836 (38%)",
    bounceRate: "41.12%",
    transactions: "455 (74%)",
  },
  {
    image: opera,
    browser: "Opera",
    sessions: "1958 (31%)",
    bounceRate: "36.82%",
    transactions: "361 (61%)",
  },
  {
    image: chrome,
    browser: "Chrome",
    sessions: "10853 (52%)",
    bounceRate: "52.80%",
    transactions: "566 (92%)",
  },
];

export const organicTraffic = [
  {
    count: 35365,
    country: "USA",
    countryFlag: usFlag,
    percentage: 2.5,
  },
  {
    count: 24865,
    country: "Germany",
    countryFlag: germanyFlag,
    percentage: 1.2,
  },
  {
    count: 18369,
    country: "Spain",
    countryFlag: spainFlag,
    percentage: 0.8,
  },
  {
    count: 11325,
    country: "Bahamas",
    countryFlag: bahaFlag,
    percentage: 2.5,
  },
];

export const worldMapLinesOptions = {
  map: "world",
  selector: "#map_2",
  mapBgColor: "#F7F8F9",
  zoomOnScroll: false,
  zoomButtons: false,
  markers: [
    { name: "Greenland", coords: [72, -42] },
    { name: "Canada", coords: [56.1304, -106.3468] },
    { name: "Brazil", coords: [-14.235, -51.9253] },
    { name: "Egypt", coords: [26.8206, 30.8025] },
    { name: "Russia", coords: [61, 105] },
    { name: "China", coords: [35.8617, 104.1954] },
    { name: "United States", coords: [37.0902, -95.7129] },
    { name: "Norway", coords: [60.472024, 8.468946] },
    { name: "Ukraine", coords: [48.379433, 31.16558] },
  ],
  lines: [
    { from: "Canada", to: "Egypt" },
    { from: "Russia", to: "Egypt" },
    { from: "Greenland", to: "Egypt" },
    { from: "Brazil", to: "Egypt" },
    { from: "United States", to: "Egypt" },
    { from: "China", to: "Egypt" },
    { from: "Norway", to: "Egypt" },
    { from: "Ukraine", to: "Egypt" },
  ],
  labels: {
    markers: {
      render: (marker: any) => marker.name,
    },
  },
  lineStyle: {
    animation: true,
    strokeDasharray: "6 3 6",
  },
  regionStyle: {
    initial: {
      fill: "rgba(169,183,197, 0.3)",
      fillOpacity: 1,
    },
  },
  markerStyle: {
    initial: {
      r: 5, // Marker width
      fill: "#22c55e", // Marker color
      fillOpacity: 1, // The opacity of the marker shape
      stroke: "#FFF", // Stroke
      strokeWidth: 1, // the stroke width
      strokeOpacity: 0.65, // The stroke opacity
    },
    // All options in initial object can be overitten in hover, selected, selectedHover object as well.
    hover: {
      stroke: "black",
      cursor: "pointer",
      strokeWidth: 2,
    },
    selected: {
      fill: "blue",
    },
    selectedHover: {
      fill: "red",
    },
  },
};
