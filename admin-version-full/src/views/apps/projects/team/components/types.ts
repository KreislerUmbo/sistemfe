export type LeaderType = {
  name: string;
  avatar: string;
  role: string;
};
export type TeamType = {
  team: string;
  logo: string;
  leader: LeaderType;
  percentage: number;
  members: string[];
  description: string;
};
