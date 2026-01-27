export type ProjectType = {
  status: "in-progress";
  logo: string;
  title: string;
  client: string;
  tasks: number;
  completionPercentage: number;
  budget: string;
  startDate: string;
  deadline: string;
  description: string;
  members: string[];
};
