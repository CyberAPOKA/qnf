import { computed, ref } from 'vue';

export const MIN_CLIP = 20;
export const MAX_CLIP = 60;
export const DEFAULT_CLIP = 30;
export const CLIP_DURATION_OPTIONS = Array.from({ length: MAX_CLIP - MIN_CLIP + 1 }, (_, index) => MIN_CLIP + index);

export function useMusicClip(getTotalDuration) {
    const startSecond = ref(0);
    const selectedDuration = ref(DEFAULT_CLIP);
    const validationError = ref('');

    const endSecond = computed(() => {
        const total = getTotalDuration();

        if (!total) {
            return startSecond.value + selectedDuration.value;
        }

        return Math.min(total, startSecond.value + selectedDuration.value);
    });

    const clipDuration = computed(() => selectedDuration.value);

    const maxStartSecond = () => {
        const total = getTotalDuration();

        if (!total) {
            return 0;
        }

        return Math.max(0, total - selectedDuration.value);
    };

    const clampStartToBounds = () => {
        startSecond.value = Math.round(Math.max(0, Math.min(startSecond.value, maxStartSecond())));
    };

    const setSelectedDuration = (duration) => {
        selectedDuration.value = Math.round(
            Math.max(MIN_CLIP, Math.min(MAX_CLIP, Number(duration) || DEFAULT_CLIP)),
        );
        clampStartToBounds();
    };

    const setStartPosition = (start) => {
        startSecond.value = Math.round(Math.max(0, Math.min(start, maxStartSecond())));
    };

    const validateSelection = (totalDuration) => {
        if (!totalDuration) {
            validationError.value = 'Selecione uma música.';
            return false;
        }

        const clip = selectedDuration.value;

        if (startSecond.value < 0) {
            validationError.value = 'O início do trecho não pode ser negativo.';
            return false;
        }

        if (endSecond.value > totalDuration) {
            validationError.value = 'O fim do trecho não pode ultrapassar a duração da música.';
            return false;
        }

        if (endSecond.value <= startSecond.value) {
            validationError.value = 'O fim do trecho deve ser maior que o início.';
            return false;
        }

        if (clip < MIN_CLIP || clip > MAX_CLIP) {
            validationError.value = 'O trecho deve ter entre 20 e 60 segundos.';
            return false;
        }

        validationError.value = '';
        return true;
    };

    const resetSegment = () => {
        startSecond.value = 0;
        selectedDuration.value = DEFAULT_CLIP;
        clampStartToBounds();
    };

    const normalizeSegment = (totalDuration) => {
        const total = totalDuration ?? getTotalDuration();

        if (!total) {
            return;
        }

        selectedDuration.value = Math.round(
            Math.max(MIN_CLIP, Math.min(MAX_CLIP, selectedDuration.value)),
        );

        if (selectedDuration.value > total) {
            selectedDuration.value = Math.max(MIN_CLIP, total);
        }

        clampStartToBounds();
    };

    const loadSegment = ({ start_second, end_second }, totalDuration = null) => {
        startSecond.value = start_second ?? 0;

        if (start_second != null && end_second != null) {
            selectedDuration.value = Math.round(
                Math.max(MIN_CLIP, Math.min(MAX_CLIP, end_second - start_second)),
            );
        }

        normalizeSegment(totalDuration);
    };

    return {
        startSecond,
        endSecond,
        selectedDuration,
        clipDuration,
        validationError,
        setSelectedDuration,
        setStartPosition,
        validateSelection,
        resetSegment,
        loadSegment,
        normalizeSegment,
    };
}
