export type ClientType = {
  name: string;
  role: string;
  avatar: string;
};
export type TaskType = {
  id: number;
  title: string;
  startDate: string;
  endDate: string;
  task: string;
  priority: "High" | "Low" | "Medium";
  team: string;
  status: "in-progress" | "done" | "pending";
  description: string;
  client: ClientType;
  projectType: string;
  progress: number;
  totalReport: number;
  assignedTo: string;
};
