<x-layouts.app :title="($meeting->title ?: 'Meeting').' - Air Meet'">
    @livewire('meeting.room', ['meeting' => $meeting], key($meeting->uuid))
</x-layouts.app>
