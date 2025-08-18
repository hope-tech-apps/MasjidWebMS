interface ImportMetaEnv {
    VITE_APP_NAME: string;
    VITE_APP_URL: string;
}

interface ImportMeta {
    readonly env: ImportMetaEnv;
}