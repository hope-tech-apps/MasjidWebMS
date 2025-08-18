import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from "node:url";
import autoprefixer from 'autoprefixer';

export default defineConfig({
    plugins: [
        vue(),
        laravel({
            input: ['resources/js/app.js', 'resources/css/app.css'],
            refresh: true,
        })
    ],
    resolve: {
        alias: {
            vue: 'vue/dist/vue.esm-bundler.js',
            '@': fileURLToPath(new URL("./resources/vue-app", import.meta.url))
        }
    },
    define: {
        'process.env': {
            APP_URL: JSON.stringify(process.env.VITE_APP_URL)
        }
    },
    // base: '/admin/'
    // css: {
    //     preprocessorOptions: {
    //         scss: {
    //             additionalData: `@import "resources/sass/app.scss";`
    //         }
    //     }
    // }
    css: {
        postcss: {
          plugins: [
            autoprefixer({
              overrideBrowserslist: [
                "last 2 versions",
                "> 1%",
                "iOS >= 12",
                "Safari >= 12"
              ]
            })
          ]
        }
    }
});
