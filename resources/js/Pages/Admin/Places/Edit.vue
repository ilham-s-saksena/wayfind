<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PlaceForm from '@/Components/Admin/PlaceForm.vue';
import { Head, router } from '@inertiajs/vue3';

const props = defineProps({
    floor: {
        type: Object,
        required: true,
    },
    place: {
        type: Object,
        required: true,
    },
});

const submit = (form) => {
    router.put(route('admin.floors.places.update', [props.floor.id, props.place.id]), form);
};
</script>

<template>
    <Head :title="`Edit ${place.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800">Edit place</h2>
        </template>

        <div class="mx-auto max-w-2xl px-4 py-8 sm:px-6 lg:px-8">
            <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                <PlaceForm
                    :place="place"
                    submit-label="Update place"
                    :cancel-route="route('admin.floors.places.index', floor.id)"
                    @submit="submit"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
