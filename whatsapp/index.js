import './loadEnv.js';
import pkg from 'whatsapp-web.js';
import qrcode from 'qrcode-terminal';
import express from 'express';
import {
    buildCommandPayload,
    createCommandForwarder,
    isSupportedCommand,
} from './commands.js';

const { Client, LocalAuth, MessageMedia } = pkg;

const PORT = process.env.WHATSAPP_PORT || 3001;
const MAX_SEND_ATTEMPTS = 3;
const MAX_INIT_ATTEMPTS = 3;
const RETRY_DELAY_MS = 1500;
const GROUP_ID = process.env.WHATSAPP_GROUP_ID || '';
const LARAVEL_WEBHOOK_URL = process.env.WHATSAPP_LARAVEL_WEBHOOK_URL || '';
const WEBHOOK_SECRET = process.env.WHATSAPP_WEBHOOK_SECRET || '';

let client = null;
let isReady = false;
let sendQueue = Promise.resolve();

const forwardCommand = createCommandForwarder({
    laravelWebhookUrl: LARAVEL_WEBHOOK_URL,
    webhookSecret: WEBHOOK_SECRET,
    groupId: GROUP_ID,
});

function createWhatsAppClient() {
    return new Client({
        // Snap Chromium cannot lock a profile under /var/www; keep the session in $HOME.
        authStrategy: new LocalAuth({
            dataPath: process.env.WWEBJS_AUTH_PATH || './.wwebjs_auth',
        }),
        webVersionCache: {
            type: 'local',
        },
        puppeteer: {
            headless: true,
            protocolTimeout: 120000,
            executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || undefined,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-gpu',
            ],
        },
    });
}

function attachClientEvents(instance) {
    instance.on('qr', (qr) => {
        console.log('Scan the QR code below to authenticate:');
        qrcode.generate(qr, { small: true });
    });

    instance.on('authenticated', () => {
        console.log('Authenticated successfully.');
    });

    instance.on('auth_failure', (msg) => {
        console.error('Authentication failed:', msg);
    });

    instance.on('ready', () => {
        isReady = true;
        console.log('WhatsApp client ready!');
    });

    instance.on('disconnected', (reason) => {
        isReady = false;
        console.log('Client disconnected:', reason);
    });

    instance.on('message_create', async (msg) => {
        try {
            if (msg.isStatus || !isSupportedCommand(msg.body)) {
                return;
            }

            const chatId = msg.fromMe ? msg.to : msg.from;
            if (GROUP_ID && chatId !== GROUP_ID) {
                console.log(`Ignoring command from other chat: ${chatId}`);
                return;
            }

            console.log(`Command received: ${String(msg.body).trim()} chat=${chatId} fromMe=${Boolean(msg.fromMe)}`);

            const payload = await buildCommandPayload(msg, client);
            payload.chat_id = chatId;
            payload.from_me = false;

            const result = await forwardCommand(payload);

            if (result?.reply) {
                await enqueueSend(() =>
                    sendWithRetry(() => client.sendMessage(chatId, result.reply), 'Command reply'),
                );
            } else {
                console.log('No reply from Laravel for this command.', result);
            }
        } catch (err) {
            console.error('Command handling failed:', err.message);
        }
    });
}

async function startWhatsAppClient() {
    for (let attempt = 1; attempt <= MAX_INIT_ATTEMPTS; attempt++) {
        if (client) {
            isReady = false;
            try {
                await client.destroy();
            } catch {
                // The previous browser may already be gone after a crashed inject().
            }
            client = null;
        }

        client = createWhatsAppClient();
        attachClientEvents(client);

        try {
            await client.initialize();
            return;
        } catch (err) {
            console.error(
                `WhatsApp initialize failed (attempt ${attempt}/${MAX_INIT_ATTEMPTS}):`,
                err.message,
            );

            if (attempt === MAX_INIT_ATTEMPTS) {
                console.error(
                    'Could not start WhatsApp Web. Delete whatsapp/.wwebjs_auth and whatsapp/.wwebjs_cache if they exist, then run again.',
                );
                return;
            }

            await sleep(RETRY_DELAY_MS * attempt);
        }
    }
}

startWhatsAppClient();

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function isTransientPuppeteerError(err) {
    const message = err?.message || String(err);
    return /detached Frame|Execution context was destroyed|Session closed|Target closed|Protocol error/i.test(message);
}

/**
 * Serialize all sends through one queue — concurrent Puppeteer evaluate()
 * calls are a common trigger for detached Frame errors.
 */
function enqueueSend(task) {
    const run = sendQueue.then(task, task);
    sendQueue = run.catch(() => {});
    return run;
}

async function sendWithRetry(fn, label) {
    let lastError;

    for (let attempt = 1; attempt <= MAX_SEND_ATTEMPTS; attempt++) {
        if (!isReady) {
            throw new Error('WhatsApp client not ready');
        }

        try {
            return await fn();
        } catch (err) {
            lastError = err;
            const transient = isTransientPuppeteerError(err);
            console.error(
                `${label} failed (attempt ${attempt}/${MAX_SEND_ATTEMPTS}):`,
                err.message,
            );

            if (!transient || attempt === MAX_SEND_ATTEMPTS) {
                break;
            }

            await sleep(RETRY_DELAY_MS * attempt);
        }
    }

    throw lastError;
}

// Express API
const app = express();
app.use(express.json({ limit: '2mb' }));

app.get('/status', (_req, res) => {
    res.json({ ready: isReady });
});

app.post('/send', async (req, res) => {
    if (!isReady) {
        return res.status(503).json({ error: 'WhatsApp client not ready' });
    }

    const { to, message } = req.body;
    if (!to || !message) {
        return res.status(400).json({ error: 'Missing "to" or "message"' });
    }

    try {
        await enqueueSend(() =>
            sendWithRetry(() => client.sendMessage(to, message), 'Send'),
        );
        console.log(`Message sent to ${to}`);
        res.json({ success: true });
    } catch (err) {
        console.error('Send failed:', err.message);
        res.status(500).json({ error: err.message });
    }
});

app.post('/send-image', async (req, res) => {
    if (!isReady) {
        return res.status(503).json({ error: 'WhatsApp client not ready' });
    }

    const { to, imagePath, caption } = req.body;
    if (!to || !imagePath) {
        return res.status(400).json({ error: 'Missing "to" or "imagePath"' });
    }

    try {
        await enqueueSend(() =>
            sendWithRetry(() => {
                const media = MessageMedia.fromFilePath(imagePath);
                return client.sendMessage(to, media, { caption: caption || '' });
            }, 'Send image'),
        );
        console.log(`Image sent to ${to}`);
        res.json({ success: true });
    } catch (err) {
        console.error('Send image failed:', err.message);
        res.status(500).json({ error: err.message });
    }
});

app.get('/groups', async (_req, res) => {
    if (!isReady) {
        return res.status(503).json({ error: 'WhatsApp client not ready' });
    }

    try {
        const chats = await client.getChats();
        const groups = chats
            .filter((c) => c.isGroup)
            .map((c) => ({ id: c.id._serialized, name: c.name }));
        res.json(groups);
    } catch (err) {
        res.status(500).json({ error: err.message });
    }
});

app.listen(PORT, '127.0.0.1', () => {
    console.log(`WhatsApp service listening on http://127.0.0.1:${PORT}`);
    console.log(
        `Commands: group=${GROUP_ID || '(not set)'} webhook=${LARAVEL_WEBHOOK_URL || '(not set)'} secret=${WEBHOOK_SECRET ? 'yes' : 'no'}`,
    );
});
