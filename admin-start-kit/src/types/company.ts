import exp from "node:constants"

export type Company = {
    id:number,
    razon_social:string,
    razon_social_comercial:string,
    urbanizacion:string,
    cod_local:string,
    email:string,
    birth_date?:string,
    phone?:number,
    n_document?:string,
    ubigeo_region:string,
    ubigeo_provincia:string,
    ubigeo_distrito:string,
    region:string,
    provincia:string,
    distrito:string,
    address:string,
    created_at:string
}
export type CompanyResponse = {
    message:string,
    code?:number,
    company?: Company,
}

export type UbigeoClient = {
    id:string,
    name:string,
    region: string,
    province_id: string,
    department_id: string,
}