import type { BlogType } from "@/views/pages/blogs/components/types";
import avatar2 from "@/assets/images/users/avatar-2.jpg";
import avatar6 from "@/assets/images/users/avatar-6.jpg";
import avatar7 from "@/assets/images/users/avatar-7.jpg";
import avatar8 from "@/assets/images/users/avatar-8.jpg";
import avatar9 from "@/assets/images/users/avatar-9.jpg";
import avatar10 from "@/assets/images/users/avatar-10.jpg";

import img1 from "@/assets/images/extra/card/img-1.jpg";
import img2 from "@/assets/images/extra/card/img-2.jpg";
import img3 from "@/assets/images/extra/card/img-3.jpg";
import img4 from "@/assets/images/extra/card/img-4.jpg";
import img5 from "@/assets/images/extra/card/img-5.jpg";
import img6 from "@/assets/images/extra/card/img-6.jpg";

export const blogData: BlogType[] = [
  {
    id: 1,
    category: "Finance",
    date: "15 Sep 2024",
    image: img1,
    title: "How does cancer grow and spread?",
    content:
      "It is a long established fact that a reader will be distracted by the readable content of a page when looking at its layout.",
    author: {
      name: "Billy Pearson",
      avatar: avatar10,
    },
  },
  {
    id: 2,
    category: "Food",
    date: "31 Dec 2023",
    image: img2,
    title: "Where does psoriasis usually start?",
    content:
      "The standard chunk of Lorem Ipsum used since the 1500s is reproduced below for those interested. Cum sociis natoque penatibus et magnis.",
    author: {
      name: "Harry Simpson",
      avatar: avatar9,
    },
  },
  {
    id: 3,
    category: "Health",
    date: "18 Oct 2024",
    image: img3,
    title: "What Can Yoga Do for Migraine Relief?",
    content:
      "When an unknown printer took a galley of type and scrambled generator on the Internet it to make a type specimen book.",
    author: {
      name: "Larry Wells",
      avatar: avatar8,
    },
  },
  {
    id: 4,
    category: "Nature",
    date: "12 Feb 2024",
    image: img4,
    title: "How Long Do Migraine Attacks Last? What to Expect",
    content:
      "It is a long established fact that a reader will be distracted by the readable content of a page when looking at its layout.",
    author: {
      name: "Steven Warner",
      avatar: avatar7,
    },
  },
  {
    id: 5,
    category: "Economic",
    date: "26 Jun 2024",
    image: img5,
    title: "Your 5-Minute Read on Beating Stress",
    content:
      "Contrary to popular belief, Lorem Ipsum is not simply random text. It has roots in a piece of classical Latin literature.",
    author: {
      name: "Morgan Smith",
      avatar: avatar6,
    },
  },
  {
    id: 6,
    category: "Fashion",
    date: "01 Aug 2024",
    image: img6,
    title: "World Oral Health Day 2023",
    content:
      "There are many variations of passages of Lorem Ipsum available, but the majority web page editors now have suffered",
    author: {
      name: "Cecil Herbert",
      avatar: avatar2,
    },
  },
];
