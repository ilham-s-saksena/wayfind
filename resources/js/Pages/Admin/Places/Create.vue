<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PlaceForm from '@/Components/Admin/PlaceForm.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    building: {
        type: Object,
        required: true,
    },
    floor: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const errors = computed(() => page.props.errors ?? {});

const submit = (form) => {
    router.post(route('admin.floors.places.store', props.floor.id), form, {
        preserveScroll: true,
        onError: () => {
            // Inertia exposes the backend validation messages in page.props.errors.
        },
    });
};
</script>

<template>
    <Head title="New place" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <div>
                    <div class="text-sm font-medium uppercase tracking-wide text-indigo-600">Add place</div>
                    <h2 class="text-xl font-semibold text-gray-800">{{ building.name }} · {{ floor.name }}</h2>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
            <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                <PlaceForm
                    :building="building"
                    :floor="floor"
                    :errors="errors"
                    submit-label="Create place"
                    :cancel-route="route('admin.floors.places.index', floor.id)"
                    @submit="submit"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
