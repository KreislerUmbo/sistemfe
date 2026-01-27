import type {
  ContactType,
  MessageType,
} from "@/views/apps/chat/components/types";

import avatar1 from "@/assets/images/users/avatar-1.jpg";
import avatar2 from "@/assets/images/users/avatar-2.jpg";
import avatar3 from "@/assets/images/users/avatar-3.jpg";
import avatar4 from "@/assets/images/users/avatar-4.jpg";
import avatar5 from "@/assets/images/users/avatar-5.jpg";
import avatar6 from "@/assets/images/users/avatar-6.jpg";

export const contactsList: ContactType[] = [
  {
    name: "Daniel Madsen",
    time: "5 min ago",
    message: "Typing...",
    status: "online",
    unreadMessages: 3,
    image: avatar1,
  },
  {
    name: "James Andrews",
    time: "30 min ago",
    message: "Amazing Work!! 🔥",
    status: "offline",
    unreadMessages: 1,
    image: avatar2,
  },
  {
    name: "Shauna Jones",
    time: "Yesterday",
    message: "Congratulations!",
    status: "offline",
    image: avatar3,
  },
  {
    name: "Frank Wei",
    time: "1 Feb",
    message: "Voice message!",
    status: "online",
    image: avatar4,
  },
  {
    name: "Karen Savage",
    time: "8 Feb",
    message: "Ok, I like it. 👍",
    status: "offline",
    image: avatar5,
  },
  {
    name: "Carol Maier",
    time: "12 Feb",
    message: "Send a pic.!",
    status: "offline",
    image: avatar6,
  },
  {
    name: "Shauna Jones",
    time: "15 Feb",
    message: "Congratulations!",
    status: "offline",
    image: avatar3,
  },
  {
    name: "Frank Wei",
    time: "2 Mar",
    message: "Voice message!",
    status: "offline",
    image: avatar5,
  },
  {
    name: "Carol Maier",
    time: "14 Mar",
    message: "Send a pic.!",
    status: "offline",
    image: avatar6,
  },
];

export const messages: MessageType[] = [
  {
    id: 1,
    direction: "left",
    isSender: false,
    img: avatar1,
    messages: [
      "Good Morning !",
      "There are many variations of passages of Lorem Ipsum available.",
    ],
    time: "yesterday",
  },
  {
    id: 2,
    direction: "right",
    isSender: true,
    img: avatar3,
    messages: ["Hi,", "Can be verified on any platform using docker?"],
    time: "12:35pm",
  },
  {
    id: 3,
    direction: "left",
    isSender: false,
    img: avatar1,
    messages: [
      "Have a nice day !",
      "Command was run with root privileges. I'm sure about that.",
      "ok",
    ],
    time: "11:10pm",
  },
  {
    id: 4,
    direction: "right",
    isSender: true,
    img: avatar3,
    messages: [
      "Thanks for your message David. I thought I'm alone with this issue. Please, 👍 the issue to support it :)",
    ],
    time: "10:10pm",
  },
  {
    id: 5,
    direction: "left",
    isSender: false,
    img: avatar1,
    messages: [
      "Sorry, I just back !",
      "It seems like you are from Mac OS world. There is no /Users/ folder on linux 😄",
    ],
    time: "11:15am",
  },
  {
    id: 6,
    direction: "right",
    isSender: true,
    img: avatar3,
    messages: [
      "Good Morning !",
      "There are many variations of passages of Lorem Ipsum available.",
    ],
    time: "9:02am",
  },
  {
    id: 7,
    direction: "left",
    isSender: false,
    img: avatar1,
    messages: ["Sorry, I just back !"],
    time: "8:19pm",
  },
];
