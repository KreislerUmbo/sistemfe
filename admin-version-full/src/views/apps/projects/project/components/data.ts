import type { ProjectType } from "@/views/apps/projects/project/components/types";

import avatar1 from "@/assets/images/users/avatar-1.jpg";
import avatar2 from "@/assets/images/users/avatar-2.jpg";
import avatar3 from "@/assets/images/users/avatar-3.jpg";
import avatar4 from "@/assets/images/users/avatar-4.jpg";
import avatar5 from "@/assets/images/users/avatar-5.jpg";
import avatar6 from "@/assets/images/users/avatar-6.jpg";

import meta from "@/assets/images/logos/lang-logo/meta.png";
import gitlab from "@/assets/images/logos/lang-logo/gitlab.png";
import chatgpt from "@/assets/images/logos/lang-logo/chatgpt.png";
import dropbox from "@/assets/images/logos/lang-logo/dropbox.png";
import slack from "@/assets/images/logos/lang-logo/slack.png";
import dribbble from "@/assets/images/logos/lang-logo/dribbble.png";

export const projectsData: ProjectType[] = [
  {
    status: "in-progress",
    logo: meta,
    title: "Meta App",
    client: "Jack Jackson",
    tasks: 91,
    completionPercentage: 70,
    budget: "33,100",
    startDate: "08 Dec 2023",
    deadline: "28 Feb 2024",
    description:
      "Start with a catchy and descriptive title that summarizes the project.",
    members: [
      avatar1,
      avatar4,
      avatar6,
      avatar1,
      avatar4,
      avatar6,
      avatar1,
      avatar4,
      avatar6,
    ],
  },
  {
    status: "in-progress",
    logo: gitlab,
    title: "Gitlab",
    client: "Kevin Ewing",
    tasks: 32,
    completionPercentage: 90,
    budget: "10,500",
    startDate: "10 Mar 2023",
    deadline: "20 Sep 2024",
    description:
      "Encourage readers to take action, such as visiting the project website.",
    members: [avatar6, avatar5],
  },
  {
    status: "in-progress",
    logo: chatgpt,
    title: "ChatGPT 5",
    client: "Bobby Harrison",
    tasks: 68,
    completionPercentage: 75,
    budget: "41,100",
    startDate: "03 Jan 2023",
    deadline: "15 Aug 2024",
    description:
      "Highlight the main features or functionalities of the project.",
    members: [avatar3, avatar2, avatar4, avatar5, avatar3],
  },
  {
    status: "in-progress",
    logo: dropbox,
    title: "Dropbox",
    client: "Anthony Stockton",
    tasks: 130,
    completionPercentage: 95,
    budget: "55,100",
    startDate: "08 Feb 2023",
    deadline: "28 Dec 2023",
    description:
      "If applicable, give credit to any collaborators, contributors, or sources of inspiration for the project.",
    members: [avatar1, avatar4, avatar3, avatar2, avatar6],
  },
  {
    status: "in-progress",
    logo: slack,
    title: "Slack Chat",
    client: "Gilbert Jackson",
    tasks: 91,
    completionPercentage: 40,
    budget: "30,580",
    startDate: "02 Jun 2023",
    deadline: "15 Apr 2024",
    description:
      "Encourage readers to take action, such as visiting the project website, trying out a demo.",
    members: [
      avatar2,
      avatar6,
      avatar5,
      avatar2,
      avatar6,
      avatar5,
      avatar2,
      avatar6,
      avatar5,
      avatar2,
      avatar6,
    ],
  },
  {
    status: "in-progress",
    logo: dribbble,
    title: "Dribbble Shots",
    client: "Michael Heinz",
    tasks: 62,
    completionPercentage: 50,
    budget: "25,800",
    startDate: "08 Jan 2023",
    deadline: "28 Dec 2024",
    description:
      "TechSavvy Solutions was founded with a vision to revolutionize the digital landscape.",
    members: [avatar5, avatar2, avatar3, avatar5, avatar2, avatar3],
  },
];
