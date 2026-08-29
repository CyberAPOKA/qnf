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

const execFileAsync = promisify(execFile);
const FFMPEG_BIN = process.env.FFMPEG_PATH || 'ffmpeg';

const { Client, LocalAuth, MessageMedia } = pkg;

const PORT = process.env.WHATSAPP_PORT || 3001;
const MAX_SEND_ATTEMPTS = 3;
const RETRY_DELAY_MS = 1500;

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

const chromePath = resolveChromePath();

const client = new Client({
    // Snap Chromium cannot lock a profile under /var/www; keep the session in $HOME.
    authStrategy: new LocalAuth({
        dataPath: process.env.WWEBJS_AUTH_PATH || './.wwebjs_auth',
    }),
    webVersionCache: {
        type: 'remote',
        remotePath: 'https://raw.githubusercontent.com/nicomeyer96/whatsapp-web.js-version-fix/main/bypass/webVersion',
    },
    puppeteer: {
        headless: true,
        executablePath: chromePath || undefined,
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
let lastQr = null;
let sendQueue = Promise.resolve();

client.on('qr', (qr) => {
    lastQr = qr;
    console.log('Scan the QR code below to authenticate:');
    qrcode.generate(qr, { small: true });
    console.log(`Or open http://127.0.0.1:${PORT}/qr in your browser.`);
});

client.on('authenticated', () => {
    console.log('Authenticated successfully.');
});

client.on('auth_failure', (msg) => {
    console.error('Authentication failed:', msg);
});

client.on('ready', () => {
    isReady = true;
    lastQr = null;
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

app.listen(PORT, '127.0.0.1', () => {
    console.log(`WhatsApp service listening on http://127.0.0.1:${PORT}`);
});
