<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Confirming actions with a one-time code</x-slot>

        <x-slot name="description">
            Every action here is confirmed with a code from the multi-factor method set up on your
            <a href="{{ \Filament\Facades\Filament::getProfileUrl() }}" class="fi-link fi-link-label">profile</a>.
        </x-slot>

        @if (app(\Webard\FilamentOtpActions\OtpConfirmations::class)->hasEnabledProviders())
            <p>You have a method set up, so every action is available.</p>
        @else
            <p>
                You have no method set up yet, so <strong>Disabled without a method</strong> is disabled
                and <strong>Hidden without a method</strong> is not shown at all.
            </p>
        @endif

        <ul class="fi-list">
            <li><strong>Bare action</strong> &mdash; goes straight to the code modal.</li>
            <li><strong>Action with a form</strong> &mdash; the form is validated first, then the code is asked for.</li>
            <li><strong>Action with a confirmation</strong> &mdash; confirm first, then the code.</li>
            <li><strong>Form, confirmation and a code</strong> &mdash; all three, stacked in order.</li>
            <li><strong>Only over 1000</strong> &mdash; the code is only asked for when the amount warrants it.</li>
            <li><strong>More</strong> &mdash; the same, from inside a dropdown group.</li>
        </ul>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">On a table</x-slot>

        <x-slot name="description">
            Row actions and a bulk action over the records you select.
        </x-slot>

        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
