export type User = {
  email?: string;
  name?: string;
  surname?: string;
  fullname?:string;
  password?: string;
  role?: Role;
  token?: string;
  permissions?: Array<string>,
  formato_impresion_default?: 'a4' | 'ticket80mm';
};
export type Role = {
  id?: string;
  name?: string;
};
export type ResponseAuthLogin = {
  user?: User;
  access_token?: string;
};