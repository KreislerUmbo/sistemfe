type EventDetails = {
  title: string;
  time: string;
  description: string;
  avatar: string;
};

export type NotificationType = {
  date: string;
  count: number;
  events: EventDetails[];
};
