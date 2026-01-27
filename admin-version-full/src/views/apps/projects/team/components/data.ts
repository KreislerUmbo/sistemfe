import type { TeamType } from "@/views/apps/projects/team/components/types";

import avatar1 from "@/assets/images/users/avatar-1.jpg";
import avatar2 from "@/assets/images/users/avatar-2.jpg";
import avatar3 from "@/assets/images/users/avatar-3.jpg";
import avatar4 from "@/assets/images/users/avatar-4.jpg";
import avatar5 from "@/assets/images/users/avatar-5.jpg";
import avatar6 from "@/assets/images/users/avatar-6.jpg";
import avatar7 from "@/assets/images/users/avatar-7.jpg";
import avatar8 from "@/assets/images/users/avatar-8.jpg";
import avatar9 from "@/assets/images/users/avatar-9.jpg";
import avatar10 from "@/assets/images/users/avatar-10.jpg";

import nextjs from "@/assets/images/logos/lang-logo/nextjs.png";
import reactjs from "@/assets/images/logos/lang-logo/reactjs.png";
import svelte from "@/assets/images/logos/lang-logo/svelte.png";
import vue from "@/assets/images/logos/lang-logo/vue.png";
import symfony from "@/assets/images/logos/lang-logo/symfony.png";
import nodejs from "@/assets/images/logos/lang-logo/nodejs.png";

export const teamsData: TeamType[] = [
  {
    team: "Nextjs Developer Team",
    logo: nextjs,
    leader: {
      name: "Carol Maier",
      avatar: avatar1,
      role: "Team Leader",
    },
    percentage: 50,
    members: [
      avatar1,
      avatar2,
      avatar4,
      avatar5,
      avatar4,
      avatar6,
      avatar4,
      avatar5,
      avatar6,
    ],
    description:
      "Paul is an experienced cybersecurity analyst with 10 years of practice.",
  },
  {
    team: "Reactjs Developer Team",
    logo: reactjs,
    leader: {
      name: "Sandra Lally",
      avatar: avatar2,
      role: "Team Leader",
    },
    percentage: 80,
    members: [avatar2, avatar4, avatar6, avatar8, avatar10],
    description:
      "Angela is a skilled content writer with over 9 years of experience.",
  },
  {
    team: "Sveltejs Developer Team",
    logo: svelte,
    leader: {
      name: "Scott Holland",
      avatar: avatar3,
      role: "Team Leader",
    },
    percentage: 45,
    members: [avatar3, avatar6, avatar9, avatar4, avatar6, avatar9],
    description:
      "Scott is a seasoned professional with more than 12 years of experience in software engineering.",
  },
  {
    team: "Vuejs Developer Team",
    logo: vue,
    leader: {
      name: "Gordon Aiello",
      avatar: avatar8,
      role: "Team Leader",
    },
    percentage: 90,
    members: [avatar10, avatar1, avatar9, avatar8],
    description:
      "Jane has over 10 years of experience in software development.",
  },
  {
    team: "Symfony Developer Team",
    logo: symfony,
    leader: {
      name: "Angela McGary",
      avatar: avatar7,
      role: "Team Leader",
    },
    percentage: 25,
    members: [
      avatar9,
      avatar5,
      avatar3,
      avatar1,
      avatar6,
      avatar2,
      avatar1,
      avatar6,
    ],
    description:
      "Angela possesses more than a decade of expertise in software engineering.",
  },
  {
    team: "Nodejs Developer Team",
    logo: nodejs,
    leader: {
      name: "Mike Gillam",
      avatar: avatar10,
      role: "Team Leader",
    },
    percentage: 65,
    members: [avatar5, avatar10, avatar4, avatar9],
    description:
      "Mike has over ten years of experience in software development.",
  },
];
