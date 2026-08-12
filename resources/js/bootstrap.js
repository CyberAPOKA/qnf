import axios from 'axios';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.axios = axios;
window.Pusher = Pusher;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

const broadcaster = import.meta.env.VITE_BROADCAST_CONNECTION || 'reverb';
const isPusher = broadcaster === 'pusher';

window.Echo = new Echo(
    isPusher
        ? {
              broadcaster: 'pusher',
              key: import.meta.env.VITE_PUSHER_APP_KEY,
              cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER || 'mt1',
              forceTLS: true,
              enabledTransports: ['ws', 'wss'],
          }
        : {
              broadcaster: 'reverb',
              key: import.meta.env.VITE_REVERB_APP_KEY,
              wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
              wsPort: Number(import.meta.env.VITE_REVERB_PORT || 8080),
              wssPort: Number(import.meta.env.VITE_REVERB_PORT || 8080),
              forceTLS: (import.meta.env.VITE_REVERB_SCHEME || 'http') === 'https',
              enabledTransports: ['ws', 'wss'],
          }
);
