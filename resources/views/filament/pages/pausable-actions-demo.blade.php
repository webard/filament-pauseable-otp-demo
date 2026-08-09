<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Demo switches</x-slot>

        <x-slot name="description">
            These put the app into the state that each case needs. In a real app this
            would be the state of your system, not buttons.
        </x-slot>

        <div class="fi-ac">
            {{ $this->toggleApiAction }}
            {{ $this->simulateExternalEditAction }}
            {{ $this->resetDemoAction }}
        </div>

        <dl>
            <dt><strong>API</strong></dt>
            <dd>{{ $this->isApiUp ? 'up' : 'down' }}</dd>

            <dt><strong>Credits</strong></dt>
            <dd>{{ $this->credits }}</dd>

            <dt><strong>First user</strong></dt>
            <dd>{{ $this->getDemoUser()->name }} (saved {{ $this->getDemoUser()->updated_at?->diffForHumans() }})</dd>
        </dl>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">What each action pauses on</x-slot>

        <ul>
            <li>
                <strong>Rename a user</strong> &mdash; stale data. Open it, hit
                <em>Simulate someone else editing</em>, then save: you are shown what changed
                before your save goes through.
            </li>
            <li>
                <strong>Prune users</strong> &mdash; impact preview. The number of affected
                records is only known after the form validates, which is why
                <code>requiresConfirmation()</code> cannot do this.
            </li>
            <li>
                <strong>Create invoice</strong> &mdash; missing data. Leave the reference empty
                and a modal generates one, writes it into the form you were filling in, and the
                whole thing is submitted together.
            </li>
            <li>
                <strong>Send a report</strong> &mdash; out of credits. Top up without losing the
                recipient you typed.
            </li>
            <li>
                <strong>Sync with the API</strong> &mdash; the API is down. Retry once it is back,
                with your payload intact.
            </li>
            <li>
                <strong>Pause from <code>before()</code></strong> &mdash; the imperative
                <code>$action-&gt;pause()</code>. The hook does not run a second time when the
                action resumes.
            </li>
            <li>
                <strong>Rate limited to 2</strong> &mdash; pausing and resuming spend one attempt
                between them, not two.
            </li>
        </ul>
    </x-filament::section>

    @if (filled($this->hookLog))
        <x-filament::section>
            <x-slot name="heading">Hook calls</x-slot>

            <x-slot name="description">
                Counted across requests. A hook that pauses is not called again when the
                action resumes.
            </x-slot>

            <ul>
                @foreach ($this->hookLog as $hook => $count)
                    <li><code>{{ $hook }}</code> &mdash; {{ $count }}&times;</li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
