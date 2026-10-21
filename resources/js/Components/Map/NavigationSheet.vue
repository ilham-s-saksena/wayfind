<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    presentation: { type: String, default: 'half' },
    label: { type: String, default: 'Informasi peta' },
});
const emit = defineEmits(['update:presentation', 'resize']);
const panel = ref(null);
const viewportHeight = ref(typeof window === 'undefined' ? 800 : window.innerHeight);
const desktop = ref(false);
const dragHeight = ref(null);
const heights = computed(() => {
    const maximum = Math.max(200, viewportHeight.value - 88);
    return { collapsed: Math.min(202, maximum), half: Math.min(Math.max(350, viewportHeight.value * 0.53), maximum), expanded: maximum };
});
const height = computed(() => dragHeight.value ?? heights.value[props.presentation]);
let drag = null;
let observer = null;
let media = null;
const updateViewport = () => {
    viewportHeight.value = window.visualViewport?.height ?? window.innerHeight;
    desktop.value = media.matches;
};
const onPointerDown = (event) => {
    if (desktop.value || (event.pointerType === 'mouse' && event.button !== 0)) return;
    drag = { y: event.clientY, height: height.value };
    event.currentTarget.setPointerCapture(event.pointerId);
};
const onPointerMove = (event) => {
    if (!drag) return;
    dragHeight.value = Math.max(heights.value.collapsed, Math.min(heights.value.expanded, drag.height + drag.y - event.clientY));
};
const onPointerUp = () => {
    if (!drag) return;
    const moved = dragHeight.value !== null && Math.abs(dragHeight.value - drag.height) > 5;
    const next = moved
        ? Object.keys(heights.value).sort((a, b) => Math.abs(heights.value[a] - height.value) - Math.abs(heights.value[b] - height.value))[0]
        : props.presentation === 'expanded' ? 'half' : 'expanded';
    emit('update:presentation', next);
    drag = null;
    dragHeight.value = null;
};
const resize = () => emit('resize', desktop.value ? 0 : panel.value?.getBoundingClientRect().height ?? 0);
watch([height, desktop], () => requestAnimationFrame(resize));
onMounted(() => {
    media = window.matchMedia('(min-width: 768px)');
    updateViewport();
    media.addEventListener('change', updateViewport);
    window.visualViewport?.addEventListener('resize', updateViewport);
    window.addEventListener('resize', updateViewport);
    observer = new ResizeObserver(resize);
    observer.observe(panel.value);
});
onBeforeUnmount(() => {
    observer?.disconnect();
    media?.removeEventListener('change', updateViewport);
    window.visualViewport?.removeEventListener('resize', updateViewport);
    window.removeEventListener('resize', updateViewport);
});
</script>

<template>
    <section ref="panel" class="navigation-sheet" :class="{ 'is-dragging': dragHeight !== null, 'is-collapsed': presentation === 'collapsed' && !desktop }" :style="{ '--sheet-height': `${height}px` }" :aria-label="label">
        <button class="sheet-handle" type="button" :aria-label="presentation === 'expanded' ? 'Perkecil panel informasi' : 'Perbesar panel informasi'" :aria-expanded="presentation === 'expanded'" @pointerdown.prevent="onPointerDown" @pointermove="onPointerMove" @pointerup="onPointerUp" @pointercancel="onPointerUp" @keydown.up.prevent="emit('update:presentation', 'expanded')" @keydown.down.prevent="emit('update:presentation', 'collapsed')" @click="(event) => { if (event.detail === 0) emit('update:presentation', presentation === 'expanded' ? 'half' : 'expanded'); }">
            <span></span>
        </button>
        <div class="sheet-header"><slot name="header" /></div>
        <div class="sheet-content" :inert="presentation === 'collapsed' && !desktop ? true : undefined"><slot /></div>
        <div v-if="$slots.footer" class="sheet-footer"><slot name="footer" /></div>
    </section>
</template>

<style scoped>
.navigation-sheet { position: absolute; z-index: 30; inset: auto 0 0; height: calc(var(--sheet-height) + env(safe-area-inset-bottom, 0px)); display: flex; flex-direction: column; overflow: hidden; border: 1px solid #e3e6eb; border-bottom: 0; border-radius: 20px 20px 0 0; background: #fff; box-shadow: 0 -4px 24px #1523380a; transition: height 220ms ease; padding-bottom: env(safe-area-inset-bottom, 0px); }
.navigation-sheet.is-dragging { transition: none; user-select: none; }
.sheet-handle { display: flex; justify-content: center; align-items: center; width: 100%; height: 26px; flex: 0 0 26px; cursor: grab; touch-action: none; }
.sheet-handle span { width: 34px; height: 4px; border-radius: 4px; background: #cbd0d7; }
.sheet-handle:focus-visible { outline: 2px solid #2563eb; outline-offset: -4px; }
.sheet-header { flex-shrink: 0; padding: 0 20px 16px; }
.sheet-content { flex: 1; min-height: 0; overflow: auto; overscroll-behavior: contain; padding: 0 20px 16px; }
.is-collapsed .sheet-content { visibility: hidden; padding: 0; }
.sheet-footer { flex-shrink: 0; max-height: 55%; overflow-y: auto; padding: 14px 20px; border-top: 1px solid #edf0f3; background: white; }
@media (min-width: 768px) {
    .navigation-sheet { inset: 24px auto auto 24px; width: 380px; height: auto; max-height: calc(100dvh - 48px); border: 1px solid #e3e6eb; border-radius: 16px; box-shadow: 0 4px 24px #15233812; padding-bottom: 0; }
    .sheet-handle { display: none; }
    .sheet-header { padding: 22px 22px 18px; }
    .sheet-content { padding: 0 22px 20px; }
    .sheet-footer { padding: 16px 22px; }
}
@media (prefers-reduced-motion: reduce) { .navigation-sheet { transition: none; } }
</style>
