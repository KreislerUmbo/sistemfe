import type { OrderListType } from "@/views/apps/ecommerce/refunds/components/types";

import products03 from "@/assets/images/products/03.png";
import products04 from "@/assets/images/products/04.png";
import products06 from "@/assets/images/products/06.png";
import products05 from "@/assets/images/products/05.png";
import products01 from "@/assets/images/products/01.png";

export const orderList: OrderListType[] = [
  {
    product: {
      image: products03,
      name: "Royal Purse",
      description: "Pure Leather 100%",
    },
    price: 80,
    quantity: 3,
    total: 240,
  },
  {
    product: {
      image: products04,
      name: "Apple Watch",
      description: "Size-05 (Model 2021)",
    },
    price: 100,
    quantity: 1,
    total: 100,
  },
  {
    product: {
      image: products06,
      name: "Cosco Volleyball",
      description: "size-04 (Model 2021)",
    },
    price: 20,
    quantity: 4,
    total: 80,
  },
  {
    product: {
      image: products05,
      name: "Reebok Shoes",
      description: "size-08 (Model 2021)",
    },
    price: 50,
    quantity: 10,
    total: 500,
  },
  {
    product: {
      image: products01,
      name: "Modern Chair",
      description: "Size-Medium (Model 2021)",
    },
    price: 70,
    quantity: 2,
    total: 140,
  },
];
