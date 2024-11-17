import { router, usePage } from "@inertiajs/vue3";
import { throttle } from "lodash";
import { ref, watch } from "vue";

export function useSearch(routeName) {
    let search = ref(usePage().props.search);
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
            console.log(search);
        }, 500)
    );

    return {
        search,
    };
}
