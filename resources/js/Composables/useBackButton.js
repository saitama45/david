import { router } from "@inertiajs/vue3";

export function useBackButton(routeName) {
    const backButton = () => {
        router.get(routeName);
    };

    return {
        backButton,
    };
}
