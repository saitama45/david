<script setup>
import { ref, computed } from "vue";
import { useForm, usePage, Head } from "@inertiajs/vue3";
import axios from "axios";
import Button from "primevue/button";
import { Send, Eye, EyeOff, MailWarning, PlugZap, BellRing } from "lucide-vue-next";
import { useToast } from "@/composables/useToast";

// Administration > Email Notifications: the no-reply mailbox the system sends its notification
// emails from, and which notifications are on. The password never comes back to the page.
const props = defineProps({
    settings: { type: Object, required: true },
    encryptions: { type: Array, default: () => ["tls", "ssl", "none"] },
    recipientFields: { type: Array, default: () => [] },
});

const { toast } = useToast();

const form = useForm({
    from_name: props.settings.from_name,
    from_address: props.settings.from_address,
    driver: props.settings.driver || "smtp",
    host: props.settings.host,
    port: props.settings.port || 587,
    encryption: props.settings.encryption || "tls",
    username: props.settings.username,
    password: "",
    commit_variance_enabled: Boolean(props.settings.commit_variance_enabled),
});

const showPassword = ref(false);
const isSmtp = computed(() => form.driver === "smtp");
const encryptionLabel = { tls: "TLS", ssl: "SSL", none: "None" };

const save = () => {
    form.post(route("email-notifications.update"), {
        preserveScroll: true,
        onSuccess: () => {
            form.password = "";
            toast.add({ severity: "success", summary: "Saved", detail: usePage().props.flash?.success || "Email notification settings saved.", life: 3000 });
        },
    });
};

// --- Test Connection: the account as typed, saved or not ---
const testing = ref(false);
const testResult = ref(null);
const sendTestTo = ref("");

const testConnection = async () => {
    testing.value = true;
    testResult.value = null;
    try {
        const { data } = await axios.post(route("email-notifications.test"), { ...form.data(), send_test_to: sendTestTo.value || null });
        testResult.value = data;
    } catch (error) {
        const errors = error.response?.data?.errors;
        testResult.value = error.response?.data?.message && !errors
            ? { ok: false, message: error.response.data.message }
            : { ok: false, message: errors ? Object.values(errors).map((list) => list[0]).join(" ") : "The test could not be run." };
    } finally {
        testing.value = false;
    }
};

const inputClass = "flex h-10 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm";
</script>

