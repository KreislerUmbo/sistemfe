import type { ApexChartType } from "@/types";
import type {
  CustomerTableType,
  SocialStatType,
  StatType,
} from "@/views/apps/analytics/customers/components/types";

import avatar1 from "@/assets/images/users/avatar-1.jpg";
import avatar2 from "@/assets/images/users/avatar-2.jpg";
import avatar3 from "@/assets/images/users/avatar-3.jpg";
import avatar4 from "@/assets/images/users/avatar-4.jpg";
import avatar5 from "@/assets/images/users/avatar-5.jpg";

export const customerLineChart: ApexChartType = {
  height: 233,
  type: "line",
  series: [
    {
      name: "New Customers ",
      data: [0, 20, 15, 19, 14, 25, 30],
    },
    {
      name: "Returning Customers",
      data: [0, 8, 7, 13, 26, 16, 25],
    },
  ],
  options: {
    chart: {
      fontFamily: "inherit",
      height: 233,
      type: "line",
      toolbar: {
        show: false,
      },
      sparkline: {
        enabled: true,
      },
    },
    colors: ["var(--bs-primary)", "var(--bs-primary-bg-subtle)"],
    grid: {
      show: true,
      strokeDashArray: 3,
    },
    stroke: {
      curve: "smooth",
      colors: ["var(--bs-primary)", "var(--bs-primary-bg-subtle)"],
      width: 2,
    },
    markers: {
      colors: ["var(--bs-primary)", "var(--bs-primary-bg-subtle)"],
      strokeColors: "transparent",
    },
    tooltip: {
      x: {
        show: false,
      },
      followCursor: true,
    },
  },
};

export const customerStats: StatType[] = [
  {
    title: "Total Customers",
    stat: "38,321",
  },
  {
    title: "New Customers",
    stat: "946",
  },
  {
    title: "Returning Customers",
    stat: "70.8%",
  },
  {
    title: "Bounce Rate",
    stat: "1.5%",
  },
];

export const socialStatData: SocialStatType[] = [
  {
    name: "Twitter",
    clickCount: 2215,
    icon: "icofont-twitter",
    audience: {
      count: 214,
      change: 1.9,
    },
    commission: {
      count: 3251,
      change: 0.5,
    },
    variant: "bg-blue",
  },
  {
    name: "Google",
    clickCount: 2154,
    icon: "icofont-google-plus",
    audience: {
      count: 159,
      change: 2.5,
    },
    commission: {
      count: 1245,
      change: 0.7,
    },
    variant: "bg-danger",
  },
  {
    name: "Instagram",
    clickCount: 3251,
    icon: "icofont-instagram",
    audience: {
      count: 124,
      change: 1.7,
    },
    commission: {
      count: 2514,
      change: 0.2,
    },
    variant: "bg-warning",
  },
];

export const customerTableData: CustomerTableType[] = [
  {
    name: "Unity Pugh",
    avatar: avatar1,
    ext: 9958,
    city: "Curicó",
    startDate: "2005/02/11",
    completion: "37%",
  },
  {
    name: "Theodore Duran",
    avatar: avatar2,
    ext: 8971,
    city: "Dhanbad",
    startDate: "1999/04/07",
    completion: "97%",
  },
  {
    name: "Kylie Bishop",
    avatar: avatar3,
    ext: 3147,
    city: "Norman",
    startDate: "2005/09/08",
    completion: "63%",
  },
  {
    name: "Willow Gilliam",
    avatar: avatar4,
    ext: 3497,
    city: "Amqui",
    startDate: "2009/11/29",
    completion: "30%",
  },
  {
    name: "Blossom Dickerson",
    avatar: avatar5,
    ext: 5018,
    city: "Kempten",
    startDate: "2006/11/09",
    completion: "17%",
  },
  {
    name: "Elliott Snyder",
    avatar: avatar3,
    ext: 3925,
    city: "Enines",
    startDate: "2006/08/03",
    completion: "57%",
  },
  {
    name: "Castor Pugh",
    avatar: avatar1,
    ext: 9488,
    city: "Neath",
    startDate: "2014/12/23",
    completion: "93%",
  },
  {
    name: "Pearl Carlson",
    avatar: avatar2,
    ext: 6231,
    city: "Cobourg",
    startDate: "2014/08/31",
    completion: "100%",
  },
  {
    name: "Deirdre Bridges",
    avatar: avatar3,
    ext: 1579,
    city: "Eberswalde-Finow",
    startDate: "2014/08/26",
    completion: "44%",
  },
  {
    name: "Daniel Baldwin",
    avatar: avatar4,
    ext: 6095,
    city: "Moircy",
    startDate: "2000/01/11",
    completion: "33%",
  },
  {
    name: "Pearl Carlson",
    avatar: avatar5,
    ext: 6231,
    city: "Cobourg",
    startDate: "2014/08/31",
    completion: "100%",
  },
];
