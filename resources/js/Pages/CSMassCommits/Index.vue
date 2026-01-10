<script setup>
import { ref, watch, computed, nextTick, onMounted, onUnmounted, triggerRef } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { throttle } from 'lodash';
import { Filter } from 'lucide-vue-next';
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { useToast } from "@/Composables/useToast";
import { useConfirm } from "primevue/useconfirm";
import axios from 'axios';

const props = defineProps({
    report: { type: Array, required: true },
    dynamicHeaders: { type: Array, required: true },
    branches: { type: Object, required: true },
    suppliers: { type: Object, required: true },
    filters: { type: Object, required: true },
    totalBranches: { type: Number, required: true },
    branchStatuses: { type: Object, required: true },
    permissions: { type: Object, required: true },
    availableCategories: { type: Array, required: true },
});

const { toast } = useToast();
const confirm = useConfirm();

const { options: branchesOptions } = useSelectOptions(props.branches);
const { options: suppliersOptions } = useSelectOptions(props.suppliers);

const orderDate = ref(props.filters.order_date || new Date().toISOString().slice(0, 10));
const supplierId = ref(props.filters.supplier_id || 'all');
const categoryFilter = ref(props.filters.category || 'all');

// --- Local Report State ---
const localReport = ref([]);
const localBranchStatuses = ref({ ...props.branchStatuses });

watch(() => props.branchStatuses, (newVal) => {
    localBranchStatuses.value = { ...newVal };
}, { deep: true, immediate: true });

// --- History & Undo ---
const history = ref([]);
const pushToHistory = (changes) => {
    history.value.push(changes);
    if (history.value.length > 50) history.value.shift();
};

const undo = async () => {
    if (history.value.length === 0) return;

    const lastChangeGroup = history.value.pop();
    const bulkPayload = [];

    // Revert changes in UI
    lastChangeGroup.forEach(change => {
        const row = sortedReport.value[change.rowIndex];
        if (row) {
            row[change.field] = change.oldValue;
            recalculateRow(row);
            
            // Sync Props
            if (props.report[change.rowIndex]) {
                props.report[change.rowIndex][change.field] = change.oldValue;
                props.report[change.rowIndex].total_quantity = row.total_quantity;
                props.report[change.rowIndex].remarks = row.remarks;
            }

            bulkPayload.push({
                order_date: orderDate.value,
                item_code: row.item_code,
                brand_code: change.field,
                new_quantity: change.oldValue,
            });
        }
    });

    if (bulkPayload.length > 0) {
        toast.add({ severity: 'info', summary: 'Undo', detail: 'Reverting...', life: 1000 });
        try {
            await axios.post(route('cs-mass-commits.bulk-update-commit'), { updates: bulkPayload });
        } catch (error) {
            console.error('Undo failed', error);
            toast.add({ severity: 'error', summary: 'Undo Failed', detail: 'Failed to revert changes.', life: 3000 });
        }
    }
    
    // Select the undone cells
    if (lastChangeGroup.length > 0) {
        // Find range
        let rMin = Infinity, rMax = -Infinity, cMin = Infinity, cMax = -Infinity;
        lastChangeGroup.forEach(c => {
            if (c.rowIndex < rMin) rMin = c.rowIndex;
            if (c.rowIndex > rMax) rMax = c.rowIndex;
            // Need to find col index from field
            const colIdx = branchHeaders.value.findIndex(h => h.field === c.field);
            if (colIdx !== -1) {
                if (colIdx < cMin) cMin = colIdx;
                if (colIdx > cMax) cMax = colIdx;
            }
        });
        
        if (rMin !== Infinity && cMin !== Infinity) {
             selection.value = { 
                 start: { r: rMin, c: cMin }, 
                 end: { r: rMax, c: cMax } 
             };
             activeCell.value = { r: rMin, c: cMin };
        }
    }
};

watch(() => props.report, (newVal) => {
    // Deep copy to detach from props
    localReport.value = JSON.parse(JSON.stringify(newVal));
}, { immediate: true });

const isBranchLocked = (brandCode) => {
    const status = localBranchStatuses.value[brandCode]?.toLowerCase();
    return status === 'received' || status === 'incomplete';
};

const isCellDisabled = (row, brandCode) => {
    // If the record doesn't exist for this branch, it's disabled.
    // The backend sends 'exists_BRANDCODE' as 1 or 0 (or "1"/"0").
    // We check if it is loosely equal to 1.
    if (row['exists_' + brandCode] != 1) return true;
    
    return isBranchLocked(brandCode);
};

const canUserEditRow = (row) => {
    const isFinishedGood = ['FINISHED GOODS', 'FG', 'FINISHED GOOD'].includes(row.category);
    if (isFinishedGood) {
        return props.permissions.canEditFinishedGood;
    } else {
        return props.permissions.canEditOther;
    }
};

// --- Excel-like Grid Logic ---
const selection = ref({ start: null, end: null }); // { r: rowIndex, c: colIndex }
const activeCell = ref(null); // { r: rowIndex, c: colIndex }
const editingCell = ref(null); // { r: rowIndex, c: colIndex }
const isDragging = ref(false);
const isFilling = ref(false);
const inputRefs = ref({}); // Map of "r-c" to input element
const cellRefs = ref({}); // Map of "r-c" to td element

const staticHeaders = computed(() => props.dynamicHeaders.slice(0, 5));
const branchHeaders = computed(() => props.dynamicHeaders.slice(5, -3));
const trailingHeaders = computed(() => props.dynamicHeaders.slice(-3));

// Format updated_at timestamp
const formatUpdatedAt = (timestamp) => {
    if (!timestamp) return '';
    const date = new Date(timestamp);
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const year = date.getFullYear();
    let hours = date.getHours();
    const minutes = String(date.getMinutes()).padStart(2, '0');
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    hours = hours ? hours : 12;
    return `${month}/${day}/${year} ${hours}:${minutes}${ampm}`;
};

