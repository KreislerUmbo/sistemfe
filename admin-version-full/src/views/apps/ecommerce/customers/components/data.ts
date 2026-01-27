import type {
  CustomerOrderType,
  CustomerType,
  StateType,
} from "@/views/apps/ecommerce/customers/components/types";
import { currency } from "@/helpers/constants";

import avatar1 from "@/assets/images/users/avatar-1.jpg";
import avatar2 from "@/assets/images/users/avatar-2.jpg";
import avatar3 from "@/assets/images/users/avatar-3.jpg";
import avatar4 from "@/assets/images/users/avatar-4.jpg";
import avatar5 from "@/assets/images/users/avatar-5.jpg";
import avatar6 from "@/assets/images/users/avatar-6.jpg";
import avatar7 from "@/assets/images/users/avatar-7.jpg";
import avatar8 from "@/assets/images/users/avatar-8.jpg";
import avatar9 from "@/assets/images/users/avatar-9.jpg";
import avatar10 from "@/assets/images/users/avatar-10.jpg";

export const customersData: CustomerType[] = [
  {
    checkboxId: "customCheck1",
    customer: {
      name: "Andy Timmons",
      avatar: avatar2,
    },
    email: "dummy@dummy.com",
    status: "VIP",
    order: 75,
    spent: 280,
  },
  {
    checkboxId: "customCheck2",
    customer: {
      name: "Jeff Beck",
      avatar: avatar3,
    },
    email: "fake@dummy.com",
    status: "loyal",
    order: 65,
    spent: 150,
  },
  {
    checkboxId: "customCheck3",
    customer: {
      name: "Vince Nelson",
      avatar: avatar4,
    },
    email: "exemple@dummy.com",
    status: "referral",
    order: 32,
    spent: 39,
  },
  {
    checkboxId: "customCheck4",
    customer: {
      name: "David Gilmour",
      avatar: avatar5,
    },
    email: "only@dummy.com",
    status: "inactive",
    order: 40,
    spent: 170,
  },
  {
    checkboxId: "customCheck5",
    customer: {
      name: "Dianna Smiley",
      avatar: avatar6,
    },
    email: "dummy@exemple.com",
    status: "re-order",
    order: 80,
    spent: 220,
  },
  {
    checkboxId: "customCheck6",
    customer: {
      name: "Adolfo Hess",
      avatar: avatar7,
    },
    email: "dummy2dummay@dummy.com",
    status: "new",
    order: 45,
    spent: 120,
  },
  {
    checkboxId: "customCheck7",
    customer: {
      name: "James Ahern",
      avatar: avatar8,
    },
    email: "dummy10@dummy.com",
    status: "repeat",
    order: 88,
    spent: 580,
  },
  {
    checkboxId: "customCheck8",
    customer: {
      name: "Simon Young",
      avatar: avatar9,
    },
    email: "totaldummy@dummy.com",
    status: "VIP",
    order: 124,
    spent: 380,
  },
  {
    checkboxId: "customCheck9",
    customer: {
      name: "Robert Lewis",
      avatar: avatar10,
    },
    email: "Exemple@dummy.com",
    status: "inactive",
    order: 84,
    spent: 254,
  },
  {
    checkboxId: "customCheck10",
    customer: {
      name: "Erik Brim",
      avatar: avatar1,
    },
    email: "onlyfake@dummy.com",
    status: "potential",
    order: 62,
    spent: 225,
  },
  {
    checkboxId: "customCheck11",
    customer: {
      name: "Kevin Powers",
      avatar: avatar5,
    },
    email: "exemple@exe.com",
    status: "repeat",
    order: 54,
    spent: 345,
  },
  {
    checkboxId: "customCheck12",
    customer: {
      name: "Wendy Keen",
      avatar: avatar3,
    },
    email: "Exemple@dummy.com",
    status: "new",
    order: 32,
    spent: 39,
  },
  {
    checkboxId: "customCheck13",
    customer: {
      name: "Wendy Keen",
      avatar: avatar1,
    },
    email: "Exemple@dummy.com",
    status: "new",
    order: 32,
    spent: 39,
  },
];

export const customerOrder: CustomerOrderType[] = [
  {
    orderId: "632536",
    product: {
      name: "Bata Shoes",
      details: "size-08 (Model 2024)",
    },
    date: "15/08/2023",
    paymentMethod: "UPI",
    status: "completed",
    amount: 390,
  },
  {
    orderId: "365485",
    product: {
      name: "Morden Chair",
      details: "Size-Mediam (Model 2021)",
    },
    date: "22/09/2023",
    paymentMethod: "Banking",
    status: "completed",
    amount: 630,
  },
  {
    orderId: "325415",
    product: {
      name: "Reebok Shoes",
      details: "size-08 (Model 2021)",
    },
    date: "31/12/2023",
    paymentMethod: "Paypal",
    status: "cancel",
    amount: 450,
  },
  {
    orderId: "546987",
    product: {
      name: "Cosco Vollyboll",
      details: "size-04 (Model 2021)",
    },
    date: "05/01/2024",
    paymentMethod: "UPI",
    status: "completed",
    amount: 880,
  },
  {
    orderId: "951236",
    product: {
      name: "Royal Purse",
      details: "Pure Lether 100%",
    },
    date: "20/02/2024",
    paymentMethod: "BTC",
    status: "pending",
    amount: 520,
  },
];

export const stateData: StateType[] = [
  {
    icon: "iconoir-dollar-circle text-info",
    title: "Total Cost",
    prefix: currency,
    amount: 27215,
    suffix: "k",
    subtitle: "New 365 Days",
  },
  {
    icon: "iconoir-cart text-blue",
    title: "Total Order",
    amount: 190,
    subtitle: "Order 365 Days",
  },
  {
    icon: "iconoir-thumbs-up text-primary",
    title: "Completed",
    amount: 165,
    subtitle: "Comp. Order 365 Days",
  },
  {
    icon: "iconoir-xmark-circle text-danger",
    title: "Canceld",
    amount: 25,
    subtitle: "Canc.Order 365 Days",
  },
];
