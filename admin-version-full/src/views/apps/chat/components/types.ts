export type ContactType = {
  name: string;
  time: string | Date;
  message: string;
  status: string;
  unreadMessages?: number;
  image: string;
};

export type MessageType = {
  id: number;
  direction: string;
  isSender: boolean;
  img: string;
  messages: string[];
  time: string;
};
