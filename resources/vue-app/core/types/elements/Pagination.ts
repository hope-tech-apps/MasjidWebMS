export type PaginationOptions = {
    itemsTotal: number;
    currentPage: number;
    perPage: number;
};

export type PaginationIndicies = {
    from: number;
    to: number;
};

export type PageChangeData = {
    indicies: PaginationIndicies|null;
    toPage: number;
}