export type User = {
  email?: string;
  name?: string;
  surname?: string;
  fullname?:string;
  password?: string;
  role?: Role;
  token?: string;
  permissions?: Array<string>,
};
export type Role = {
  id?: string;
  name?: string;
};
export type ResponseAuthLogin = {
  user?: User;
  access_token?: string;
};