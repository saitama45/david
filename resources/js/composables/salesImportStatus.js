export const salesStatusLabel = (status) => ({
    pending: "Queued", processing: "Processing", completed: "Completed",
    completed_with_issues: "Completed", failed: "Failed",
}[status] ?? status);

export const salesStatusClass = (status) => ({
    pending: "bg-gray-100 text-gray-700", processing: "bg-blue-100 text-blue-700",
    completed: "bg-green-100 text-green-700", completed_with_issues: "bg-green-100 text-green-700",
    failed: "bg-red-100 text-red-700",
}[status] ?? "bg-gray-100 text-gray-700");
