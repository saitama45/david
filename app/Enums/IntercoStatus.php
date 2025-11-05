<?php

namespace App\Enums;

enum IntercoStatus: string
{
    case OPEN = 'open';
    case APPROVED = 'approved';
    case DISAPPROVED = 'disapproved';
    case COMMITTED = 'committed';
    case IN_TRANSIT = 'in_transit';
    case RECEIVED = 'received';

    public function getLabel(): string
    {
        return match($this) {
            self::OPEN => 'Open',
            self::APPROVED => 'Approved',
            self::DISAPPROVED => 'Disapproved',
            self::COMMITTED => 'Committed',
            self::IN_TRANSIT => 'In Transit',
            self::RECEIVED => 'Received',
        };
    }

    public function getColor(): string
    {
        return match($this) {
            self::OPEN => 'gray',
            self::APPROVED => 'blue',
            self::DISAPPROVED => 'red',
            self::COMMITTED => 'yellow',
            self::IN_TRANSIT => 'purple',
            self::RECEIVED => 'green',
        };
    }

    public function getIcon(): string
    {
        return match($this) {
            self::OPEN => 'heroicon-o-document-text',
            self::APPROVED => 'heroicon-o-check-circle',
            self::DISAPPROVED => 'heroicon-o-x-circle',
            self::COMMITTED => 'heroicon-o-clock',
            self::IN_TRANSIT => 'heroicon-o-truck',
            self::RECEIVED => 'heroicon-o-check-badge',
        };
    }

    public function canBeEdited(): bool
    {
        return match($this) {
            self::OPEN => true,
            default => false,
        };
    }

    public function canBeApproved(): bool
    {
        return match($this) {
            self::OPEN => true,
            default => false,
        };
    }

    public function canBeCommitted(): bool
    {
        return match($this) {
            self::APPROVED => true,
            default => false,
        };
    }

    public function canBeReceived(): bool
    {
        return match($this) {
            self::COMMITTED, self::IN_TRANSIT => true,
            default => false,
        };
    }
}