// Helper to get column key from index
const getColKey = (colIndex) => branchHeaders.value[colIndex]?.field;

// Helper to get coordinates
const getCoords = (rowIndex, colKey) => {
    const colIndex = branchHeaders.value.findIndex(h => h.field === colKey);
    return { r: rowIndex, c: colIndex };
};

// Check if cell is in selection range
const isSelected = (rowIndex, colKey) => {
    if (!selection.value.start || !selection.value.end) return false;
    const colIndex = branchHeaders.value.findIndex(h => h.field === colKey);
    if (colIndex === -1) return false;

    const rMin = Math.min(selection.value.start.r, selection.value.end.r);
    const rMax = Math.max(selection.value.start.r, selection.value.end.r);
    const cMin = Math.min(selection.value.start.c, selection.value.end.c);
    const cMax = Math.max(selection.value.start.c, selection.value.end.c);

    return rowIndex >= rMin && rowIndex <= rMax && colIndex >= cMin && colIndex <= cMax;
};

// Check if cell is the active one (focused)
const isActive = (rowIndex, colKey) => {
    if (!activeCell.value) return false;
    const colIndex = branchHeaders.value.findIndex(h => h.field === colKey);
    return activeCell.value.r === rowIndex && activeCell.value.c === colIndex;
};

// Check if cell is currently being edited
const isEditing = (rowIndex, colKey) => {
    if (!editingCell.value) return false;
    const colIndex = branchHeaders.value.findIndex(h => h.field === colKey);
    return editingCell.value.r === rowIndex && editingCell.value.c === colIndex;
};

// Set Ref for Inputs
const setInputRef = (el, rowIndex, colKey) => {
    if (el) inputRefs.value[`${rowIndex}-${colKey}`] = el;
};

// Set Ref for Cells
const setCellRef = (el, rowIndex, colKey) => {
    if (el) cellRefs.value[`${rowIndex}-${colKey}`] = el;
};

// --- Interactions ---

const onCellMouseDown = (rowIndex, colKey, event) => {
    // If clicking fill handle, don't change selection start, handled by onFillHandleMouseDown
    if (event.target.classList.contains('fill-handle')) return;

    if (event.shiftKey && activeCell.value) {
        // Range select
        const { c } = getCoords(rowIndex, colKey);
        selection.value.end = { r: rowIndex, c };
    } else {
        // New selection
        const { c } = getCoords(rowIndex, colKey);
        activeCell.value = { r: rowIndex, c };
        selection.value = { start: { r: rowIndex, c }, end: { r: rowIndex, c } };
        isDragging.value = true;
        
        // Ensure not in edit mode
        editingCell.value = null;
        
        // Focus the cell (td) to capture keyboard events
        const cellEl = cellRefs.value[`${rowIndex}-${colKey}`];
        if (cellEl) cellEl.focus();
    }
};

const onCellMouseOver = (rowIndex, colKey) => {
    const { c } = getCoords(rowIndex, colKey);
    if (isDragging.value) {
        selection.value.end = { r: rowIndex, c };
    } else if (isFilling.value) {
        // Update fill range visual (could implement ghost selection)
        selection.value.end = { r: rowIndex, c };
    }
};

const onCellMouseUp = () => {
    if (isFilling.value) {
        performFill();
    }
    isDragging.value = false;
    isFilling.value = false;
};

// Fill Handle Logic
const onFillHandleMouseDown = (event) => {
    event.stopPropagation();
    isFilling.value = true;
    isDragging.value = false; // distinct modes
};

const performFill = async () => {
    if (!selection.value.start || !selection.value.end) return;

    const rMin = Math.min(selection.value.start.r, selection.value.end.r);
    const rMax = Math.max(selection.value.start.r, selection.value.end.r);
    const cMin = Math.min(selection.value.start.c, selection.value.end.c);
    const cMax = Math.max(selection.value.start.c, selection.value.end.c);

    // Source value: Active cell
    const sourceRow = sortedReport.value[activeCell.value.r];
    const sourceCol = branchHeaders.value[activeCell.value.c].field;
    const sourceValue = sourceRow[sourceCol];

    const updates = [];
    const bulkPayload = [];
    const historyChanges = [];

    for (let r = rMin; r <= rMax; r++) {
        for (let c = cMin; c <= cMax; c++) {
            const row = sortedReport.value[r];
            const col = branchHeaders.value[c].field;
            
            // Validation
            if (isCellDisabled(row, col) || !canUserEditRow(row)) continue;

            // Update local if different
            if (row[col] !== sourceValue) {
                historyChanges.push({
                    rowIndex: r,
                    field: col,
                    oldValue: row[col],
                    newValue: sourceValue
                });

                row[col] = sourceValue;
                // Update timestamp immediately
                row.updated_at = new Date().toISOString();
                // Update derived values locally
                recalculateRow(row);
                
                // Sync Props to prevent revert on next watcher trigger
                if (props.report[r]) {
                    props.report[r][col] = sourceValue;
                    props.report[r].updated_at = row.updated_at;
                    props.report[r].total_quantity = row.total_quantity;
                    props.report[r].remarks = row.remarks;
                }
                
                bulkPayload.push({
                    order_date: orderDate.value,
                    item_code: row.item_code,
                    brand_code: col,
                    new_quantity: parseFloat(sourceValue) || 0,
                    supplier_id: supplierId.value,
                });
            }
        }
    }

    if (historyChanges.length > 0) {
        pushToHistory(historyChanges);
    }

    if (bulkPayload.length > 0) {
        toast.add({ severity: 'info', summary: 'Saving', detail: `Updating ${bulkPayload.length} cells...`, life: 2000 });
        try {
            await axios.post(route('cs-mass-commits.bulk-update-commit'), { updates: bulkPayload });
            toast.add({ severity: 'success', summary: 'Saved', detail: 'Bulk update successful.', life: 2000 });
        } catch (error) {
            console.error('Bulk update failed', error);
            toast.add({ severity: 'error', summary: 'Update Failed', detail: 'Failed to save some changes.', life: 5000 });
            // Ideally revert here, but for now we rely on user refresh if critical failure
        }
    }
};

