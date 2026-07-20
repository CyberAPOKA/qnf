import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig(({ isSsrBuild }) => ({
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            ssr: 'resources/js/ssr.js',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        isSsrBuild !== true && VitePWA({
            buildBase: '/build/',
            base: '/',
            scope: '/',
            registerType: 'autoUpdate',
            injectRegister: null,
            devOptions: {
                enabled: false,
            },
            includeAssets: [],
            workbox: {
                globPatterns: [
                    '**/*.{js,css,ico,png,svg,woff,woff2}',
                ],
                navigateFallback: null,
                cleanupOutdatedCaches: true,
            },
            manifest: {
                id: '/',
                name: 'QNF Futsal',
                short_name: 'QNF',
                description: 'Organização das partidas de futsal do QNF',
                lang: 'pt-BR',
                start_url: '/',
                scope: '/',
                display: 'standalone',
                orientation: 'portrait',
                background_color: '#111111',
                theme_color: '#111111',
                icons: [
                    {
                        src: '/pwa-192x192.png',
                        sizes: '192x192',
                        type: 'image/png',
                    },
                    {
                        src: '/pwa-512x512.png',
                        sizes: '512x512',
                        type: 'image/png',
                        purpose: 'any',
                    },
                    {
                        src: '/maskable-icon-512x512.png',
                        sizes: '512x512',
                        type: 'image/png',
                        purpose: 'maskable',
                    },
                ],
            },
        }),
    ],
}));