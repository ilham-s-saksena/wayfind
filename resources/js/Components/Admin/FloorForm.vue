<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    floor: {
        type: Object,
        default: null,
    },
    building: {
        type: Object,
        required: true,
    },
    submitLabel: {
        type: String,
        default: 'Save',
    },
    cancelRoute: {
        type: String,
        default: '#',
    },
});

const emit = defineEmits(['submit']);

const form = props.floor
    ? {
          name: props.floor.name,
          level: props.floor.level,
      }
    : {
          name: '',
          level: 1,
      };
</script>

<template>
    <form class="space-y-6" @submit.prevent="emit('submit', form)">
        <div>
            <InputLabel for="name" value="Floor name" />
            <TextInput
                id="name"
                v-model="form.name"
                type="text"
                class="mt-1 block w-full"
                required
            />
        </div>

        <div>
            <InputLabel for="level" value="Level" />
            <TextInput
                id="level"
                v-model.number="form.level"
                type="number"
                min="0"
                class="mt-1 block w-full"
                required
            />
        </div>

        <div class="flex items-center justify-end gap-3">
            <Link :href="cancelRoute">
                <SecondaryButton type="button">Cancel</SecondaryButton>
            </Link>
            <PrimaryButton type="submit">{{ submitLabel }}</PrimaryButton>
        </div>
    </form>
</template>
