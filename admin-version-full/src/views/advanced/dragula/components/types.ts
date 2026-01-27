export type MovableType = {
  id: number;
  logo: string;
  title: string;
  clientName: string;
  status: "TODO" | "in-progress" | "completed" | "pending";
  members?: string[];
  tasks?: number;
  progress?: number;
  budget?: number;
  startDate?: string;
  deadlineDate?: string;
};

export type ProjectType = {
  name: string;
  handle: string;
  img: string;
  email: string;
  phone: string;
  children: MovableType[];
};