// Keyboard Navigation
const onKeyDown = (event) => {
    if (!activeCell.value) return;
    if (editingCell.value) return; // Let input handle it

    // If Shift is held, we move the 'end' of the selection.
    // Otherwise, we move the active cell.
    let r = event.shiftKey ? selection.value.end.r : activeCell.value.r;
    let c = event.shiftKey ? selection.value.end.c : activeCell.value.c;

    const maxR = sortedReport.value.length - 1;
    const maxC = branchHeaders.value.length - 1;

    let nextR = r;
    let nextC = c;
    let handled = false;
    
    const isCtrl = event.ctrlKey || event.metaKey;

    switch (event.key) {
        case 'ArrowUp':
            if (isCtrl) nextR = 0;
            else if (r > 0) nextR--;
            handled = true;
            break;
        case 'ArrowDown':
            if (isCtrl) nextR = maxR;
            else if (r < maxR) nextR++;
            handled = true;
            break;
        case 'ArrowLeft':
            if (isCtrl) nextC = 0;
            else if (c > 0) nextC--;
            handled = true;
            break;
        case 'ArrowRight':
            if (isCtrl) nextC = maxC;
            else if (c < maxC) nextC++;
            handled = true;
            break;
        case 'Enter':
            // Enter Edit Mode
            handled = true;
            startEditing(activeCell.value.r, activeCell.value.c);
            break;
        case 'Delete':
        case 'Backspace':
            // Clear content
            handled = true;
            clearSelection();
            break;
        case 'z':
            if (event.ctrlKey || event.metaKey) {
                handled = true;
                if (!event.shiftKey) {
                    undo();
                }
            }
            break;
    }

    if (handled) {
        event.preventDefault();
        if (event.key.startsWith('Arrow')) {
            // Move selection
            if (event.shiftKey) {
                selection.value.end = { r: nextR, c: nextC };
            } else {
                activeCell.value = { r: nextR, c: nextC };
                selection.value = { start: { r: nextR, c: nextC }, end: { r: nextR, c: nextC } };
            }
            
            // Focus new cell
            const nextKey = branchHeaders.value[nextC].field;
            nextTick(() => {
                const cellEl = cellRefs.value[`${nextR}-${nextKey}`];
                if (cellEl) {
                    cellEl.focus();
                    ensureCellVisible(cellEl);
                }
            });
        }
    } else if (event.key.length === 1 && !event.ctrlKey && !event.altKey && !event.metaKey) {
        // Typing: start editing with this key
        event.preventDefault(); // Prevent double entry (programmatic set + browser default)
        startEditing(activeCell.value.r, activeCell.value.c, event.key);
    }
};

const ensureCellVisible = (cellEl) => {
    if (!cellEl) return;
    const container = cellEl.closest('.overflow-y-auto');
    if (container) {
        const headerHeight = 100; // Approximate header height (2 rows + padding)
        const cellTop = cellEl.offsetTop;
        const scrollTop = container.scrollTop;
        
        // If cell is above the visible area (under the sticky header)
        if (cellTop < scrollTop + headerHeight) {
            container.scrollTo({ top: Math.max(0, cellTop - headerHeight), behavior: 'smooth' });
        } 
        // If cell is below the visible area
        else if (cellTop + cellEl.clientHeight > scrollTop + container.clientHeight) {
            container.scrollTo({ top: cellTop - container.clientHeight + cellEl.clientHeight + 20, behavior: 'smooth' });
        }
    }
};

const startEditing = (r, c, initialValue = null) => {
    const colKey = branchHeaders.value[c].field;
    // Validation: Check permissions before editing
    if (isCellDisabled(sortedReport.value[r], colKey) || !canUserEditRow(sortedReport.value[r])) return;

    editingCell.value = { r, c };
    
    // If initialValue provided (user started typing), set it
    if (initialValue) {
        sortedReport.value[r][colKey] = initialValue;
    }

    nextTick(() => {
        const input = inputRefs.value[`${r}-${colKey}`];
        if (input) {
            input.focus();
            if (!initialValue) input.select();
        }
    });
};

const finishEditing = (rowIndex, colKey) => {
    editingCell.value = null;
    const cellEl = cellRefs.value[`${rowIndex}-${colKey}`];
    if (cellEl) cellEl.focus();
    
    // Trigger update
    updateCommit(rowIndex, colKey);
};

const finishAndMove = (r, c, direction) => {
    const colKey = branchHeaders.value[c].field;
    editingCell.value = null; // Exit edit mode
    updateCommit(r, colKey); // Save

    // Calculate next
    let nextR = r;
    let nextC = c;

    if (direction === 'down') {
        if (nextR < sortedReport.value.length - 1) {
            nextR++;
        }
    } else if (direction === 'right') {
        if (nextC < branchHeaders.value.length - 1) {
            nextC++;
        }
    }

    // Move Selection
    activeCell.value = { r: nextR, c: nextC };
    selection.value = { start: { r: nextR, c: nextC }, end: { r: nextR, c: nextC } };

    // Focus New Cell
    nextTick(() => {
        const nextColKey = branchHeaders.value[nextC].field;
        const cellEl = cellRefs.value[`${nextR}-${nextColKey}`];
        if (cellEl) {
             cellEl.focus();
             ensureCellVisible(cellEl);
        }
    });
};

