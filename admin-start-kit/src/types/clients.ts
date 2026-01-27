export type ClientUser = {
    id?:string,
    full_name: string,
}
export type UbigeoClient = { //es para el ubigeos
    id:string,
    name:string,
    province_id?:string,
    department_id?:string,
}
export type Client = { 
    id:number,
    name:string,
    surname?:string,
    full_name:string,
    name_comerc?:string,
    email:string,
    birth_date?:string,
    user_id:string,
    user:ClientUser,
    type_client:string,
    phone?:string,
    state:number,
    type_document?:string,
    n_document?:string,
    ubigeo_region:string,
    ubigeo_provincia:string,
    ubigeo_distrito:string,
    region:string,
    provincia:string,
    distrito:string,
    address:string,
    gender:string,
    created_at:string
}
export type Clients = { //esto nos sirve para listar que en el backend es el return del index
    clients:  {
        data:Client[],
    },
    total: number,
    paginate: number,
}
export type ClientResponse = {//nos sirve para registrar y actualizar
    message:string | number,
    code?:number,
    client?: Client,
}