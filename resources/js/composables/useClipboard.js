import { ref } from 'vue';

export function useClipboard() {
    const label = ref('Copiar');
    const copying = ref(false);

    async function copy(text) {
        if (!text || copying.value) return;
        copying.value = true;
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(text);
            } else {
                const textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.style.position = 'fixed';
                textarea.style.left = '-9999px';
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
            }
            label.value = 'Copiado!';
        } catch {
            label.value = 'Erro ao copiar';
        } finally {
            setTimeout(() => {
                label.value = 'Copiar';
                copying.value = false;
            }, 2000);
        }
    }

    return { label, copying, copy };
}
