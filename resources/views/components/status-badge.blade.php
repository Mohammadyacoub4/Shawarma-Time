@php
use App\Enums\TimesheetStatus;
$map = [
    TimesheetStatus::PendingApproval->value => ['class' => 'badge-pending',  'label' => 'Pending Approval'],
    TimesheetStatus::Approved->value        => ['class' => 'badge-approved', 'label' => 'Approved'],
    TimesheetStatus::Rejected->value        => ['class' => 'badge-rejected', 'label' => 'Rejected'],
];
$key   = $status instanceof TimesheetStatus ? $status->value : $status;
$entry = $map[$key] ?? ['class' => 'badge bg-dark-600 text-dark-300', 'label' => ucfirst(str_replace('_', ' ', $key))];
@endphp
<span class="{{ $entry['class'] }}">{{ $entry['label'] }}</span>
