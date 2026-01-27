import type { ApexChartType } from "@/types";
import type { TaskType } from "@/views/apps/projects/task/components/types";

import avatar6 from "@/assets/images/users/avatar-6.jpg";
import avatar1 from "@/assets/images/users/avatar-1.jpg";
import avatar3 from "@/assets/images/users/avatar-3.jpg";

export const taskChart: ApexChartType = {
  height: 260,
  type: "donut",
  series: [50, 25, 25],
  options: {
    chart: {
      height: 260,
      type: "donut",
    },
    plotOptions: {
      pie: {
        donut: {
          size: "80%",
        },
      },
    },
    dataLabels: {
      enabled: false,
    },

    stroke: {
      show: true,
      width: 2,
      colors: ["transparent"],
    },
    legend: {
      show: true,
      position: "bottom",
      horizontalAlign: "center",
      // verticalAlign: 'middle',
      floating: false,
      fontSize: "13px",
      fontFamily: "Be Vietnam Pro, sans-serif",
      offsetX: 0,
      offsetY: 0,
    },
    labels: ["Active", "Completed", "Assigned"],
    colors: ["#22c55e", "#08b0e7", "#ffc728"],

    responsive: [
      {
        breakpoint: 600,
        options: {
          plotOptions: {
            donut: {
              customScale: 0.2,
            },
          },
          chart: {
            height: 240,
          },
          legend: {
            show: false,
          },
        },
      },
    ],
    tooltip: {
      y: {
        formatter: function (val) {
          return val + " %";
        },
      },
    },
  },
};

export const tasksDetails: TaskType[] = [
  {
    id: 1,
    title: "Start from to end",
    startDate: "20 Mar 2024",
    endDate: "30 Nov 2024",
    task: "Working API issue",
    priority: "High",
    team: "React development team",
    status: "in-progress",
    description:
      "Outline common error scenarios that team members may encounter when using the APIs. Provide guidance on how to handle these errors gracefully and troubleshoot issues effectively. Offer examples of typical API requests and corresponding responses. These examples can help team members understand how to interact with the APIs and interpret the data they receive.",
    client: {
      name: "Elliott Snyder",
      role: "Client",
      avatar: avatar6,
    },
    projectType: "Bank data Management",
    progress: 70,
    totalReport: 12,
    assignedTo: "Kylie Bishop",
  },
  {
    id: 2,
    title: "Start from to end",
    startDate: "10 Jan 2024",
    endDate: "30 Apr 2024",
    task: "Add Product page",
    priority: "Low",
    team: "Flutter development team",
    status: "done",
    description:
      "Outline common error scenarios that team members may encounter when using the APIs. Provide guidance on how to handle these errors gracefully and troubleshoot issues effectively. Offer examples of typical API requests and corresponding responses. These examples can help team members understand how to interact with the APIs and interpret the data they receive.",
    client: {
      name: "Daniel Baldwin",
      role: "Client",
      avatar: avatar1,
    },
    projectType: "Ecommerce",
    progress: 85,
    totalReport: 15,
    assignedTo: "Pearl Carlson",
  },
  {
    id: 3,
    title: "Start from to end",
    startDate: "15 Jun 2024",
    endDate: "15 Aug 2024",
    task: "Form submit page",
    priority: "Medium",
    team: "Angular development team",
    status: "pending",
    description:
      "Outline common error scenarios that team members may encounter when using the APIs. Provide guidance on how to handle these errors gracefully and troubleshoot issues effectively. Offer examples of typical API requests and corresponding responses. These examples can help team members understand how to interact with the APIs and interpret the data they receive.",
    client: {
      name: "Unity Pugh",
      role: "Client",
      avatar: avatar3,
    },
    projectType: "AI Chat & Images",
    progress: 30,
    totalReport: 8,
    assignedTo: "Theodore Duran",
  },
];
