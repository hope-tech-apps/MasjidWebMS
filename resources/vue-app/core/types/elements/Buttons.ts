export type ButtonType = "submit" | "reset" | "button";

export type ButtonProps = {
    title: string,
    type: ButtonType,
    class: string,
    disabled: boolean
}