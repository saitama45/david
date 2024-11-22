import { computed } from "vue";

export function useSelectOptions(data) {
    const options = computed(() => {
        return Object.entries(data).map(([value, label]) => ({
            value: value,
            label: label,
        }));
    });

    return {
        options,
    };
}
