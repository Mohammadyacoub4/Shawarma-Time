<?php

namespace App\Enums;

enum TimesheetStatus: string
{
    case ClockedIn = 'clocked_in';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::ClockedIn => 'Clocked In',
            self::PendingApproval => 'Pending Approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::ClockedIn => 'متواجد حالياً بالعمل',
            self::PendingApproval => 'بانتظار الموافقة',
            self::Approved => 'معتمد',
            self::Rejected => 'مرفوض',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::ClockedIn => 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30',
            self::PendingApproval => 'bg-amber-500/10 text-amber-400 border border-amber-500/30',
            self::Approved => 'bg-green-500/10 text-green-400 border border-green-500/30',
            self::Rejected => 'bg-rose-500/10 text-rose-400 border border-rose-500/30',
        };
    }
}
