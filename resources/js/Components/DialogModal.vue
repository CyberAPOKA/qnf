<script setup>
import Modal from './Modal.vue';

const emit = defineEmits(['close']);

defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    maxWidth: {
        type: String,
        default: '2xl',
    },
    closeable: {
        type: Boolean,
        default: true,
    },
});

const close = () => {
    emit('close');
};
</script>

<template>
    <Modal
        :show="show"
        :max-width="maxWidth"
        :closeable="closeable"
        @close="close"
    >
        <div class="flex min-h-0 flex-1 flex-col overflow-hidden">
            <div class="shrink-0 px-6 py-4">
                <div class="text-lg font-medium text-gray-900">
                    <slot name="title" />
                </div>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-6 pb-4 text-sm text-gray-600">
                <slot name="content" />
            </div>

            <div class="flex shrink-0 flex-row justify-end bg-gray-100 px-6 py-4 text-end">
                <slot name="footer" />
            </div>
        </div>
    </Modal>
</template>
