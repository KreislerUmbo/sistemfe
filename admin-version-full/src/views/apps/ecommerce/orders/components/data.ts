import type {
  OrderListType,
  OrderType,
} from "@/views/apps/ecommerce/orders/components/types";
import { currency } from "@/helpers/constants";

import products01 from "@/assets/images/products/01.png";
import products03 from "@/assets/images/products/03.png";
import products04 from "@/assets/images/products/04.png";
import products05 from "@/assets/images/products/05.png";
import products06 from "@/assets/images/products/06.png";

export const ordersData: OrderType[] = [
  {
    orderId: "546987",
    productName: "Bata Shoes",
    productDetails: "size-08 (Model 2024)",
    orderDate: "15/08/2023",
    paymentMethod: "UPI",
    status: "completed",
    amount: 390,
  },
  {
    orderId: "362514",
    productName: "Morden Chair",
    productDetails: "Size-Mediam (Model 2021)",
    orderDate: "22/09/2023",
    paymentMethod: "Banking",
    status: "completed",
    amount: 630,
  },
  {
    orderId: "215487",
    productName: "Reebok Shoes",
    productDetails: "size-08 (Model 2021)",
    orderDate: "31/12/2023",
    paymentMethod: "Paypal",
    status: "cancel",
    amount: 450,
  },
  {
    orderId: "326598",
    productName: "Cosco Vollyboll",
    productDetails: "size-04 (Model 2021)",
    orderDate: "05/01/2024",
    paymentMethod: "UPI",
    status: "completed",
    amount: 880,
  },
  {
    orderId: "369852",
    productName: "Royal Purse",
    productDetails: "Pure Lether 100%",
    orderDate: "20/02/2024",
    paymentMethod: "BTC",
    status: "pending",
    amount: 520,
  },
  {
    orderId: "987456",
    productName: "Bata Shoes",
    productDetails: "size-08 (Model 2024)",
    orderDate: "15/08/2023",
    paymentMethod: "UPI",
    status: "completed",
    amount: 390,
  },
  {
    orderId: "159753",
    productName: "Morden Chair",
    productDetails: "Size-Mediam (Model 2021)",
    orderDate: "22/09/2023",
    paymentMethod: "Banking",
    status: "completed",
    amount: 630,
  },
  {
    orderId: "852456",
    productName: "Reebok Shoes",
    productDetails: "size-08 (Model 2021)",
    orderDate: "31/12/2023",
    paymentMethod: "Paypal",
    status: "cancel",
    amount: 450,
  },
  {
    orderId: "154863",
    productName: "Cosco Vollyboll",
    productDetails: "size-04 (Model 2021)",
    orderDate: "05/01/2024",
    paymentMethod: "UPI",
    status: "completed",
    amount: 880,
  },
  {
    orderId: "625877",
    productName: "Royal Purse",
    productDetails: "Pure Lether 100%",
    orderDate: "20/02/2024",
    paymentMethod: "BTC",
    status: "pending",
    amount: 520,
  },
];

export const OrderInfo = {
  username: "@donFlo",
  name: "Don Flowers",
  email: "DonIFlowers@jourrapide.com",
  payment: currency + "2450 ",
  orderDate: "31 Dec 2023",
  delivery_date: "05 Jan 2024",
  courier: "FedEx Corporation",
  address: "718 Bingamon Branch Road",
  location: "Central Valley",
  pincode: "NY 10917",
};

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
