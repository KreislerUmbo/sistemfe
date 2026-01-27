import type { BaseButtonVariant } from "bootstrap-vue-next";

export type StatType = {
  title: string;
  icon?: string;
  stat: string;
  change?: number;
  subTitle?: string;
  buttonVariant?:
    | keyof BaseButtonVariant
    | null
    | undefined
    | "outline-secondary";
};

export type SellingType = {
  name: string;
  flag: string;
  progress: number;
  sales: number;
};

export type OrderType = {
  name: string;
  avatar: string;
  id: string;
  amount: number;
};

export type ProductType = {
  name: string;
  image: string;
  id: string;
  price: number;
  originalPrice: number;
  stockQuantity: number;
  soldQuantity: number;
  status: "in-stock" | "out-of-stock";
};
