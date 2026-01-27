import type { ClientType } from "@/views/apps/projects/clients/components/types";

import avatar1 from "@/assets/images/users/avatar-1.jpg";
import avatar2 from "@/assets/images/users/avatar-2.jpg";
import avatar3 from "@/assets/images/users/avatar-3.jpg";
import avatar4 from "@/assets/images/users/avatar-4.jpg";
import avatar5 from "@/assets/images/users/avatar-5.jpg";
import avatar6 from "@/assets/images/users/avatar-6.jpg";

import bahaflag from "@/assets/images/flags/baha_flag.jpg";
import usflag from "@/assets/images/flags/us_flag.jpg";
import frenchflag from "@/assets/images/flags/french_flag.jpg";
import germanyflag from "@/assets/images/flags/germany_flag.jpg";
import russiaflag from "@/assets/images/flags/russia_flag.jpg";
import spainflag from "@/assets/images/flags/spain_flag.jpg";

export const clients: ClientType[] = [
  {
    theme: "Mannat Themes",
    user: {
      name: "Karen Savage",
      handle: "@karen",
      avatar: avatar1,
      flag: bahaflag,
    },
    preProject: "Health App",
    description:
      "Explore our blog for in-depth articles, how-to guides, and inspiring stories that showcase the beauty of sustainable living.",
    contact: {
      email: "example@example.com",
      phone: "+1 123 456 789",
    },
  },
  {
    theme: "Mannat Themes",
    user: {
      name: "Carol Maier",
      handle: "@carol",
      avatar: avatar2,
      flag: usflag,
    },
    preProject: "Payment App",
    description:
      "Below are the contact details for our project client, provided for your reference and communication needs",
    contact: {
      email: "example@example.com",
      phone: "+1 123 456 789",
    },
  },
  {
    theme: "Mannat Themes",
    user: {
      name: "Frank Wei",
      handle: "@frank",
      avatar: avatar3,
      flag: frenchflag,
    },
    preProject: "Gaming App",
    description:
      "Discover the latest trends in eco-friendly living, from zero-waste hacks to renewable energy solutions. Thank you so much.",
    contact: {
      email: "example@example.com",
      phone: "+1 123 456 789",
    },
  },
  {
    theme: "Mannat Themes",
    user: {
      name: "Sandra Lally",
      handle: "@sandra",
      avatar: avatar4,
      flag: germanyflag,
    },
    preProject: "AI App",
    description:
      "Welcome to GreenEco Solutions, your go-to destination for sustainable living tips, eco-friendly products.",
    contact: {
      email: "example@example.com",
      phone: "+1 123 456 789",
    },
  },
  {
    theme: "Mannat Themes",
    user: {
      name: "James Andrews",
      handle: "@james",
      avatar: avatar5,
      flag: russiaflag,
    },
    preProject: "Video App",
    description:
      "Take action in your community and beyond with our resources for activism and advocacy. Thank you so much",
    contact: {
      email: "example@example.com",
      phone: "+1 123 456 789",
    },
  },
  {
    theme: "Mannat Themes",
    user: {
      name: "Shauna Jones",
      handle: "@shauna",
      avatar: avatar6,
      flag: spainflag,
    },
    preProject: "Audio App",
    description:
      "Below are the contact details for our project client, provided for your reference and communication needs",
    contact: {
      email: "example@example.com",
      phone: "+1 123 456 789",
    },
  },
];