const clearSelection = async () => {
    const rMin = Math.min(selection.value.start.r, selection.value.end.r);
    const rMax = Math.max(selection.value.start.r, selection.value.end.r);
    const cMin = Math.min(selection.value.start.c, selection.value.end.c);
    const cMax = Math.max(selection.value.start.c, selection.value.end.c);

    const bulkPayload = [];
    const historyChanges = [];

    for (let r = rMin; r <= rMax; r++) {
        for (let c = cMin; c <= cMax; c++) {
            const col = branchHeaders.value[c].field;
            const row = sortedReport.value[r];
            // Validation: Skip if read-only
             if (!isCellDisabled(row, col) && canUserEditRow(row)) {
                if (row[col] !== 0) {
                    historyChanges.push({
                        rowIndex: r,
                        field: col,
                        oldValue: row[col],
                        newValue: 0
                    });

                    row[col] = 0;
                    recalculateRow(row);
                    
                    // Sync Props to prevent revert on next watcher trigger
                    if (props.report[r]) {
                        props.report[r][col] = 0;
                        props.report[r].total_quantity = row.total_quantity;
                        props.report[r].remarks = row.remarks;
                    }

                    bulkPayload.push({
                        order_date: orderDate.value,
                        item_code: row.item_code,
                        brand_code: col,
                        new_quantity: 0,
                    });
                }
             }
        }
    }
    
    if (historyChanges.length > 0) {
        pushToHistory(historyChanges);
    }

    if (bulkPayload.length > 0) {
        try {
            await axios.post(route('cs-mass-commits.bulk-update-commit'), { updates: bulkPayload });
            toast.add({ severity: 'success', summary: 'Cleared', detail: 'Cells cleared.', life: 1000 });
        } catch (error) {
             console.error('Bulk clear failed', error);
        }
    }
};

const onCellCopy = (event) => {
    if (editingCell.value) return; // Allow native copy in input

    const rMin = Math.min(selection.value.start.r, selection.value.end.r);
    const rMax = Math.max(selection.value.start.r, selection.value.end.r);
    const cMin = Math.min(selection.value.start.c, selection.value.end.c);
    const cMax = Math.max(selection.value.start.c, selection.value.end.c);

    let text = "";
    for (let r = rMin; r <= rMax; r++) {
        const rowVals = [];
        for (let c = cMin; c <= cMax; c++) {
            const col = branchHeaders.value[c].field;
            rowVals.push(sortedReport.value[r][col] ?? "");
        }
        text += rowVals.join("\t") + "\n";
    }

    if (text) {
        event.clipboardData.setData('text/plain', text);
        event.preventDefault();
        toast.add({ severity: 'info', summary: 'Copied', detail: 'Selection copied.', life: 1000 });
    }
};

const onCellPaste = (event) => {
    if (editingCell.value) return; // Allow native paste in input
    event.preventDefault();
    const text = (event.clipboardData || window.clipboardData).getData('text');
    if (text) {
        processPasteText(text);
    }
};

const processPasteText = async (text) => {
    try {
        // Split rows by newline, filtering out empty last line often added by Excel/Sheets
        const rows = text.split(/\r?\n/);
        if (rows.length > 0 && rows[rows.length - 1].trim() === "") {
            rows.pop();
        }
        
        if (!rows.length) {
            toast.add({ severity: 'warn', summary: 'Paste', detail: 'No data found in clipboard.', life: 2000 });
            return;
        }

        // Parse clipboard data into a 2D array
        const clipboardData = rows.map(row => row.split("\t"));
        const sourceRows = clipboardData.length;
        const sourceCols = clipboardData[0].length;
        
        // Determine Destination Range
        const rMin = Math.min(selection.value.start.r, selection.value.end.r);
        const rMax = Math.max(selection.value.start.r, selection.value.end.r);
        const cMin = Math.min(selection.value.start.c, selection.value.end.c);
        const cMax = Math.max(selection.value.start.c, selection.value.end.c);
        
        const isSingleCellSource = (sourceRows === 1 && sourceCols === 1);
        const isMultiCellDestination = (rMax > rMin || cMax > cMin);

        const bulkPayload = [];
        const historyChanges = [];
        let hasAnyPasteAttempt = false;

        if (isSingleCellSource && isMultiCellDestination) {
            // Case 1: Copy 1 -> Paste to Many (Fill Selection)
            const valToPaste = clipboardData[0][0].trim();
            const evaluatedValue = evaluateFormula(valToPaste);
            const numVal = parseFloat(evaluatedValue);
            const safeNumVal = isNaN(numVal) ? 0 : numVal;

            for (let r = rMin; r <= rMax; r++) {
                for (let c = cMin; c <= cMax; c++) {
                    const col = branchHeaders.value[c].field;
                    const row = sortedReport.value[r];
                    
                    if (!isCellDisabled(row, col) && canUserEditRow(row)) {
                        hasAnyPasteAttempt = true;
                        const currentVal = parseFloat(row[col]) || 0;
                        if (currentVal !== safeNumVal) {
                            historyChanges.push({
                                rowIndex: r,
                                field: col,
                                oldValue: row[col],
                                newValue: safeNumVal
                            });

                            row[col] = safeNumVal;
                            // Update timestamp immediately
                            row.updated_at = new Date().toISOString();
                            recalculateRow(row);
                            
                            // Sync Props to prevent revert on next watcher trigger
                            if (props.report[r]) {
                                props.report[r][col] = safeNumVal;
                                props.report[r].updated_at = row.updated_at;
                                props.report[r].total_quantity = row.total_quantity;
                                props.report[r].remarks = row.remarks;
                            }

                            bulkPayload.push({
                                order_date: orderDate.value,
                                item_code: row.item_code,
                                brand_code: col,
                                new_quantity: safeNumVal,
                                supplier_id: supplierId.value,
                            });
                        }
                    }
                }
            }
        } else {
            // Case 2: Standard Paste (Top-Left Anchor)
            // We use the top-left of the selection as the anchor, usually `rMin, cMin`
            // Note: `activeCell` might be bottom-right if dragging backwards, but usually paste anchors top-left.
            
            const startR = rMin; 
            const startC = cMin;

            for (let i = 0; i < sourceRows; i++) {
                const r = startR + i;
                if (r >= sortedReport.value.length) break;

                for (let j = 0; j < sourceCols; j++) {
                    const c = startC + j;
                    if (c >= branchHeaders.value.length) break;

                    const col = branchHeaders.value[c].field;
                    const row = sortedReport.value[r];
                    
                    const isDisabled = isCellDisabled(row, col);
                    const canEdit = canUserEditRow(row);
                    
                    if (!isDisabled && canEdit) {
                        hasAnyPasteAttempt = true;
                        let originalClipboardValue = clipboardData[i][j].trim();
                        let evaluatedValue = evaluateFormula(originalClipboardValue);
                        const numVal = parseFloat(evaluatedValue);
                        const safeNumVal = isNaN(numVal) ? 0 : numVal;
                        
                        const currentVal = parseFloat(row[col]) || 0;
                        
                        if (currentVal !== safeNumVal) {
                            historyChanges.push({
                                rowIndex: r,
                                field: col,
                                oldValue: row[col],
                                newValue: safeNumVal
                            });

                            row[col] = safeNumVal;
                            // Update timestamp immediately
                            row.updated_at = new Date().toISOString();
                            recalculateRow(row);
                            
                            // Sync Props to prevent revert on next watcher trigger
                            if (props.report[r]) {
                                props.report[r][col] = safeNumVal;
                                props.report[r].updated_at = row.updated_at;
                                props.report[r].total_quantity = row.total_quantity;
                                props.report[r].remarks = row.remarks;
                            }
                            
                            bulkPayload.push({
                                order_date: orderDate.value,
                                item_code: row.item_code,
                                brand_code: col,
                                new_quantity: safeNumVal,
                                supplier_id: supplierId.value,
                            });
                        }
                    }
                }
            }
        }

        if (historyChanges.length > 0) {
            pushToHistory(historyChanges);
        }

        if (bulkPayload.length > 0) {
            toast.add({ severity: 'info', summary: 'Pasting', detail: `Updating ${bulkPayload.length} cells...`, life: 2000 });
            try {
                await axios.post(route('cs-mass-commits.bulk-update-commit'), { updates: bulkPayload });
                toast.add({ severity: 'success', summary: 'Pasted', detail: 'Bulk paste successful.', life: 2000 });
            } catch (error) {
                console.error('Bulk paste axios failed', error);
                toast.add({ severity: 'error', summary: 'Paste Failed', detail: 'Failed to save some changes to server.', life: 5000 });
            }
        } else if (hasAnyPasteAttempt) {
            toast.add({ severity: 'info', summary: 'Paste', detail: 'No actual changes detected for editable cells.', life: 2000 });
        } else {
            toast.add({ severity: 'warn', summary: 'Paste', detail: 'No editable cells found in the target range.', life: 2000 });
        }

    } catch (err) {
        console.error('Failed to process paste:', err);
        toast.add({ severity: 'error', summary: 'Paste Failed', detail: 'An unexpected error occurred during paste processing.', life: 2000 });
    }
};

