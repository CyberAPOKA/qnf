const VIDEO_ID_PATTERN = /^[a-zA-Z0-9_-]{11}$/;

/**
 * Extrai o ID de um vídeo a partir de uma URL do YouTube ou do próprio ID.
 */
export function extractYouTubeVideoId(input) {
    const trimmed = input.trim();

    if (! trimmed) {
        return null;
    }

    if (VIDEO_ID_PATTERN.test(trimmed)) {
        return trimmed;
    }

    const normalized = trimmed.startsWith('http') ? trimmed : `https://${trimmed}`;

    try {
        const url = new URL(normalized);
        const host = url.hostname.replace(/^www\./, '');

        if (host === 'youtu.be') {
            const id = url.pathname.split('/').filter(Boolean)[0];

            return VIDEO_ID_PATTERN.test(id ?? '') ? id : null;
        }

        if (host === 'youtube.com' || host === 'm.youtube.com' || host === 'music.youtube.com') {
            const fromQuery = url.searchParams.get('v');

            if (fromQuery && VIDEO_ID_PATTERN.test(fromQuery)) {
                return fromQuery;
            }

            const pathMatch = url.pathname.match(/^\/(?:embed|shorts|v|live)\/([a-zA-Z0-9_-]{11})/);

            if (pathMatch) {
                return pathMatch[1];
            }
        }
    } catch {
        return null;
    }

    return null;
}

export function isYouTubeUrl(input) {
    return extractYouTubeVideoId(input) !== null;
}
