<?php

/*
|--------------------------------------------------------------------------
| Business-rule exception registry
|--------------------------------------------------------------------------
|
| One entry per rule a store user may ask to be excepted from.
|
| type:
|   unlock - the function is blocked by the server; an approved request is a
|            one-time grant consumed by the transaction it was raised for.
|   excuse - the function is not blocked; the item is scored late in the
|            Adoption Rate report. An approved request marks it Excused.
|
| request_permissions: the requester must hold all of these (the permission to
|   perform the blocked function) and be assigned to the store.
| approve_permission: the module's existing approver permission. The approver
|   must also be assigned to the store and may never be the requester.
|
| Validity (unlock only), in hours: what the approver may grant, further capped
| by the subject itself (see RuleExceptionService::maxValidUntil()).
|
| Only time rules are waivable. Status, permission, entity/store scope,
| delivery-schedule and stock rules are never lifted by an exception.
*/

return [
    'rules' => [
        'mec.upload_window' => [
            'module' => 'month_end_count',
            'type' => 'unlock',
            'label' => 'Month End Count upload after the window closed',
            'request_permissions' => ['perform month end count'],
            'approve_permission' => 'approve month end count level 1',
            'default_validity_hours' => 24,
            'max_validity_hours' => 72,
        ],
        'mass_order.late_order' => [
            'module' => 'mass_orders',
            'type' => 'unlock',
            'label' => 'Mass order after the ordering cutoff',
            'request_permissions' => ['create mass orders'],
            'approve_permission' => 'approve mass order',
            'default_validity_hours' => 12,
            'max_validity_hours' => 48,
        ],
        'mass_order.edit_after_cutoff' => [
            'module' => 'mass_orders',
            'type' => 'unlock',
            'label' => 'Edit a mass order after its cutoff',
            'request_permissions' => ['edit mass orders'],
            'approve_permission' => 'approve mass order',
            'default_validity_hours' => 12,
            'max_validity_hours' => 48,
        ],
        'dts_mass_order.late_order' => [
            'module' => 'dts_mass_orders',
            'type' => 'unlock',
            'label' => 'DTS mass order after the ordering cutoff',
            'request_permissions' => ['create dts mass orders'],
            'approve_permission' => 'approve mass order',
            'default_validity_hours' => 12,
            'max_validity_hours' => 48,
        ],
        'dts_mass_order.edit_locked' => [
            'module' => 'dts_mass_orders',
            'type' => 'unlock',
            'label' => 'Edit a DTS mass order batch after its cutoff',
            'request_permissions' => ['edit dts mass orders'],
            'approve_permission' => 'approve mass order',
            'default_validity_hours' => 12,
            'max_validity_hours' => 48,
        ],
        'receiving.late_logging' => [
            'module' => 'receiving',
            'type' => 'excuse',
            'label' => 'Delivery receiving not logged on the delivery date',
            'request_permissions' => ['receive orders'],
            'approve_permission' => 'approve received orders',
            'filing_window_days' => 7,
        ],
        'sales.late_upload' => [
            'module' => 'store_transactions',
            'type' => 'excuse',
            'label' => 'Sales not uploaded within 1 working day',
            'request_permissions' => ['create store transactions'],
            'approve_permission' => 'approve store transactions',
            'filing_window_days' => 7,
        ],
        'wastage.late_upload' => [
            'module' => 'wastage',
            'type' => 'excuse',
            'label' => 'Wastage not recorded within 1 working day',
            'request_permissions' => ['create wastage record'],
            'approve_permission' => 'approve wastage level 1',
            'filing_window_days' => 7,
        ],
    ],

    'reasons' => [
        'system_issue' => 'System issue / downtime',
        'supplier_delivery' => 'Supplier or delivery issue',
        'store_operations' => 'Store operations (manpower, closure, emergency)',
        'data_unavailable' => 'Data not yet available (POS / SAP)',
        'other' => 'Other',
    ],

    // Reason codes that must carry an attachment as evidence.
    'attachment_required_reasons' => ['other'],

    'justification_min' => 20,
    'justification_max' => 2000,
    'attachment_max_kb' => 5120,
];
