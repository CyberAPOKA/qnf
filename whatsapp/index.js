import pkg from 'whatsapp-web.js';
import qrcode from 'qrcode-terminal';
import express from 'express';

const { Client, LocalAuth, MessageMedia } = pkg;

const PORT = process.env.WHATSAPP_PORT || 3001;
const MAX_SEND_ATTEMPTS = 3;
const RETRY_DELAY_MS = 1500;

const client = new Client({
    authStrategy: new LocalAuth({ dataPath: './.wwebjs_auth' }),
    webVersionCache: {
        type: 'remote',
        remotePath: 'https://raw.githubusercontent.com/nicomeyer96/whatsapp-web.js-version-fix/main/bypass/webVersion',
    },
    puppeteer: {
        headless: true,
        executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || undefined,
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            // Chrome may detach idle frames; keep the WhatsApp Web page alive.
            '--disable-features=IsolateOrigins,site-per-process,MemorySaverMode',
            '--memory-pressure-off',
            '--disable-dev-shm-usage',
        ],
    },
});

let isReady = false;
let sendQueue = Promise.resolve();

client.on('qr', (qr) => {
    console.log('Scan the QR code below to authenticate:');
    qrcode.generate(qr, { small: true });
});

client.on('authenticated', () => {
    console.log('Authenticated successfully.');
});

client.on('auth_failure', (msg) => {
    console.error('Authentication failed:', msg);
});

client.on('ready', () => {
    isReady = true;
    console.log('WhatsApp client ready!');
});

client.on('disconnected', (reason) => {
    isReady = false;
    console.log('Client disconnected:', reason);
});

client.initialize();

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
});
