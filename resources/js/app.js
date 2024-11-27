import "../css/app.css";
import "./bootstrap";
import "primeicons/primeicons.css";

import { createInertiaApp } from "@inertiajs/vue3";
import { resolvePageComponent } from "laravel-vite-plugin/inertia-helpers";
import { createApp, h } from "vue";
import { ZiggyVue } from "../../vendor/tightenco/ziggy";
import PrimeVue from "primevue/config";
import Layout from "./Layouts/App.vue";
import ToastService from "primevue/toastservice";
import Aura from "@primevue/themes/aura";
import { Button } from "@/components/ui/button";
import Loading from "./Components/Loading.vue";
import DivFlexCenter from "./Components/div/DivFlexCenter.vue";
import DivFlexCol from "./Components/div/DivFlexCol.vue";
import { Input } from "@/components/ui/input";
import Table from "./Components/table/Table.vue";
import TH from "./Components/table/TH.vue";
import TD from "./Components/table/TD.vue";
import { Link } from "@inertiajs/vue3";
import TableContainer from "./Components/table/TableContainer.vue";
import { MagnifyingGlassIcon } from "@radix-icons/vue";
import SearchBar from "./Components/table/SearchBar.vue";
import TableHeader from "./Components/table/TableHeader.vue";
import TableHead from "./Components/table/TableHead.vue";
import TableBody from "./Components/table/TableBody.vue";
import {
    Filter,
    Eye,
    Pencil,
    Trash2,
    EllipsisVertical,
    Minus,
    Plus,
} from "lucide-vue-next";
import ConfirmationService from "primevue/confirmationservice";
import { Label } from "@/components/ui/label";
import FormError from "@/Components/FormError.vue";
import Pagination from "./Components/table/Pagination.vue";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogFooter,
} from "@/components/ui/dialog";
import { Badge } from "@/components/ui/badge";
import Textarea from "./Components/ui/textarea/Textarea.vue";
import Select from "primevue/select";
import InputContainer from "./Components/form/InputContainer.vue";
import {
    Card,
    CardContent,
    CardHeader,
    CardDescription,
    CardFooter,
    CardTitle,
} from "@/components/ui/card";
import FilterTab from "./Components/FilterTab.vue";
import FilterTabButton from "./Components/FilterTabButton.vue";
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from "@/components/ui/popover";
import DatePicker from "primevue/datepicker";
import InputLabel from "@/Components/form/InputLabel.vue";
import ShowButton from "./Components/button/ShowButton.vue";

const appName = import.meta.env.VITE_APP_NAME || "Laravel";

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob("./Pages/**/*.vue")
        ),
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .component("Layout", Layout)
            .component("Button", Button)
            .component("Loading", Loading)
            .component("DivFlexCenter", DivFlexCenter)
            .component("DivFlexCol", DivFlexCol)
            .component("Input", Input)
            .component("Table", Table)
            .component("TH", TH)
            .component("TD", TD)
            .component("Link", Link)
            .component("Popover", Popover)
            .component("PopoverContent", PopoverContent)
            .component("PopoverTrigger", PopoverTrigger)
            .component("TableContainer", TableContainer)
            .component("MagnifyingGlassIcon", MagnifyingGlassIcon)
            .component("SearchBar", SearchBar)
            .component("TableHeader", TableHeader)
            .component("TableHead", TableHead)
            .component("TableBody", TableBody)
            .component("Filter", Filter)
            .component("Eye", Eye)
            .component("Pencil", Pencil)
            .component("Trash2", Trash2)
            .component("Label", Label)
            .component("FormError", FormError)
            .component("Pagination", Pagination)
            .component("Dialog", Dialog)
            .component("DialogContent", DialogContent)
            .component("DialogDescription", DialogDescription)
            .component("DialogHeader", DialogHeader)
            .component("DialogTitle", DialogTitle)
            .component("Badge", Badge)
            .component("DialogFooter", DialogFooter)
            .component("Textarea", Textarea)
            .component("Select", Select)
            .component("InputContainer", InputContainer)
            .component("EllipsisVertical", EllipsisVertical)
            .component("Card", Card)
            .component("CardContent", CardContent)
            .component("CardHeader", CardHeader)
            .component("CardFooter", CardFooter)
            .component("CardDescription", CardDescription)
            .component("CardTitle", CardTitle)
            .component("FilterTab", FilterTab)
            .component("FilterTabButton", FilterTabButton)
            .component("DatePicker", DatePicker)
            .component("InputLabel", InputLabel)
            .component("Plus", Plus)
            .component("Minus", Minus)
            .component("ShowButton", ShowButton)
            .use(PrimeVue, {
                theme: {
                    preset: Aura,
                    options: {
                        prefix: "p",
                        darkModeSelector: "system",
                        cssLayer: false,
                    },
                },
            })
            .use(ConfirmationService)
            .use(ToastService)
            .mount(el);
    },
    progress: {
        color: "#4B5563",
    },
});
