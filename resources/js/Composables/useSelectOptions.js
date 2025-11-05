// resources/js/Composables/useSelectOptions.js
import { computed } from 'vue';

export function useSelectOptions(data) {
    const options = computed(() => {
        // Ensure data is not null or undefined before proceeding
        if (!data) {
            return [];
        }

        // Check if data is already an array of {value, label} objects (like from API)
        if (Array.isArray(data) && data.length > 0 && typeof data[0].value !== 'undefined' && typeof data[0].label !== 'undefined') {
            // Data is already in the correct format, but ensure consistent data types
            return data.map(item => ({
                value: isNaN(Number(item.value)) ? item.value : Number(item.value), // Preserve numeric values
                label: item.label,
            }));
        }

        // Otherwise, assume it's an object from pluck and convert it
        return Object.entries(data).map(([value, label]) => ({
            value: isNaN(Number(value)) ? value : Number(value), // Preserve numeric values instead of converting to string
            label: label,
        }));
    });

    return {
        options,
    };
}





// export function useSelectOptions(data) {
//     const options = computed(() => {
//         return Object.entries(data).map(([value, label]) => ({
//             value: value,
//             label: label,
//         }));
//     });

//     return {
//         options,
//     };
// }
