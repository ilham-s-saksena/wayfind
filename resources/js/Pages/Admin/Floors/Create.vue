<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloorForm from '@/Components/Admin/FloorForm.vue';
import { Head, router } from '@inertiajs/vue3';

const props = defineProps({
    building: {
        type: Object,
        required: true,
    },
});

const submit = (form) => {
    router.post(route('admin.buildings.floors.store', props.building.id), form);
};
</script>

<template>
    <Head title="New floor" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800">Create floor</h2>
        </template>

        <div class="mx-auto max-w-2xl px-4 py-8 sm:px-6 lg:px-8">
            <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                <FloorForm
                    :building="building"
                    submit-label="Create floor"
                    :cancel-route="route('admin.buildings.show', building.id)"
                    @submit="submit"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
