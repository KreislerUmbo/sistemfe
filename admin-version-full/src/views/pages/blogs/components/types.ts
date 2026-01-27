export type BlogType = {
  id: number;
  category: string;
  date: string;
  image: string;
  title: string;
  content: string;
  author: {
    name: string;
    avatar: string;
  };
};
