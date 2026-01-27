export type OrderType = {
  orderId: string;
  productName: string;
  productDetails: string;
  orderDate: string;
  paymentMethod: string;
  status: "completed" | "cancel" | "pending";
  amount: number;
};

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
