<?php

use App\Models\StoreOrderItem;

uses(Tests\TestCase::class);

function commitUser(array $permissions): object
{
    return new class($permissions) {
        public function __construct(private array $permissions) {}

        public function can(string $ability): bool
        {
            return in_array($ability, $this->permissions, true);
        }
    };
}

test('CONTROL items are editable with the control permission whatever the category', function () {
    $control = commitUser(['edit control commits']);

    expect(StoreOrderItem::userCanEditCommit($control, 'TRADED', 'CONTROL'))->toBeTrue()
        ->and(StoreOrderItem::userCanEditCommit($control, 'FINISHED GOOD', 'CONTROL'))->toBeTrue()
        ->and(StoreOrderItem::userCanEditCommit($control, 'TRADED', ' control '))->toBeTrue();
});

test('the control permission does not open non-CONTROL items', function () {
    $control = commitUser(['edit control commits']);

    expect(StoreOrderItem::userCanEditCommit($control, 'TRADED', 'SAUCE - GOURMET'))->toBeFalse()
        ->and(StoreOrderItem::userCanEditCommit($control, 'FINISHED GOOD', null))->toBeFalse();
});

test('category permissions still apply without the control permission', function () {
    $other = commitUser(['edit other commits']);
    $finishedGood = commitUser(['edit finished good commits']);

    expect(StoreOrderItem::userCanEditCommit($other, 'TRADED', 'CONTROL'))->toBeTrue()
        ->and(StoreOrderItem::userCanEditCommit($other, 'FINISHED GOOD', 'CONTROL'))->toBeFalse()
        ->and(StoreOrderItem::userCanEditCommit($finishedGood, 'FINISHED GOOD', 'SAUCE'))->toBeTrue()
        ->and(StoreOrderItem::userCanEditCommit($finishedGood, 'TRADED', 'CONTROL'))->toBeFalse();
});

test('Confirm All commits CONTROL items for a control-only user and skips the rest', function () {
    $item = new StoreOrderItem();
    $control = commitUser(['edit control commits']);

    expect($item->canBeCommittedBy($control, 'TRADED', 'CONTROL'))->toBeTrue()
        ->and($item->canBeCommittedBy($control, 'TRADED', 'SAUCE'))->toBeFalse()
        ->and($item->canBeCommittedBy(commitUser(['edit other commits']), 'TRADED', 'SAUCE'))->toBeTrue();
});
