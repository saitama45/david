import { router, usePage } from "@inertiajs/vue3";
import { throttle } from "lodash";

export function useSearch(routeName) {
    let search = ref(usePage().props.filters.search);
    watch(
        search,
        throttle(function (value) {
            router.get(
                route(routeName),
                { search: value },
                {
                    preserveState: true,
                    replace: true,
                }
            );
        }, 500)
    );

    return {
        search,
    };
}
