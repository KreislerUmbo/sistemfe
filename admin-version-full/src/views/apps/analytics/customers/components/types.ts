export type StatType = {
  title: string;
  stat: string;
};

export type SocialStatType = {
  name: string;
  clickCount: number;
  icon: string;
  audience: {
    count: number;
    change: number;
  };
  commission: {
    count: number;
    change: number;
  };
  variant: string;
};

export type CustomerTableType = {
  name: string;
  avatar: string;
  ext: number;
  city: string;
  startDate: string;
  completion: string;
};
