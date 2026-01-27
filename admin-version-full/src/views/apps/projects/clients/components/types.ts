export type UserType = {
  name: string;
  handle: string;
  avatar: string;
  flag: string;
};
export type ContactType = {
  email: string;
  phone: string;
};
export type ClientType = {
  theme: string;
  user: UserType;
  preProject: string;
  description: string;
  contact: ContactType;
};
