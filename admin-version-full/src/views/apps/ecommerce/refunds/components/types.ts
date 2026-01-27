type ProductType = {
  image: string;
  name: string;
  description: string;
};
export type OrderListType = {
  product: ProductType;
  price: number;
  quantity: number;
  total: number;
};
