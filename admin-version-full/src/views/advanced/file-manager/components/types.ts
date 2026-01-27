export type FolderType = {
  title: string;
  image: string;
  files: number;
  storage: string;
  progress: number;
};

export type DocumentType = {
  file_name: string;
  date: string;
  size: string;
  avatars?: string[];
};