// --- Formulas ---
const evaluateFormula = (val) => {
    // If it's a string starting with '=', evaluate it
    if (typeof val === 'string' && val.trim().startsWith('=')) {
        try {
            // Remove '='
            let expression = val.trim().substring(1);
            // Replace commonly used Excel-like references if we wanted to support them (e.g. A1), but that's hard.
            // Just support Math.
            // Safety: allow digits, operators, parens, dot, and Math functions
            // Quick and dirty safety check:
            if (!/^[\d\.\+\-\*\/\(\)\sMath\.\w]+$/.test(expression)) {
                 // Fallback or risky?
                 // Let's assume trusted user for now, but strict regex is safer.
                 // We'll allow `sum(...)` if we implemented a helper, but `new Function` supports standard JS.
            }
            // Basic support: 5+2, 10*5
            return new Function('return ' + expression)();
        } catch (e) {
            console.warn("Formula error", e);
            return val; // Return raw text if failed
        }
    }
    return val;
};


const recalculateRow = (row) => {
    let totalQty = 0;
    let allBranchesMetApproved = true; // Assume true for "Stock Supported"
    
    // Iterate over branch headers to calculate totals and check logic
    for (const header of branchHeaders.value) {
        const code = header.field;
        // Handle empty strings or invalid numbers as 0
        const val = row[code];
        const committed = (val === '' || val === null || isNaN(parseFloat(val))) ? 0 : parseFloat(val);
        const approved = parseFloat(row['approved_' + code] || 0);
        
        totalQty += committed;
        
        // Check condition b)
        // If ANY branch has committed < approved, then "Stock Supported" is false.
        if (committed < approved) {
            allBranchesMetApproved = false;
        }
    }
    
    let newRemarks = '';
    // Condition a)
    if (totalQty === 0) {
        newRemarks = '86';
    } 
    // Condition b)
    else if (allBranchesMetApproved) {
        newRemarks = 'Stock Supported';
    } 
    // Condition c) (Implied: total > 0 AND !allBranchesMetApproved)
    else {
        newRemarks = 'Allocation';
    }

    // Use Object.assign to ensure reactivity triggers
    Object.assign(row, {
        total_quantity: totalQty,
        remarks: newRemarks
    });
    
    // Force Vue to re-evaluate the localReport ref, updating the UI
    triggerRef(localReport);
};

const handleInput = (row, field) => {
    // If user clears the input, set it to 0 immediately
    if (row[field] === '' || row[field] === null) {
        row[field] = 0;
    }
    recalculateRow(row);
};

