import './loadEnv.js';
import { execFile } from 'child_process';
import { randomUUID } from 'crypto';
import fs from 'fs';
import os from 'os';
import path from 'path';
import { promisify } from 'util';
import pkg from 'whatsapp-web.js';
import QRCode from 'qrcode';
import qrcode from 'qrcode-terminal';
import express from 'express';
import {
    buildCommandPayload,
    createCommandForwarder,
    isSupportedCommand,
} from './commands.js';

const execFileAsync = promisify(execFile);
const FFMPEG_BIN = process.env.FFMPEG_PATH || 'ffmpeg';

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
let lastQr = null;
let sendQueue = Promise.resolve();

const forwardCommand = createCommandForwarder({
    laravelWebhookUrl: LARAVEL_WEBHOOK_URL,
    webhookSecret: WEBHOOK_SECRET,
    groupId: GROUP_ID,
});

function resolveChromePath() {
    if (process.env.PUPPETEER_EXECUTABLE_PATH) {
        return process.env.PUPPETEER_EXECUTABLE_PATH;
    }

    const candidates = process.platform === 'win32'
        ? [
            'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
            path.join(process.env.LOCALAPPDATA || '', 'Google\\Chrome\\Application\\chrome.exe'),
        ]
        : [
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
        ];

    return candidates.find((candidate) => fs.existsSync(candidate));
}

function createWhatsAppClient() {
    const chromePath = resolveChromePath();

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
            executablePath: chromePath || undefined,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-gpu',
                // Chrome may detach idle frames; keep the WhatsApp Web page alive.
                '--disable-features=IsolateOrigins,site-per-process,MemorySaverMode',
                '--memory-pressure-off',
            ],
        },
    });
}

function attachClientEvents(instance) {
    instance.on('qr', (qr) => {
        lastQr = qr;
        console.log('Scan the QR code below to authenticate:');
        qrcode.generate(qr, { small: true });
        console.log(`Or open http://127.0.0.1:${PORT}/qr in your browser.`);
    });

    instance.on('authenticated', () => {
        console.log('Authenticated successfully.');
    });

    instance.on('auth_failure', (msg) => {
        console.error('Authentication failed:', msg);
    });

    instance.on('ready', () => {
        isReady = true;
        lastQr = null;
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
            }

            if (result?.audio_path) {
                try {
                    await enqueueSend(() =>
                        sendWithRetry(() => sendVoiceNote(chatId, result.audio_path), 'Command voice note'),
                    );
                } finally {
                    if (result.cleanup_audio) {
                        fs.unlink(result.audio_path, () => {});
                    }
                }
            }

            if (!result?.reply && !result?.audio_path) {
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

async function convertToVoiceNote(inputPath) {
    if (!fs.existsSync(inputPath)) {
        throw new Error(`Audio file not found: ${inputPath}`);
    }

    const outputPath = path.join(os.tmpdir(), `qnf-voice-${randomUUID()}.ogg`);

    try {
        await execFileAsync(FFMPEG_BIN, [
            '-y',
            '-i', inputPath,
            '-c:a', 'libopus',
            '-b:a', '32k',
            '-ac', '1',
            '-ar', '48000',
            '-vn',
            outputPath,
        ], { windowsHide: true, timeout: 60000 });
    } catch (err) {
        const details = err?.stderr?.toString().trim() || err.message;
        throw new Error(`FFmpeg voice conversion failed: ${details}`);
    }

    return outputPath;
}

function loadVoiceMedia(oggPath) {
    const data = fs.readFileSync(oggPath).toString('base64');

    return new MessageMedia('audio/ogg; codecs=opus', data, path.basename(oggPath));
}

async function sendVoiceNote(to, audioPath) {
    const oggPath = await convertToVoiceNote(audioPath);

    try {
        const media = loadVoiceMedia(oggPath);

        return await client.sendMessage(to, media, { sendAudioAsVoice: true });
    } finally {
        fs.unlink(oggPath, () => {});
    }
}

// Express API
const app = express();
app.use(express.json({ limit: '2mb' }));

app.get('/status', (_req, res) => {
    res.json({ ready: isReady, hasQr: Boolean(lastQr) });
});

app.get('/qr', async (req, res) => {
    if (isReady) {
        return res
            .type('html')
            .send('<p>WhatsApp já autenticado. Pode fechar esta página.</p>');
    }

    if (!lastQr) {
        return res
            .type('html')
            .send('<p>Aguardando QR code... Atualize em alguns segundos.</p>');
    }

    if (req.query.format === 'png') {
        const png = await QRCode.toBuffer(lastQr, { width: 360, margin: 2 });

        return res.type('png').send(png);
    }

    res.type('html').send(`<!doctype html>
<html lang="pt-BR">
  <body style="font-family:sans-serif;text-align:center;padding:40px">
    <h1>Escaneie o QR do WhatsApp</h1>
    <p>WhatsApp → Aparelhos conectados → Conectar um aparelho</p>
    <img src="/qr?format=png" width="360" height="360" alt="QR code do WhatsApp" />
    <p>Esta página atualiza sozinha a cada 15 segundos.</p>
    <script>setTimeout(() => location.reload(), 15000)</script>
  </body>
</html>`);
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

app.post('/send-audio', async (req, res) => {
    if (!isReady) {
        return res.status(503).json({ error: 'WhatsApp client not ready' });
    }

    const { to, audioPath } = req.body;
    if (!to || !audioPath) {
        return res.status(400).json({ error: 'Missing "to" or "audioPath"' });
    }

    try {
        await enqueueSend(() =>
            sendWithRetry(() => sendVoiceNote(to, audioPath), 'Send voice note'),
        );
        console.log(`Voice note sent to ${to}`);
        res.json({ success: true });
    } catch (err) {
        console.error('Send audio failed:', err.message);
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

let httpServer = null;
let httpRetryTimer = null;

function startHttpServer() {
    if (httpServer) {
        return;
    }

    const server = app.listen(PORT, '127.0.0.1', () => {
        httpServer = server;

        if (httpRetryTimer) {
            clearTimeout(httpRetryTimer);
            httpRetryTimer = null;
        }

        console.log(`WhatsApp service listening on http://127.0.0.1:${PORT}`);
        console.log(
            `Commands: group=${GROUP_ID || '(not set)'} webhook=${LARAVEL_WEBHOOK_URL || '(not set)'} secret=${WEBHOOK_SECRET ? 'yes' : 'no'}`,
        );
    });

    server.on('error', (err) => {
        if (err.code === 'EADDRINUSE') {
            console.error(`Port ${PORT} in use, retrying in 3s...`);

            if (! httpRetryTimer) {
                httpRetryTimer = setTimeout(() => {
                    httpRetryTimer = null;
                    startHttpServer();
                }, 3000);
            }

            return;
        }

        console.error('HTTP server error:', err);
        process.exit(1);
    });
}

startHttpServer();
