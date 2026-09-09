import test from 'node:test';
import assert from 'node:assert/strict';
import { guidanceContext, eligiblePeople } from '../resources/js/composables/workflowGuidance.js';

test('completed records do not suggest repeating an action', () => {
    const context = guidanceContext('Wastage/Show', { wastage: { wastage_status: 'approved_lvl2' }, wastageApprovalConfig: { required_levels: 1, show_level2: false } });
    assert.deepEqual(context.current, []);
    assert.match(context.message, /complete/);
    assert.ok(!context.steps.includes('wastage_2'));
});
test('in-flight second level records still require review in one-level mode', () => {
    const context = guidanceContext('Wastage/Show', { wastage: { wastage_status: 'approved_lvl1' }, wastageApprovalConfig: { required_levels: 1, show_level2: true } });
    assert.deepEqual(context.current, ['wastage_2']);
});
test('pending wastage uses the saved approval count even when old work keeps the Level 2 menu visible', () => {
    const props = { wastage: { wastage_status: 'pending' }, wastageApprovalConfig: { required_levels: 1, show_level2: true, has_in_flight_level2_records: true } };
    assert.deepEqual(guidanceContext('Wastage/Show', props).steps, ['wastage', 'wastage_1']);
    assert.deepEqual(guidanceContext('Wastage/Create', { wastageApprovalConfig: props.wastageApprovalConfig }).steps, ['wastage', 'wastage_1']);
    props.wastageApprovalConfig.required_levels = 2;
    assert.deepEqual(guidanceContext('Wastage/Show', props).steps, ['wastage', 'wastage_1', 'wastage_2']);
});
test('one-level setup with no old Level 2 work does not suggest Level 2 approval', () => {
    const context = guidanceContext('WastageApprovalLevel2/Index', { wastageApprovalConfig: { required_levels: 1, has_in_flight_level2_records: false } });
    assert.deepEqual(context.current, []);
    assert.match(context.message, /finishes at Level 1/);
});
test('uploaded MEC is not yet submitted and mixed items retain both handoffs', () => {
    assert.deepEqual(guidanceContext('MonthEndCount/Review', { countItems: [{ status: 'uploaded' }] }).current, ['mec_review']);
    assert.deepEqual(guidanceContext('MonthEndCountApproval/Show', { countItems: [{ status: 'pending_level1_approval' }, { status: 'level1_approved' }] }).current, ['mec_1', 'mec_2']);
});
test('pending receipts direct users to approval instead of receiving again', () => {
    assert.deepEqual(guidanceContext('OrderReceiving/Show', { order: { order_status: 'committed' }, receiveDatesHistory: [{ status: 'pending' }] }).current, ['receipt_approval']);
});
test('responsible people require the selected branch and supplier', () => {
    const definition = { key: 'fg_commit', people: [{ id: 1, branches: [10], suppliers: [20] }, { id: 2, branches: [11], suppliers: [20] }] };
    assert.deepEqual(eligiblePeople(definition, '10', '20').map(person => person.id), [1]);
    assert.deepEqual(eligiblePeople(definition, 10, null), []);
    assert.deepEqual(eligiblePeople(definition, null, 20), []);
});
test('mixed DTS batches use each branch status rather than the first order', () => {
    const props = { batch_number: 'B1', status: 'received', workflowRecords: [
        { store_branch_id: 10, order_status: 'received' },
        { store_branch_id: 11, order_status: 'approved' },
    ] };
    assert.deepEqual(guidanceContext('DTSMassOrders/Show', props).current, ['dts_commit']);
    assert.equal(guidanceContext('DTSMassOrders/Show', props).status, 'mixed');
    assert.equal(guidanceContext('DTSMassOrders/Show', props, 11).status, 'approved');
    assert.match(guidanceContext('DTSMassOrders/Show', props, 10).message, /received/);
    assert.deepEqual(guidanceContext('DTSMassOrders/Show', props, 11).current, ['dts_commit']);
    assert.ok(!guidanceContext('DTSMassOrders/Show', props).steps.includes('order_approval'));
});
