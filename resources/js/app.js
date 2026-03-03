import './bootstrap';
import '../css/app.css';
import '@fortawesome/fontawesome-free/css/all.min.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { createPinia } from 'pinia';
import PrimeVue from 'primevue/config';
import Aura from '@primevue/themes/aura';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

const pinia = createPinia();

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(pinia)
            .use(PrimeVue, {
                theme: {
                    preset: Aura,
                    options: { darkModeSelector: false },
                },
            })
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
