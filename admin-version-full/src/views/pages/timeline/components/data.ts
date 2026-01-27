import type {
  Activity,
  activity2Type,
} from "@/views/pages/timeline/components/types";

export const activities: Activity[] = [
  {
    icon: "las la-check-circle text-primary",
    title: "Task finished",
    time: "10 Min ago",
    description:
      "There are many variations of passages of Lorem Ipsum available, but the majority have suffered alteration.",
  },
  {
    icon: "las la-user-clock text-danger",
    title: "Task Overdue",
    time: "50 Min ago",
    description:
      "There are many variations of passages of Lorem Ipsum available, but the majority have suffered alteration.",
    badges: ["Design", "HTML"],
  },
  {
    icon: "las la-clipboard-check text-primary",
    title: "New Task",
    time: "10 hours ago",
    description:
      "There are many variations of passages of Lorem Ipsum available, but the majority have suffered alteration.",
  },
  {
    icon: "las la-comment-dots text-danger",
    title: "New Comment",
    time: "Yesterday",
    description:
      "There are many variations of passages of Lorem Ipsum available, but the majority have suffered alteration.",
  },
  {
    icon: "las la-user-friends text-primary",
    title: "New Lead Meeting",
    time: "14 Nov 2019",
    description:
      "There are many variations of passages of Lorem Ipsum available, but the majority have suffered alteration.",
  },
];

export const activities2: activity2Type[] = [
  {
    date: "Sep 18, 2024",
    time: "05:01 PM",
    description:
      "It is a long established fact that a reader will be distracted.",
  },
  {
    date: "Aug 10, 2024",
    time: "11:19 AM",
    description:
      "There are many variations of passages of Lorem Ipsum available, but the majority.",
  },
  {
    date: "Aug 10, 2024",
    time: "11:19 AM",
    description:
      "There are many variations of passages of Lorem Ipsum available, but the majority.",
  },
  {
    date: "Aug 10, 2024",
    time: "11:19 AM",
    description:
      "There are many variations of passages of Lorem Ipsum available, but the majority.",
  },
  {
    date: "Jul 06, 2024",
    time: "02:02 PM",
    description:
      "The standard chunk of Lorem Ipsum used since the 1500s is reproduced below for those interested.",
  },
];
