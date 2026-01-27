export type MovableType = {
  id: string;
  sectionId: string;
  priority: string;
  title: string;
  image?: string;
  description?: string;
  commentsCount: number;
  totalTasks: number;
  completedTasks: number;
  tags?: string[];
};

export type KanbanTaskType = {
  id: number;
  title: string;
  issues: number;
  points: number;
  children: MovableType[];
};