<template>
    <Head title="Email Notifications" />

    <Layout heading="Email Notifications">
        <div class="max-w-5xl space-y-6">
            <!-- What this mailbox is -->
            <div class="flex gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-900" data-testid="no-reply-notice">
                <MailWarning class="size-6 shrink-0" />
                <div class="text-sm space-y-1">
                    <p class="font-semibold text-base">This is a NO-REPLY mailbox.</p>
                    <p>
                        The account below only <strong>sends</strong> automatic notification emails from DAVID. Nobody reads its inbox,
                        so use an address meant for that, such as <strong>no-reply@yourcompany.com</strong>, not a person's mailbox.
                        Every email says it is a no-reply email.
                    </p>
                </div>
            </div>

            <!-- Outbound mail -->
            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="mb-1 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-green-700"><Send class="size-4" /> Outbound Mail (SMTP)</h2>
                        <p class="mt-1 text-xs italic text-gray-500">For Gmail accounts, generate and use a 16-character App Password.</p>
                    </div>
                    <Button type="button" severity="secondary" outlined :disabled="testing" @click="testConnection">
                        <PlugZap class="size-4 mr-2" /> {{ testing ? "Testing…" : "Test Connection" }}
                    </Button>
                </div>

                <div v-if="testResult" class="mt-3 rounded-md px-3 py-2 text-sm" :class="testResult.ok ? 'bg-green-50 text-green-800 border border-green-200' : 'bg-red-50 text-red-800 border border-red-200'" data-testid="test-result">
                    {{ testResult.message }}
                </div>

                <hr class="my-5 border-gray-100" />

                <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                    <div>
                        <label for="from_name" class="mb-1 block text-sm font-medium text-gray-700">Sender Display Name <span class="text-red-500">*</span></label>
                        <input id="from_name" v-model="form.from_name" type="text" maxlength="100" :class="inputClass" placeholder="DAVID Notifications (no-reply)" />
                        <p v-if="form.errors.from_name" class="mt-1 text-sm text-red-600">{{ form.errors.from_name }}</p>
                    </div>
                    <div>
                        <label for="from_address" class="mb-1 block text-sm font-medium text-gray-700">Sender Email Address (no-reply) <span class="text-red-500">*</span></label>
                        <input id="from_address" v-model="form.from_address" type="email" maxlength="255" :class="inputClass" placeholder="no-reply@yourcompany.com" />
                        <p v-if="form.errors.from_address" class="mt-1 text-sm text-red-600">{{ form.errors.from_address }}</p>
                    </div>

                    <div>
                        <label for="driver" class="mb-1 block text-sm font-medium text-gray-700">Mail Driver</label>
                        <select id="driver" v-model="form.driver" :class="inputClass">
                            <option value="smtp">SMTP</option>
                            <option value="log">Log only (nothing is sent, for trying it out)</option>
                        </select>
                    </div>
                    <div>
                        <label for="host" class="mb-1 block text-sm font-medium text-gray-700">SMTP Host <span v-if="isSmtp" class="text-red-500">*</span></label>
                        <input id="host" v-model="form.host" type="text" :disabled="!isSmtp" :class="[inputClass, !isSmtp && 'bg-gray-50']" placeholder="smtp.gmail.com" />
                        <p v-if="form.errors.host" class="mt-1 text-sm text-red-600">{{ form.errors.host }}</p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="port" class="mb-1 block text-sm font-medium text-gray-700">Port <span v-if="isSmtp" class="text-red-500">*</span></label>
                            <input id="port" v-model.number="form.port" type="number" min="1" max="65535" :disabled="!isSmtp" :class="[inputClass, !isSmtp && 'bg-gray-50']" />
                            <p v-if="form.errors.port" class="mt-1 text-sm text-red-600">{{ form.errors.port }}</p>
                        </div>
                        <div>
                            <label for="encryption" class="mb-1 block text-sm font-medium text-gray-700">Encryption</label>
                            <select id="encryption" v-model="form.encryption" :disabled="!isSmtp" :class="[inputClass, !isSmtp && 'bg-gray-50']">
                                <option v-for="value in encryptions" :key="value" :value="value">{{ encryptionLabel[value] ?? value }}</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label for="username" class="mb-1 block text-sm font-medium text-gray-700">SMTP Username</label>
                        <input id="username" v-model="form.username" type="text" autocomplete="off" :disabled="!isSmtp" :class="[inputClass, !isSmtp && 'bg-gray-50']" placeholder="no-reply@yourcompany.com" />
                    </div>

                    <div class="md:col-span-2">
                        <label for="password" class="mb-1 block text-sm font-medium text-gray-700">SMTP Password</label>
                        <div class="relative">
                            <input
                                id="password"
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                autocomplete="new-password"
                                :disabled="!isSmtp"
                                :class="[inputClass, 'pr-10', !isSmtp && 'bg-gray-50']"
                                :placeholder="settings.has_password ? 'Saved. Leave blank to keep it, or type a new one.' : 'Type the password or App Password'"
                            />
                            <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600" :title="showPassword ? 'Hide' : 'Show'" @click="showPassword = !showPassword">
                                <EyeOff v-if="showPassword" class="size-4" /><Eye v-else class="size-4" />
                            </button>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">Stored encrypted. It is never shown again after saving.</p>
                    </div>

                    <div class="md:col-span-2">
                        <label for="send_test_to" class="mb-1 block text-sm font-medium text-gray-700">Send a test email to <span class="text-xs text-gray-400">(optional, for Test Connection)</span></label>
                        <input id="send_test_to" v-model="sendTestTo" type="email" :class="inputClass" placeholder="you@yourcompany.com" />
                    </div>
                </div>
            </div>

            <!-- Notifications -->
            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="mb-4 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-blue-700"><BellRing class="size-4" /> Notifications</h2>

                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-4 hover:bg-gray-50">
                    <input v-model="form.commit_variance_enabled" type="checkbox" class="mt-1 size-4" data-testid="commit-variance-switch" />
                    <span class="text-sm">
                        <span class="block font-semibold text-gray-900">Commit variance</span>
                        <span class="block text-gray-600">
                            When CS confirms a commit in <strong>CS Mass Commits</strong> (Confirm All) at a quantity different from what the store
                            ordered, the store gets an email listing <strong>only the items with a variance</strong>: Item Code, Item Name, UOM,
                            Ordered, Committed and Variance. A quantity already emailed is not emailed again; a changed one is.
                        </span>
                        <span class="mt-2 block text-gray-600">
                            Sent to the store's
                            <strong>{{ recipientFields.join(", ") }}</strong>
                            in <strong>Store Branches</strong>. A store with none of them filled in gets no email.
                        </span>
                    </span>
                </label>
            </div>

            <div class="flex justify-end">
                <Button type="button" :disabled="form.processing" @click="save">Save Settings</Button>
            </div>
        </div>
    </Layout>
</template>
