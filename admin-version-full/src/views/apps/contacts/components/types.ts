export type ContactType = {
  avatar: string;
  name: string;
  email: string;
  phone: string;
  status: "active" | "inactive" | "repeat";
  type: string;
  isNew?: boolean;
};
