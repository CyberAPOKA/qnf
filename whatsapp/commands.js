const COMMAND_PATTERN = /^\/(play|jogar|desistir|quit|commands|comandos|add|remove|lineup)(\s|$)/i;

export function isSupportedCommand(body) {
    return COMMAND_PATTERN.test(String(body || '').trim());
}

export function serializeWhatsAppId(id) {
    if (!id) {
        return null;
    }

    if (typeof id === 'string') {
        return id;
    }

    try {
        if (typeof id._serialized === 'string' && id._serialized !== '') {
            return id._serialized;
        }

        if (typeof id.user === 'string' && id.user !== '') {
            return id.user;
        }
    } catch {
        return null;
    }

    return null;
}

export function phoneFromWhatsAppId(id) {
    const serialized = serializeWhatsAppId(id);

    if (!serialized) {
        return null;
    }

    const user = String(serialized).split('@')[0];

    if (/^\d+$/.test(user)) {
        return user;
    }

    return null;
}

export function isUserContactId(id) {
    const serialized = serializeWhatsAppId(id);

    if (!serialized || serialized.endsWith('@g.us')) {
        return false;
    }

    return serialized.endsWith('@c.us')
        || serialized.endsWith('@lid')
        || /^\d+$/.test(serialized.split('@')[0]);
}

export async function extractPhone(contact) {
    if (!contact) {
        return null;
    }

    if (contact.number && /^\d+$/.test(String(contact.number).replace(/\D/g, ''))) {
        return String(contact.number).replace(/\D/g, '');
    }

    return phoneFromWhatsAppId(contact.id);
}

function resolveAuthorId(msg, client) {
    if (isUserContactId(msg.author)) {
        return serializeWhatsAppId(msg.author);
    }

    if (isUserContactId(msg.from)) {
        return serializeWhatsAppId(msg.from);
    }

    if (msg.fromMe && client?.info?.wid) {
        return serializeWhatsAppId(client.info.wid);
    }

    return serializeWhatsAppId(msg.author) || serializeWhatsAppId(msg.from);
}

async function safeGetContact(client, contactId) {
    const serialized = serializeWhatsAppId(contactId);

    if (!client || !serialized || !serialized.endsWith('@c.us')) {
        return null;
    }

    try {
        return await client.getContactById(serialized);
    } catch {
        return null;
    }
}

export async function buildCommandPayload(msg, client = null) {
    const whatsapp = client || msg.client;
    const sessionId = serializeWhatsAppId(whatsapp?.info?.wid);
    const authorId = msg.fromMe ? sessionId : resolveAuthorId(msg, whatsapp);
    const contact = msg.fromMe ? null : await safeGetContact(whatsapp, authorId);
    const mentionedIds = (Array.isArray(msg.mentionedIds) ? msg.mentionedIds : [])
        .map(serializeWhatsAppId)
        .filter(Boolean);

    const mentionedPhones = [];

    for (const id of mentionedIds) {
        const mention = await safeGetContact(whatsapp, id);
        const phone = (await extractPhone(mention)) || phoneFromWhatsAppId(id);

        if (phone) {
            mentionedPhones.push(phone);
        }
    }

    const authorPhone = phoneFromWhatsAppId(sessionId)
        || (await extractPhone(contact))
        || phoneFromWhatsAppId(authorId);

    return {
        message_id: msg.id?._serialized || '',
        chat_id: msg.fromMe ? (msg.to || msg.from) : msg.from,
        author_id: authorId || '',
        author_phone: authorPhone,
        from_me: Boolean(msg.fromMe),
        body: msg.body || '',
        mentioned_phones: mentionedPhones,
        mentioned_ids: mentionedIds,
    };
}

export function createCommandForwarder({
    laravelWebhookUrl,
    webhookSecret,
    groupId,
    fetchImpl = fetch,
    logger = console,
}) {
    return async function forwardCommand(payload) {
        if (!laravelWebhookUrl || !webhookSecret) {
            logger.error('WhatsApp command webhook is not configured.');
            return null;
        }

        if (groupId && payload.chat_id !== groupId) {
            return null;
        }

        const controller = new AbortController();
        const timeoutMs = Number(process.env.WHATSAPP_WEBHOOK_TIMEOUT_MS || 120000);
        const timeout = setTimeout(() => controller.abort(), timeoutMs);

        try {
            const response = await fetchImpl(laravelWebhookUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Authorization: `Bearer ${webhookSecret}`,
                },
                body: JSON.stringify(payload),
                signal: controller.signal,
            });

            if (!response.ok) {
                logger.error(`Laravel webhook failed with status ${response.status}`);
                return null;
            }

            return await response.json();
        } catch (err) {
            logger.error('Laravel webhook error:', err.message);
            return null;
        } finally {
            clearTimeout(timeout);
        }
    };
}
