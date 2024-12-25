import { router, usePage } from "@inertiajs/vue3";
import { throttle } from "lodash";

export function useSearch(routeName, id) {
    console.log(usePage().props);
    let search = ref(usePage().props.filters.search);
    watch(
        search,
        throttle(function (value) {
            router.get(
                route(routeName, id),
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
