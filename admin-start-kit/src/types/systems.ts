export interface System {
    id: number;
    category_id: number;
    name: string;
    slug: string;
    description_short: string;
    description_long: string | null;
    icon_emoji: string | null;
    badge: string | null;
    rating_avg: number;
    rating_count: number;
    is_featured: boolean;
    is_active: boolean;
    metadata: any;
    category?: string | null;
    created_at?: string;
    updated_at?: string;
}

export interface SystemsResponse {
    total: number;
    paginate: number;
    systems: System[];
}

export interface SystemResponse {
    code: number;
    message: string;
    system: System;
}