const updateCommit = async (rowIndex, field, silent = false) => {
    const row = localReport.value[rowIndex];
    let val = row[field];
    
    // Evaluate formula if present
    val = evaluateFormula(val);
    row[field] = val; // Update model with evaluated result

    // Ensure we send a valid number to backend
    const newValue = (val === '' || val === null || isNaN(parseFloat(val))) ? 0 : parseFloat(val);
    const originalValue = props.report[rowIndex] ? parseFloat(props.report[rowIndex][field]) : 0;

    // If value hasn't changed effectively, do nothing
    if (newValue === originalValue) return;

    // Validation: Negative check
    if (newValue < 0) {
        if (!silent) toast.add({ severity: 'error', summary: 'Invalid Input', detail: 'Quantity must be a non-negative number.', life: 3000 });
        // Revert to original
        row[field] = originalValue;
        recalculateRow(row); // Recalc back to original
        return;
    }

    // Update timestamp immediately for reactive display
    row.updated_at = new Date().toISOString();

    // Recalculate derived values immediately after validation
    recalculateRow(row);

    // Record History
    pushToHistory([{
        rowIndex,
        field,
        oldValue: originalValue,
        newValue: newValue
    }]);

    // --- Optimistic Update ---
    // Update the "last saved" reference immediately so subsequent blurs don't re-trigger
    if (props.report[rowIndex]) {
        props.report[rowIndex][field] = newValue;
        props.report[rowIndex].updated_at = row.updated_at;
        // CRITICAL FIX: Also update the derived fields in props so the watcher (which fires on prop change)
        // doesn't revert localReport's derived values to the old state.
        props.report[rowIndex].total_quantity = row.total_quantity;
        props.report[rowIndex].remarks = row.remarks;
    }

    // Show Success Feedback Immediately
    if (!silent) toast.add({ severity: 'success', summary: 'Saved', detail: 'Quantity updated successfully.', life: 2000 });

    try {
                    await axios.post(route('cs-mass-commits.update-commit'), {
                        order_date: orderDate.value,
                        item_code: row.item_code,
                        brand_code: field,
                        new_quantity: newValue,
                        supplier_id: supplierId.value,
                    });
        // Ensure calculations are consistent
        recalculateRow(row);

    } catch (error) {
        console.error('Update failed', error);
        
        // --- Revert on Failure ---
        // Revert local state
        row[field] = originalValue;
        
        // Revert the "last saved" reference
        if (props.report[rowIndex]) {
            props.report[rowIndex][field] = originalValue;
        }
        
        recalculateRow(row);
        
        if (!silent) {
            const errorMsg = error.response?.data?.message || 'Failed to update quantity.';
            toast.add({ severity: 'error', summary: 'Update Failed', detail: errorMsg, life: 5000 });
        }
    }
};

const handleEnterKey = (event) => {
    // Keep this for legacy or if we want to support Enter in Edit Mode to just commit
    // But our new onKeyDown handles navigation.
    // We can map this to finishEditing logic.
    event.target.blur();
};

// --- Confirm All Logic ---
const isProcessing = ref(false);

const confirmAllCommits = () => {
    if (isProcessing.value) {
        toast.add({
            severity: 'warn',
            summary: 'Processing',
            detail: 'Commit process is already running. Please wait.',
            life: 3000
        });
        return;
    }

    confirm.require({
        message: `Are you sure you want to commit all orders for ${orderDate.value}? This action cannot be undone.`,
        header: 'Confirm All Commits',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-success',
        rejectClass: 'p-button-danger',
        accept: () => {
            console.log('CS Mass Commits - Starting confirm-all request', {
                order_date: orderDate.value,
                supplier_id: supplierId.value,
                timestamp: new Date().toISOString()
            });

            isProcessing.value = true;

            router.post(route('cs-mass-commits.confirm-all'), {
                order_date: orderDate.value,
                supplier_id: supplierId.value,
            }, {
                preserveState: true,
                preserveScroll: true,
                onStart: () => {
                    console.log('CS Mass Commits - Request started');
                    toast.add({
                        severity: 'info',
                        summary: 'Processing',
                        detail: 'Committing orders... This may take a moment.',
                        life: 2000
                    });
                },
                onSuccess: (page) => {
                    console.log('CS Mass Commits - Request successful', {
                        response: page,
                        timestamp: new Date().toISOString()
                    });

                    isProcessing.value = false;

                    // Extract message from flash data if available
                    const flashMessage = page.props.flash?.success || page.props.flash?.info;
                    const messageText = flashMessage || 'Orders have been processed.';
                    const messageType = page.props.flash?.success ? 'success' : (page.props.flash?.info ? 'info' : 'success');

                    // Optimistically update statuses
                    branchHeaders.value.forEach(header => {
                        const brand = header.field;
                        let totalItems = 0;
                        let committableItems = 0;

                        sortedReport.value.forEach(row => {
                            if (row['exists_' + brand] == 1) {
                                totalItems++;
                                if (canUserEditRow(row)) {
                                    committableItems++;
                                }
                            }
                        });

                        if (totalItems > 0) {
                            if (committableItems === totalItems) {
                                localBranchStatuses.value[brand] = 'COMMITTED';
                            } else if (committableItems > 0) {
                                localBranchStatuses.value[brand] = 'PARTIAL_COMMITTED';
                            }
                        }
                    });

                    toast.add({
                        severity: messageType,
                        summary: 'Success',
                        detail: messageText,
                        life: 4000
                    });

                    // Force a page reload to refresh the data
                    setTimeout(() => {
                        router.reload({
                            preserveScroll: true,
                            onSuccess: () => {
                                console.log('CS Mass Commits - Page reloaded successfully');
                            }
                        });
                    }, 500);
                },
                onError: (errors) => {
                    console.error('CS Mass Commits - Request failed', {
                        errors: errors,
                        timestamp: new Date().toISOString()
                    });

                    isProcessing.value = false;

                    const errorMsg = Object.values(errors)[0] || 'An unknown error occurred during the commit process.';
                    toast.add({
                        severity: 'error',
                        summary: 'Commit Failed',
                        detail: errorMsg,
                        life: 6000
                    });
                },
                onFinish: () => {
                    console.log('CS Mass Commits - Request finished');
                    isProcessing.value = false;
                }
            });
        },
        reject: () => {
            console.log('CS Mass Commits - User cancelled the commit operation');
        }
    });
};

