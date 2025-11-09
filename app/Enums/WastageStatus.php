<?php

namespace App\Enums;

enum WastageStatus: string
{
    case PENDING = 'PENDING';
    case APPROVED_LVL1 = 'APPROVED_LVL1';
    case APPROVED_LVL2 = 'APPROVED_LVL2';
    case CANCELLED = 'CANCELLED';

    /**
     * Get the label for display purposes
     */
    public function getLabel(): string
    {
        return match($this) {
            self::PENDING => 'Pending',
            self::APPROVED_LVL1 => 'Approved Level 1',
            self::APPROVED_LVL2 => 'Approved Level 2',
            self::CANCELLED => 'Cancelled',
        };
    }

    /**
     * Get the color class for UI display
     */
    public function getColor(): string
    {
        return match($this) {
            self::PENDING => 'text-gray-600',
            self::APPROVED_LVL1 => 'text-blue-600',
            self::APPROVED_LVL2 => 'text-green-600',
            self::CANCELLED => 'text-red-600',
        };
    }

    /**
     * Get the background color class for UI display
     */
    public function getBackgroundColor(): string
    {
        return match($this) {
            self::PENDING => 'bg-gray-100',
            self::APPROVED_LVL1 => 'bg-blue-100',
            self::APPROVED_LVL2 => 'bg-green-100',
            self::CANCELLED => 'bg-red-100',
        };
    }

    /**
     * Check if the status can be edited
     */
    public function canBeEdited(): bool
    {
        return match($this) {
            self::PENDING => true,
            self::APPROVED_LVL1 => false,
            self::APPROVED_LVL2 => false,
            self::CANCELLED => false,
        };
    }

    /**
     * Check if the status can be approved at level 1
     */
    public function canBeApprovedLevel1(): bool
    {
        return match($this) {
            self::PENDING => true,
            self::APPROVED_LVL1 => false,
            self::APPROVED_LVL2 => false,
            self::CANCELLED => false,
        };
    }

    /**
     * Check if the status can be approved at level 2
     */
    public function canBeApprovedLevel2(): bool
    {
        return match($this) {
            self::PENDING => false,
            self::APPROVED_LVL1 => true,
            self::APPROVED_LVL2 => false,
            self::CANCELLED => false,
        };
    }

    /**
     * Check if the status can be cancelled
     */
    public function canBeCancelled(): bool
    {
        return match($this) {
            self::PENDING => true,
            self::APPROVED_LVL1 => true,
            self::APPROVED_LVL2 => false,
            self::CANCELLED => false,
        };
    }

    /**
     * Get the next status for approval workflow
     */
    public function getNextApprovalStatus(): ?self
    {
        return match($this) {
            self::PENDING => self::APPROVED_LVL1,
            self::APPROVED_LVL1 => self::APPROVED_LVL2,
            self::APPROVED_LVL2 => null,
            self::CANCELLED => null,
        };
    }

    /**
     * Check if the status is an approved status
     */
    public function isApproved(): bool
    {
        return match($this) {
            self::APPROVED_LVL1 => true,
            self::APPROVED_LVL2 => true,
            default => false,
        };
    }

    /**
     * Check if the status is final (no further actions possible)
     */
    public function isFinal(): bool
    {
        return match($this) {
            self::APPROVED_LVL2 => true,
            self::CANCELLED => true,
            default => false,
        };
    }
}