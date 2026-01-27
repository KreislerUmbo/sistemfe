type UserType = {
  name: string;
  avatar: string;
};

export type CustomerType = {
  checkboxId: string;
  customer: UserType;
  email: string;
  status:
    | "VIP"
    | "loyal"
    | "referral"
    | "inactive"
    | "re-order"
    | "new"
    | "potential"
    | "repeat";
  order: number;
  spent: number;
};

type ProductType = {
  name: string;
  details: string;
};

export type CustomerOrderType = {
  orderId: string;
  product: ProductType;
  date: string;
  paymentMethod: string;
  status: "completed" | "pending" | "cancel";
  amount: number;
};

export type StateType = {
  icon: string;
  title: string;
  prefix?: string;
  amount: number;
  suffix?: string;
  subtitle: string;
};
