export function guidanceContext(component, props = {}, branchId = null) {
    const module = component.split('/')[0];
    const record = props.order || props.wastage || (props.batch_number ? { order_status: props.status, variant: 'mass dts' } : null);
    let status = String(record?.interco_status || record?.wastage_status || record?.order_status || '').toLowerCase();
    let steps = [];
    let current = [];
    let message = '';
    if (['MassOrders', 'DTSMassOrders', 'MassOrdersApproval', 'CSMassCommits', 'CSDTSMassCommit', 'CSDTSMassCommits', 'OrderReceiving', 'OrdersReceiving', 'ReceivingApproval'].includes(module)) {
        const dts = module.includes('DTS') || record?.variant === 'mass dts';
        steps = [dts ? 'dts_order' : 'order', ...(dts ? [] : ['order_approval']), ...(dts ? ['dts_commit'] : ['fg_commit', 'other_commit']), 'receive', 'receipt_approval'];
        if (record && status) {
            current = ({ pending: ['order_approval'], approved: dts ? ['dts_commit'] : ['fg_commit', 'other_commit'], partial_committed: dts ? ['dts_commit'] : ['fg_commit', 'other_commit'], committed: ['receive'], incomplete: ['receive'] })[status] || [];
            if (status === 'received') message = 'Receiving is complete. No further receiving action is required for this order.';
            if (status === 'rejected') message = 'This order was rejected. Review the rejection reason and the available correction actions before placing a replacement.';
        }
    } else if (['Interco', 'IntercoApproval', 'StoreCommits', 'IntercoReceiving'].includes(module)) {
        steps = ['interco', 'interco_approval', 'interco_commit', 'interco_receive'];
        if (record) current = ({ open: ['interco_approval'], approved: ['interco_commit'], committed: ['interco_receive'], in_transit: ['interco_receive'] })[status] || [];
        if (status === 'received') message = 'Transfer received. No further receiving action is required.';
        if (status === 'disapproved') message = 'This request was disapproved. Review the reason and available correction actions.';
    } else if (['Wastage', 'WastageApprovalLevel1', 'WastageApprovalLevel2'].includes(module)) {
        const requiredLevels = Number(props.wastageApprovalConfig?.required_levels ?? 2);
        const legacyLevel2 = status === 'approved_lvl1'
            || (!record && module === 'WastageApprovalLevel2' && props.wastageApprovalConfig?.has_in_flight_level2_records);
        steps = ['wastage', 'wastage_1', ...(requiredLevels === 2 || legacyLevel2 ? ['wastage_2'] : [])];
        if (record) current = ({ pending: ['wastage_1'], approved_lvl1: ['wastage_2'] })[status] || [];
        if (status === 'approved_lvl2') message = 'Wastage approval is complete. No further approval is required.';
        if (status === 'cancelled') message = 'This wastage record was cancelled. Review the reason before creating another record.';
        if (!record && module === 'WastageApprovalLevel2' && requiredLevels === 1 && !legacyLevel2) {
            message = 'The current Wastage Settings require one approval level. Wastage approval finishes at Level 1.';
        }
    } else if (['MonthEndCount', 'MonthEndCountApproval', 'MECApproval2'].includes(module)) {
        steps = ['mec', 'mec_review', 'mec_1', 'mec_2'];
        const items = props.countItems || props.items || [];
        if (Array.isArray(items) && items.length) {
            current = [...new Set(items.map(item => ({ uploaded: 'mec_review', pending_level1_approval: 'mec_1', level1_approved: 'mec_2' })[item.status]).filter(Boolean))];
            if (items.every(item => item.status === 'level2_approved')) message = 'Month end count approval is complete.';
        }
    } else if (module === 'StoreTransactions' || module === 'StoreTransaction') {
        steps = ['sales'];
    }
    if (Array.isArray(props.workflowRecords) && props.batch_number) {
        const records = props.workflowRecords.filter(item => !branchId || Number(item.store_branch_id) === Number(branchId));
        const statuses = [...new Set(records.map(item => item.order_status))];
        status = statuses.length > 1 ? 'mixed' : statuses[0] || '';
        current = [...new Set(records.flatMap(item => ({ approved: ['dts_commit'], partial_committed: ['dts_commit'], committed: ['receive'], incomplete: ['receive'] })[item.order_status] || []))];
        message = !records.length ? 'No orders for the selected branch in this batch.'
            : records.every(item => item.order_status === 'received') ? 'All deliveries in this selection are received.'
            : current.length ? '' : 'Review the batch status and order details before further action.';
    }
    if (record && module === 'ReceivingApproval' && props.items?.length) current = ['receipt_approval'];
    if (record && module === 'OrderReceiving' && props.receiveDatesHistory?.some(item => item.status === 'pending')) current = ['receipt_approval'];
    const defaults = {
        MassOrders: ['order'], DTSMassOrders: ['dts_order'], MassOrdersApproval: ['order_approval'],
        CSMassCommits: ['fg_commit', 'other_commit'], CSDTSMassCommits: ['dts_commit'],
        OrderReceiving: ['receive'], ReceivingApproval: ['receipt_approval'],
        Interco: ['interco'], IntercoApproval: ['interco_approval'], StoreCommits: ['interco_commit'], IntercoReceiving: ['interco_receive'],
        Wastage: ['wastage'], WastageApprovalLevel1: ['wastage_1'], WastageApprovalLevel2: ['wastage_2'],
        MonthEndCount: ['mec'], MonthEndCountApproval: ['mec_1'], MECApproval2: ['mec_2'], StoreTransaction: ['sales'],
    };
    return { steps, current, suggested: defaults[module] || steps.slice(0, 1), message, status, record };
}

export function eligiblePeople(definition, branchId, supplierId) {
    if (!branchId) return [];
    return (definition?.people || []).filter(person => person.branches.map(Number).includes(Number(branchId))
        && (!['order', 'fg_commit', 'other_commit'].includes(definition.key)
            || (supplierId && person.suppliers.map(Number).includes(Number(supplierId)))));
}
