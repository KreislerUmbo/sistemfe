import type { ApexChartType } from "@/types";
import type {
  SocialMediaStatType,
  TopCountryType,
} from "@/views/apps/analytics/reports/components/types";

import usFlag from "@/assets/images/flags/us_flag.jpg";
import spainFlag from "@/assets/images/flags/spain_flag.jpg";
import germanyFlag from "@/assets/images/flags/germany_flag.jpg";
import bahaFlag from "@/assets/images/flags/baha_flag.jpg";

export const metricsChart: ApexChartType = {
  height: 292,
  type: "bar",
  series: [
    {
      name: "2024",
      data: [2.7, 2.2, 1.3, 2.5, 1, 2.5, 1.2, 1.2, 2.7, 1, 3.6, 2.1],
    },
    {
      name: "2023",
      data: [
        -2.3, -1.9, -1, -2.1, -1.3, -2.2, -1.1, -2.3, -2.8, -1.1, -2.5, -1.5,
      ],
    },
  ],
  options: {
    chart: {
      toolbar: {
        show: false,
      },
      type: "bar",
      fontFamily: "inherit",
      foreColor: "#adb0bb",
      height: 292,
      stacked: true,
      offsetX: -15,
    },
    colors: ["var(--bs-primary)", "var(--bs-secondary)"],
    plotOptions: {
      bar: {
        horizontal: false,
        barHeight: "80%",
        columnWidth: "12%",
        borderRadius: 3,
        borderRadiusApplication: "end",
        borderRadiusWhenStacked: "all",
      },
    },
    dataLabels: {
      enabled: false,
    },
    legend: {
      show: false,
    },
    grid: {
      show: true,
      strokeDashArray: 3,
      padding: {
        top: 0,
        bottom: 0,
        right: 0,
      },
      borderColor: "rgba(0,0,0,0.05)",
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
    // yaxis: {
    //   min: -5,
    //   max: 5,
    // },
    xaxis: {
      axisBorder: {
        show: false,
      },
      axisTicks: {
        show: false,
      },
      categories: [
        "Jan",
        "Feb",
        "Mar",
        "Apr",
        "May",
        "Jun",
        "July",
        "Aug",
        "Sep",
        "Oct",
        "Nov",
        "Dec",
      ],
    },
    yaxis: {
      tickAmount: 4,
    },
  },
};

export const socialStats: SocialMediaStatType[] = [
  {
    url: "htpps:/",
    source: "Twitter",
    views: 9.2,
    uniques: 7.9,
  },
  {
    url: ".com/dashboard",
    source: "Facebook",
    views: 7.7,
    uniques: 6.2,
  },
  {
    url: ".com/ecommerce-index",
    source: "Instagram",
    views: 6.8,
    uniques: 5.5,
  },
  {
    url: ".com/apps/projects-overview",
    source: "LinkedIn",
    views: 5.0,
    uniques: 4.9,
  },
  {
    url: ".com/blog/crypto/exchange",
    source: "WhatsApp",
    views: 4.3,
    uniques: 3.3,
  },
];

export const topCountriesData: TopCountryType[] = [
  {
    countryFlag: usFlag,
    name: "USA",
    count: "35,365",
    change: 2.5,
  },
  {
    countryFlag: germanyFlag,
    name: "Germany",
    count: "24,865",
    change: 1.2,
  },
  {
    countryFlag: spainFlag,
    name: "Spain",
    count: "18,369",
    change: 0.8,
  },
  {
    countryFlag: bahaFlag,
    name: "Bahamas",
    count: "11,325",
    change: 2.5,
  },
];
