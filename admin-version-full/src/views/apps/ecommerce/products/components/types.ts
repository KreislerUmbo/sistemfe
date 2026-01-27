export type ProductType = {
  id: number;
  name: string;
  category: string;
  pics: number;
  price: number;
  status: "published" | "inactive" | "draft";
  createdAt: string;
  image: string;
  description: string;
};
