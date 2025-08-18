declare module 'bootstrap' {
    export class Alert {
        constructor(element: HTMLElement);
        close(): void;
        static getInstance(element: HTMLElement): Alert | null;
        static getOrCreateInstance(element: HTMLElement): Alert;
    }

    export class Modal {
        constructor(element: HTMLElement, options?: ModalOptions);
        toggle(): void;
        show(): void;
        hide(): void;
        handleUpdate(): void;
        dispose(): void;
        static getInstance(element: HTMLElement): Modal | null;
        static getOrCreateInstance(element: HTMLElement, options?: ModalOptions): Modal;
    }

    export interface ModalOptions {
        backdrop?: boolean | 'static';
        keyboard?: boolean;
        focus?: boolean;
    }

    export class Button {
        constructor(element: HTMLElement);
        toggle(): void;
        static getInstance(element: HTMLElement): Button | null;
        static getOrCreateInstance(element: HTMLElement): Button;
    }

    export class Carousel {
        constructor(element: HTMLElement, options?: CarouselOptions);
        cycle(): void;
        pause(): void;
        prev(): void;
        next(): void;
        to(index: number): void;
        dispose(): void;
        static getInstance(element: HTMLElement): Carousel | null;
        static getOrCreateInstance(element: HTMLElement, options?: CarouselOptions): Carousel;
    }

    export interface CarouselOptions {
        interval?: number;
        keyboard?: boolean;
        pause?: 'hover' | false;
        ride?: 'carousel';
        wrap?: boolean;
        touch?: boolean;
    }

    // Add other Bootstrap components as needed
}