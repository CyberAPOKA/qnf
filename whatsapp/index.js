import pkg from 'whatsapp-web.js';
import qrcode from 'qrcode-terminal';
import express from 'express';

const { Client, LocalAuth } = pkg;

const PORT = process.env.WHATSAPP_PORT || 3001;

const client = new Client({
    authStrategy: new LocalAuth({ dataPath: './.wwebjs_auth' }),
    puppeteer: {
        headless: true,
        executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || undefined,
        args: ['--no-sandbox', '--disable-setuid-sandbox'],
    },
});

let isReady = false;

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

// Express API
const app = express();
app.use(express.json());

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
        await client.sendMessage(to, message);
        console.log(`Message sent to ${to}`);
        res.json({ success: true });
    } catch (err) {
        console.error('Send failed:', err.message);
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
