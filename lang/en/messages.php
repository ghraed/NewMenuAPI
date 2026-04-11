<?php

return [
    'auth' => [
        'invalid_credentials' => 'Invalid email, phone number, or password',
        'missing_restaurant' => 'No restaurant is linked to this account',
        'logged_out' => 'Logged out successfully',
        'unauthenticated' => 'Unauthenticated.',
    ],
    'orders' => [
        'invalid_table' => 'Select a valid table reference for this restaurant.',
        'created' => 'Order created successfully and is awaiting staff confirmation.',
        'edit_only_pending' => 'Only orders waiting for staff confirmation can be edited.',
        'updated' => 'Order updated successfully.',
        'confirm_only_pending' => 'Only orders waiting for staff confirmation can be confirmed.',
        'confirmed' => 'Order confirmed and sent to accounting.',
        'cancel_only_pending' => 'Only orders waiting for staff confirmation can be cancelled.',
        'cancelled' => 'Order request cancelled successfully.',
        'account_only_confirmed' => 'Only staff-confirmed orders can be processed by accounting.',
        'accounted' => 'Order accounted successfully.',
        'discount_type_required' => 'A discount type is required when a discount value is provided.',
    ],
    'waves' => [
        'invalid_table' => 'Select a valid table reference for this restaurant.',
        'already_pending' => 'A wave from this table is already waiting for staff.',
        'sent' => 'Wave sent to the staff team.',
        'resolve_only_pending' => 'Only pending waves can be resolved.',
        'resolved' => 'Wave marked as handled.',
    ],
];