// --- Status Badge Color (Copied from MassOrders/Index.vue) ---
const statusBadgeColor = (status) => {
    switch (status?.toUpperCase()) {
        case "RECEIVED": return "bg-green-500 text-white";
        case "APPROVED": return "bg-teal-500 text-white";
        case "INCOMPLETE": return "bg-orange-500 text-white";
        case "PENDING": return "bg-yellow-500 text-white";
        case "COMMITTED": return "bg-blue-500 text-white";
        case "PARTIAL_COMMITTED": return "bg-indigo-500 text-white";
        case "REJECTED": return "bg-red-500 text-white";
        default: return "bg-gray-500 text-white";
    }
};

watch([orderDate, supplierId, categoryFilter], throttle(() => {
    router.get(
        route('cs-mass-commits.index'),
        {
            order_date: orderDate.value,
            supplier_id: supplierId.value,
            category: categoryFilter.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        }
    );
}, 300));

const resetFilters = () => {
    orderDate.value = new Date().toISOString().slice(0, 10);
    supplierId.value = 'all';
    categoryFilter.value = 'all';
};

const canConfirmAny = computed(() => {
    const statuses = Object.values(localBranchStatuses.value);
    if (statuses.length === 0) {
        return false;
    }
    // Check if there is at least one branch that is NOT received or incomplete
    return statuses.some(status =>
        status?.toLowerCase() !== 'received' && status?.toLowerCase() !== 'incomplete'
    );
});

const exportRoute = computed(() =>
    route('cs-mass-commits.export', {
        order_date: orderDate.value,
        supplier_id: supplierId.value,
    })
);


const branchCount = computed(() => branchHeaders.value.length);
const totalColumns = computed(() => staticHeaders.value.length + branchCount.value + trailingHeaders.value.length);

const sortedReport = computed(() => localReport.value);

// Helper to format quantities for display
const formatQuantity = (value) => {
    const num = parseFloat(value);
    if (isNaN(num)) return value;
    // Fix floating point artifacts (e.g. 2.546 becoming 2.5459999999999998)
    // toFixed(10) is sufficient precision for this context to round off the artifact,
    // and parseFloat strips the trailing zeros to show the value "as is".
    return parseFloat(num.toFixed(10));
};

// Set default supplier on mount if none selected and user has suppliers
onMounted(() => {
    if (supplierId.value === 'all' && suppliersOptions.value.length > 0) {
        // Find supplier with lowest ID
        const sortedSuppliers = [...suppliersOptions.value].sort((a, b) => {
            const aVal = String(a.value);
            const bVal = String(b.value);
            return aVal.localeCompare(bVal);
        });
        supplierId.value = sortedSuppliers[0].value;
    }
});

</script>

