//esto va servir como sub
export type RoleUser = {
    id?:string,
    name: string,
}
// aqui se define los campos que se usan
export type User = {
    id:number,
    name:string,
    surname:string, 
    full_name:string,
    email:string,
    role_id:string,
    role:RoleUser,
    branch_id?: number,
    phone?:number,
    state:number, // 1: Activo, 2: Inactivo
    avatar?:string,
    type_document:string,
    n_document:string,
    gender:string,
    formato_impresion_default?: 'a4' | 'ticket80mm',
    created_at:string
}
//este es para  los return de los index,store,update,
export type Users = {
    users:  {
        data:User[],
    },
    total: number,
    paginate: number,
    roles: RoleUser[],
}

//esto son las respuestar de los return en el controlador
export type UserResponse = {
    message:string | number,
    code:number,
    user?: User,
}