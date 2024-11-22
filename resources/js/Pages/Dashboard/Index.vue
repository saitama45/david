<script setup>
import StatisticOverview from "../../Components/dashboard/StatisticOverview.vue";
import Layout from "@/Layouts/App.vue";

</script>
<template>
    <Layout heading="Dashboard">
        <div class="grid gap-4 md:grid-cols-2 md:gap-8 lg:grid-cols-4">
            <StatisticOverview
                heading="Total Revenue"
                value="$45,231.89"
                subheading="+20.1% from last month"
            />
            <StatisticOverview
                heading="Total Revenue"
                value="$45,231.89"
                subheading="+20.1% from last month"
            />
            <StatisticOverview
                heading="Total Revenue"
                value="$45,231.89"
                subheading="+20.1% from last month"
            />
            <StatisticOverview
                heading="Total Revenue"
                value="$45,231.89"
                subheading="+20.1% from last month"
            />
        </div>
    </Layout>
</template>
