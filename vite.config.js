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
                runtimeCaching: [
                    {
                        // Player/profile photos use hashed filenames on upload — safe to cache long-term.
                        urlPattern: ({ url }) =>
                            url.pathname.startsWith('/storage/players/')
                            || url.pathname.startsWith('/storage/profile-photos/'),
                        handler: 'CacheFirst',
                        options: {
                            cacheName: 'qnf-player-photos',
                            expiration: {
                                maxEntries: 300,
                                maxAgeSeconds: 60 * 60 * 24 * 365,
                            },
                            cacheableResponse: {
                                statuses: [0, 200],
                            },
                        },
                    },
                    {
                        // Brand/static assets under /assets (rarely change).
                        urlPattern: ({ url }) => url.pathname.startsWith('/assets/'),
                        handler: 'CacheFirst',
                        options: {
                            cacheName: 'qnf-static-assets',
                            expiration: {
                                maxEntries: 80,
                                maxAgeSeconds: 60 * 60 * 24 * 30,
                            },
                            cacheableResponse: {
                                statuses: [0, 200],
                            },
                        },
                    },
                    {
                        // Generated media (week_team, ranking, music, etc.) may overwrite the same path.
                        urlPattern: ({ url }) => url.pathname.startsWith('/storage/'),
                        handler: 'StaleWhileRevalidate',
                        options: {
                            cacheName: 'qnf-storage-media',
                            expiration: {
                                maxEntries: 120,
                                maxAgeSeconds: 60 * 60 * 24 * 7,
                            },
                            cacheableResponse: {
                                statuses: [0, 200],
                            },
                        },
                    },
                ],
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