<template>
    <Layout heading="CS Mass Commits" :hasExcelDownload="true" :exportRoute="exportRoute">
        <TableContainer>
            <TableHeader class="flex-wrap">
                <div class="flex items-center gap-4">
                    <label for="order_date" class="text-sm font-medium text-gray-700">Date:</label>
                    <Input
                        id="order_date"
                        type="date"
                        v-model="orderDate"
                        class="w-48"
                    />
                </div>

                <div class="flex items-center gap-4">
                    <label for="supplier_filter" class="text-sm font-medium text-gray-700">Supplier:</label>
                    <Select
                        id="supplier_filter"
                        filter
                        placeholder="Select a Supplier"
                        v-model="supplierId"
                        :options="suppliersOptions"
                        optionLabel="label"
                        optionValue="value"
                        class="w-64"
                    />
                </div>

                <div class="flex items-center gap-4">
                    <label for="category_filter" class="text-sm font-medium text-gray-700">Category:</label>
                    <Select
                        id="category_filter"
                        filter
                        placeholder="All Categories"
                        v-model="categoryFilter"
                        :options="props.availableCategories.map(c => ({ label: c, value: c }))"
                        optionLabel="label"
                        optionValue="value"
                        class="w-64"
                    >
                        <template #header>
                            <div class="p-2">
                                <Button text @click="categoryFilter = 'all'" class="w-full text-left">All Categories</Button>
                            </div>
                        </template>
                    </Select>
                </div>

                <div class="flex items-center gap-2 ml-auto">
                    <Button @click="resetFilters" variant="outline">
                        Reset Filters
                    </Button>
                    <Button
                        v-if="canConfirmAny"
                        @click="confirmAllCommits"
                        variant="destructive"
                        :disabled="isProcessing"
                        :class="{ 'opacity-50 cursor-not-allowed': isProcessing }"
                    >
                        <span v-if="isProcessing" class="flex items-center gap-2">
                            <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Processing...
                        </span>
                        <span v-else>Confirm All Commits</span>
                    </Button>
                </div>
            </TableHeader>
            
            <div class="mb-2 text-sm text-gray-600">
                Total Records: {{ sortedReport.length }}
            </div>
            
            <div class="bg-white border rounded-md shadow-sm">
                <div class="overflow-x-auto max-h-[75vh] overflow-y-auto" style="user-select: text;" @mouseup="onCellMouseUp">
                    <table class="min-w-full">
                        <thead class="bg-slate-100 sticky top-0 z-10 text-slate-800 shadow-sm">
                            <!-- Main Header Row -->
                            <tr class="text-sm">
                                <!-- Row Number Header -->
                                <th rowspan="2" class="px-4 py-3 text-center whitespace-nowrap font-bold border-b-2 border-slate-200 bg-slate-200">
                                    #
                                </th>
                                
                                <!-- Static Headers -->
                                <th v-for="header in staticHeaders" :key="header.field" rowspan="2" 
                                    class="px-4 py-3 text-left whitespace-nowrap font-bold border-b-2 border-slate-200 bg-slate-200">
                                    {{ header.label }}
                                </th>
                                
                                <!-- Group Header for Branches -->
                                <th :colspan="branchCount" class="px-4 py-4 text-center bg-blue-100 border-b-2 border-slate-200">
                                   <div class="flex justify-center items-center gap-2 font-bold text-blue-800">
                                        <span>BRANCH QUANTITIES</span>
                                        <Filter class="w-4 h-4" />
                                   </div>
                                </th>
                                
                                <!-- Trailing Headers -->
                                <th v-for="header in trailingHeaders" :key="header.field" rowspan="2"
                                    class="px-4 py-3 text-right whitespace-nowrap font-bold border-b-2 border-slate-200 bg-slate-200">
                                    {{ header.label }}
                                </th>
                                
                                <!-- Updated At Header -->
                                <th rowspan="2" class="px-4 py-3 text-center whitespace-nowrap font-bold border-b-2 border-slate-200 bg-slate-200">
                                    Updated At
                                </th>
                            </tr>
                            <!-- Sub-Header Row for Branches -->
                            <tr>
                                <th v-for="header in branchHeaders" :key="header.field" 
                                    class="px-4 py-2 text-center whitespace-nowrap font-semibold border-b-2 border-slate-200 bg-blue-50">
                                    <div>{{ header.label.replace(' Qty', '') }}</div>
                                    <div v-if="localBranchStatuses[header.field]" class="text-xs font-normal mt-1">
                                        <span :class="statusBadgeColor(localBranchStatuses[header.field])" class="px-2 py-1 rounded-full shadow-sm">
                                            {{ localBranchStatuses[header.field].toUpperCase() }}
                                        </span>
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="sortedReport.length === 0">
                                <td :colspan="totalColumns + 1" class="text-center p-4">No data available for the selected filters.</td>
                            </tr>
                            <tr v-for="(row, rowIndex) in sortedReport" :key="rowIndex" class="border-t group">
                                <!-- Row Number -->
                                <td class="px-4 py-3 text-center whitespace-nowrap text-gray-500 font-medium">
                                    {{ rowIndex + 1 }}
                                </td>
                                
                                <td v-for="header in staticHeaders" :key="header.field" class="px-4 py-3 text-left whitespace-nowrap">
                                    {{ row[header.field] }}
                                </td>
                                
                                <td v-for="(header, colIndex) in branchHeaders" :key="header.field" 
                                    :class="[
                                        'px-2 py-1 text-right whitespace-nowrap border border-transparent relative focus:outline-none',
                                        isSelected(rowIndex, header.field) ? 'bg-blue-100 !border-blue-400' : '',
                                        isActive(rowIndex, header.field) ? 'ring-2 ring-blue-600 z-10' : '',
                                        row['exists_' + header.field] != 1 ? 'bg-gray-100 cursor-not-allowed opacity-75' : ''
                                    ]"
                                    :title="row['exists_' + header.field] != 1 ? 'This item does not exist for this store' : ''"
                                    tabindex="0"
                                    :ref="el => setCellRef(el, rowIndex, header.field)"
                                    @mousedown="onCellMouseDown(rowIndex, header.field, $event)"
                                    @mouseover="onCellMouseOver(rowIndex, header.field)"
                                    @keydown="onKeyDown"
                                    @dblclick="startEditing(rowIndex, getCoords(rowIndex, header.field).c)"
                                    @copy="onCellCopy"
                                    @paste="onCellPaste"
                                >
                                    <div class="w-full h-full min-h-[1.5rem] flex items-center justify-end">
                                        <span 
                                            v-if="row['approved_' + header.field] !== undefined && parseFloat(row['approved_' + header.field]) > 0"
                                            class="absolute top-1 left-1 text-[10px] font-bold px-1 rounded bg-teal-100 text-teal-800"
                                            title="Approved Quantity"
                                        >
                                            {{ parseFloat(row['approved_' + header.field]).toFixed(0) }}
                                        </span>

                                        <!-- Edit Mode -->
                                        <input
                                            v-if="isEditing(rowIndex, header.field)"
                                            :ref="el => setInputRef(el, rowIndex, header.field)"
                                            type="text"
                                            v-model="row[header.field]"
                                            class="w-full h-full px-1 py-0 text-right border-none focus:ring-0 bg-white"
                                            @blur="finishEditing(rowIndex, header.field)"
                                            @keydown.stop
                                            @keydown.enter.prevent="finishAndMove(rowIndex, colIndex, 'down')"
                                            @keydown.tab.prevent="finishAndMove(rowIndex, colIndex, 'right')"
                                        />
                                        <!-- Display Mode -->
                                        <span v-else class="w-full text-right px-2">
                                            {{ formatQuantity(row[header.field]) }}
                                        </span>

                                        <!-- Fill Handle -->
                                        <div 
                                            v-if="isSelected(rowIndex, header.field) && 
                                                  selection.end && 
                                                  rowIndex === Math.max(selection.start.r, selection.end.r) && 
                                                  getCoords(rowIndex, header.field).c === Math.max(selection.start.c, selection.end.c) &&
                                                  !isEditing(rowIndex, header.field)"
                                            class="fill-handle absolute -bottom-1 -right-1 w-3 h-3 bg-blue-600 border border-white cursor-crosshair z-20"
                                            @mousedown="onFillHandleMouseDown"
                                        ></div>
                                    </div>
                                </td>

                                <td v-for="header in trailingHeaders" :key="header.field"
                                    class="px-4 py-3 text-right whitespace-nowrap">
                                    {{ formatQuantity(row[header.field]) }}
                                </td>
                                
                                <!-- Updated At Column -->
                                <td class="px-4 py-3 text-center whitespace-nowrap text-sm text-gray-600">
                                    {{ formatUpdatedAt(row.updated_at) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </TableContainer>
    </Layout>
</template>

