export type Media = {
    id: number;
    model_type: string;
    model_id: number;
    uuid: string;
    collection_name: string;
    name: string;
    file_name: string;
    mime_type: string;
    disk: string;
    conversions_disk: string;
    size: number;
    manipulations: object; // You can further define this type if needed
    custom_properties: object; // You can further define this type if needed
    generated_conversions: object; // You can further define this type if needed
    responsive_images: object; // You can further define this type if needed
    order_column: number;
    created_at: string;
    updated_at: string;
    original_url: string;
    preview_url: string;
}

// Example usage
const mediaItem: Media = {
    id: 1,
    model_type: "App\\Models\\User",
    model_id: 1,
    uuid: "unique-identifier",
    collection_name: "default",
    name: "sample-image",
    file_name: "sample-image.jpg",
    mime_type: "image/jpeg",
    disk: "public",
    conversions_disk: "public",
    size: 1024,
    manipulations: {},
    custom_properties: {},
    generated_conversions: {},
    responsive_images: {},
    order_column: 1,
    created_at: "2023-01-01T00:00:00.000Z",
    updated_at: "2023-01-01T00:00:00.000Z",
    original_url: "",
    preview_url: ""
};