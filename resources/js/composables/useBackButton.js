import { usePage, router } from "@inertiajs/vue3";

export function useBackButton(routeName) {
    const backButton = () => {
        router.get(usePage().props.previous);
    };

    return {
        backButton,
    };
}
