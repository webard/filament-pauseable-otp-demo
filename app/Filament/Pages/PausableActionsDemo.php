<?php

namespace App\Filament\Pages;

use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Everything that pausing an action is good for, with no one-time codes involved.
 *
 * Each action pauses on something the server decides, mounts a modal that resolves
 * it, and then resumes with whatever the user had already entered still intact. The
 * switches at the top of the page put the app into the state each case needs.
 */
class PausableActionsDemo extends Page
{
    protected string $view = 'filament.pages.pausable-actions-demo';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedPauseCircle;

    protected static ?string $navigationLabel = 'Pausable actions';

    /**
     * Demo switches. In a real app these would be the state of your system, not
     * properties you can flip from the page.
     */
    public bool $isApiUp = false;

    public int $credits = 0;

    /**
     * Counts every lifecycle hook call, so the page can show that pausing does not
     * make them run twice.
     *
     * @var array<string, int>
     */
    public array $hookLog = [];

    public function mount(): void
    {
        // The version of the record as this page last showed it. Kept server side, so
        // the browser cannot claim to have seen a newer one than it did.
        session()->put('demo.rename.seen_version', $this->getRecordVersion($this->getDemoUser()));
    }

    public function getTitle(): string | Htmlable
    {
        return 'Pausable actions';
    }

    public function getSubheading(): string | Htmlable | null
    {
        return 'Pausing an action, without a one-time code in sight.';
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->staleRecordAction(),
            $this->impactPreviewAction(),
            $this->missingDataAction(),
            $this->outOfCreditsAction(),
            $this->unavailableApiAction(),
            $this->pauseFromBeforeAction(),
            $this->rateLimitedAction(),
        ];
    }

    /**
     * Someone else edited the record while the user had the form open. The user is
     * shown what changed, and decides whether to go ahead.
     */
    protected function staleRecordAction(): Action
    {
        return Action::make('staleRecord')
            ->label('Rename a user')
            ->color('primary')
            ->schema([
                TextInput::make('name')
                    ->label('Name')
                    ->required(),
            ])
            ->fillForm(fn (): array => ['name' => $this->getDemoUser()->name])
            ->pauseWhen(function (Action $action): bool {
                $version = $this->getRecordVersion($this->getDemoUser());

                if (session('demo.rename.seen_version') === $version) {
                    return false;
                }

                if (session('demo.rename.accepted_version') === $version) {
                    return false;
                }

                $action->getLivewire()->mountAction('confirmStaleRecord');

                return true;
            })
            ->registerModalActions([
                Action::make('confirmStaleRecord')
                    ->cancelParentActionsOnClose($this->closesTheWholeStack(...))
                    ->label('Someone else changed this')
                    ->modalHeading('This user changed while you were editing')
                    ->modalDescription('Someone else saved changes after you opened the form. Review them before you continue.')
                    ->modalSubmitActionLabel('Save mine anyway')
                    ->modalIcon(Heroicon::OutlinedExclamationTriangle)
                    ->modalIconColor('warning')
                    ->schema(fn (): array => [
                        Text::make('Their version: ' . $this->getDemoUser()->name),
                    ])
                    ->action(function (Action $action): void {
                        session()->put('demo.rename.accepted_version', $this->getRecordVersion($this->getDemoUser()));

                        $this->resumeParentOf($action);
                    }),
            ])
            ->action(function (array $data): void {
                $user = $this->getDemoUser();
                $user->update(['name' => $data['name']]);

                session()->forget('demo.rename.accepted_version');
                session()->put('demo.rename.seen_version', $this->getRecordVersion($user));

                $this->notify("Renamed to {$data['name']}.");
            });
    }

    /**
     * The number of affected records is only known once the form has been validated,
     * which is exactly where `requiresConfirmation()` cannot help.
     */
    protected function impactPreviewAction(): Action
    {
        return Action::make('impactPreview')
            ->label('Prune users')
            ->color('danger')
            ->schema([
                DatePicker::make('createdBefore')
                    ->label('Created before')
                    ->default(now())
                    ->required(),
            ])
            ->pauseWhen(function (Action $action): bool {
                $count = $this->countUsersToPrune($action->getData()['createdBefore'] ?? null);

                if (session('demo.prune.accepted_count') === $count) {
                    return false;
                }

                $action->getLivewire()->mountAction('confirmImpact');

                return true;
            })
            ->registerModalActions([
                Action::make('confirmImpact')
                    ->cancelParentActionsOnClose($this->closesTheWholeStack(...))
                    ->label('Review the impact')
                    ->modalHeading('Review what this affects')
                    ->modalIcon(Heroicon::OutlinedExclamationTriangle)
                    ->modalIconColor('danger')
                    ->modalSubmitActionLabel('Prune them')
                    // Read straight off the Livewire property rather than through
                    // `$mountedActions`: resolving those builds this schema, so asking
                    // for them from inside it recurses.
                    ->schema(fn (): array => [
                        Text::make(sprintf(
                            'This would affect %d of %d users.',
                            $this->countUsersToPrune($this->getPausedActionData('createdBefore')),
                            User::count(),
                        )),
                    ])
                    ->action(function (Action $action): void {
                        session()->put(
                            'demo.prune.accepted_count',
                            $this->countUsersToPrune($this->getPausedActionData('createdBefore')),
                        );

                        $this->resumeParentOf($action);
                    }),
            ])
            ->action(function (array $data): void {
                $count = $this->countUsersToPrune($data['createdBefore'] ?? null);

                session()->forget('demo.prune.accepted_count');

                // Nothing is actually deleted in the demo.
                $this->notify("Pruned {$count} users.");
            });
    }

    /**
     * The action is missing a piece of data, so a modal produces it and writes it
     * into the form that is waiting, which is then submitted as a whole.
     */
    protected function missingDataAction(): Action
    {
        return Action::make('missingData')
            ->label('Create invoice')
            ->color('success')
            ->schema([
                TextInput::make('client')
                    ->label('Client')
                    ->required(),
                TextInput::make('amount')
                    ->label('Amount')
                    ->numeric()
                    ->required(),
                TextInput::make('reference')
                    ->label('Reference')
                    ->helperText('Leave this empty to have one generated for you.'),
            ])
            ->pauseWhen(function (Action $action): bool {
                if (filled($action->getData()['reference'] ?? null)) {
                    return false;
                }

                $action->getLivewire()->mountAction('generateReference');

                return true;
            })
            ->registerModalActions([
                Action::make('generateReference')
                    ->cancelParentActionsOnClose($this->closesTheWholeStack(...))
                    ->label('Generate a reference')
                    ->modalHeading('Generate a reference')
                    ->modalDescription('This is written into the invoice you were filling in, which is then submitted as a whole.')
                    ->modalSubmitActionLabel('Add to the invoice')
                    ->schema([
                        TextInput::make('prefix')
                            ->label('Prefix')
                            ->default('INV')
                            ->required(),
                    ])
                    ->action(function (Action $action, array $data): void {
                        $livewire = $action->getLivewire();

                        $reference = $data['prefix'] . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);

                        // The paused action's schema state lives here, so writing to it
                        // fills in the field that the user left empty.
                        data_set($livewire->mountedActions, '0.data.reference', $reference);

                        $this->resumeParentOf($action);
                    }),
            ])
            ->action(function (array $data): void {
                $this->notify("Invoice {$data['reference']} for {$data['client']}, {$data['amount']}.");
            });
    }

    /**
     * The user has run out of something the action needs, so they top it up without
     * losing what they had already filled in.
     */
    protected function outOfCreditsAction(): Action
    {
        return Action::make('outOfCredits')
            ->label('Send a report')
            ->color('warning')
            ->schema([
                TextInput::make('recipient')
                    ->label('Recipient')
                    ->email()
                    ->required(),
            ])
            ->pauseWhen(function (Action $action): bool {
                if ($this->credits > 0) {
                    return false;
                }

                $action->getLivewire()->mountAction('topUpCredits');

                return true;
            })
            ->registerModalActions([
                Action::make('topUpCredits')
                    ->cancelParentActionsOnClose($this->closesTheWholeStack(...))
                    ->label('Top up')
                    ->modalHeading('You are out of credits')
                    ->modalDescription('Sending a report costs one credit. Top up to continue - the report you were sending is kept.')
                    ->modalSubmitActionLabel('Add 5 credits')
                    ->modalIcon(Heroicon::OutlinedBanknotes)
                    ->action(function (Action $action): void {
                        $this->credits += 5;

                        $this->resumeParentOf($action);
                    }),
            ])
            ->action(function (array $data): void {
                $this->credits--;

                $this->notify("Report sent to {$data['recipient']}. {$this->credits} credits left.");
            });
    }

    /**
     * The weakest of the cases, but a real one: the user keeps what they typed and
     * retries, instead of losing the form to a failure.
     */
    protected function unavailableApiAction(): Action
    {
        return Action::make('unavailableApi')
            ->label('Sync with the API')
            ->color('gray')
            ->schema([
                TextInput::make('payload')
                    ->label('Payload')
                    ->required(),
            ])
            ->pauseWhen(function (Action $action): bool {
                if ($this->isApiUp) {
                    return false;
                }

                $action->getLivewire()->mountAction('retryApi');

                return true;
            })
            ->registerModalActions([
                Action::make('retryApi')
                    ->cancelParentActionsOnClose($this->closesTheWholeStack(...))
                    ->label('Retry')
                    ->modalHeading('The API is not responding')
                    ->modalDescription('Nothing has been sent. Your payload is kept, so you can retry once it is back.')
                    ->modalSubmitActionLabel('Try again')
                    ->modalIcon(Heroicon::OutlinedCloudArrowUp)
                    ->modalIconColor('danger')
                    ->action(function (Action $action): void {
                        $this->resumeParentOf($action);
                    }),
            ])
            ->action(function (array $data): void {
                $this->notify("Synced: {$data['payload']}.");
            });
    }

    /**
     * The imperative side of the API: `pause()` straight from a lifecycle hook.
     */
    protected function pauseFromBeforeAction(): Action
    {
        return Action::make('pauseFromBefore')
            ->label('Pause from `before()`')
            ->color('info')
            ->requiresConfirmation()
            ->before(function (Action $action): void {
                $this->countHookCall('pauseFromBefore:before');

                if ($this->isApiUp) {
                    return;
                }

                Notification::make()
                    ->title('Paused from the `before()` hook')
                    ->body('Turn the API on with the switch, then confirm again. The hook will not run a second time.')
                    ->warning()
                    ->send();

                $action->pause();
            })
            ->action(function (): void {
                $this->countHookCall('pauseFromBefore:action');

                $this->notify('Ran after being paused from `before()`.');
            });
    }

    /**
     * `rateLimit(2)` would be spent by a pause and a resumption if resuming counted
     * as a fresh attempt.
     */
    protected function rateLimitedAction(): Action
    {
        return Action::make('rateLimited')
            ->label('Rate limited to 2')
            ->color('gray')
            ->rateLimit(2)
            ->pauseWhen(function (Action $action): bool {
                if ($this->isApiUp) {
                    return false;
                }

                $action->getLivewire()->mountAction('retryRateLimited');

                return true;
            })
            ->registerModalActions([
                Action::make('retryRateLimited')
                    ->cancelParentActionsOnClose($this->closesTheWholeStack(...))
                    ->label('Continue')
                    ->modalHeading('Paused')
                    ->modalDescription('Turn the API on with the switch, then continue. Resuming does not spend another attempt.')
                    ->modalSubmitActionLabel('Continue')
                    ->action(function (Action $action): void {
                        $this->resumeParentOf($action);
                    }),
            ])
            ->action(function (): void {
                $this->notify('Rate limited action ran.');
            });
    }

    public function toggleApiAction(): Action
    {
        return Action::make('toggleApi')
            ->label(fn (): string => $this->isApiUp ? 'Take the API down' : 'Bring the API up')
            ->color(fn (): string => $this->isApiUp ? 'danger' : 'success')
            ->action(function (): void {
                $this->isApiUp = ! $this->isApiUp;
            });
    }

    public function simulateExternalEditAction(): Action
    {
        return Action::make('simulateExternalEdit')
            ->label('Simulate someone else editing')
            ->color('warning')
            ->action(function (): void {
                $user = $this->getDemoUser();
                $user->update(['name' => 'Edited elsewhere at ' . now()->format('H:i:s')]);

                $this->notify('Someone else just saved this user.');
            });
    }

    public function resetDemoAction(): Action
    {
        return Action::make('resetDemo')
            ->label('Reset the demo')
            ->color('gray')
            ->action(function (): void {
                $this->credits = 0;
                $this->isApiUp = false;
                $this->hookLog = [];

                session()->forget([
                    'demo.rename.accepted_version',
                    'demo.prune.accepted_count',
                ]);

                session()->put('demo.rename.seen_version', $this->getRecordVersion($this->getDemoUser()));

                $this->notify('Back to the start.');
            });
    }

    /**
     * The paused action's schema state, straight off the Livewire property.
     */
    protected function getPausedActionData(string $key): mixed
    {
        return data_get($this->mountedActions, "0.data.{$key}");
    }

    public function getDemoUser(): User
    {
        return User::query()->oldest('id')->firstOrFail();
    }

    /**
     * Derived from the record's contents rather than its timestamp, since two saves
     * within the same second would otherwise look identical.
     */
    protected function getRecordVersion(User $user): string
    {
        $user = $user->fresh();

        return md5($user->name . '|' . $user->updated_at?->toISOString());
    }

    protected function countUsersToPrune(mixed $createdBefore): int
    {
        if (blank($createdBefore)) {
            return 0;
        }

        return User::query()->whereDate('created_at', '<', $createdBefore)->count();
    }

    /**
     * Closing a modal that resolved a pause normally returns to the action underneath
     * it. When that action has no modal of its own there is nothing to return to, and
     * leaving it mounted would silently block every other action on the page, so the
     * whole stack is closed instead.
     */
    public function closesTheWholeStack(Action $action): bool
    {
        $parentAction = $action->getParentAction();

        if (! $parentAction) {
            return false;
        }

        return ! $action->getLivewire()->mountedActionShouldOpenModal($parentAction);
    }

    /**
     * Unmounts the modal that resolved the pause, and calls the action underneath it,
     * which resumes rather than starting again.
     */
    protected function resumeParentOf(Action $action): void
    {
        $livewire = $action->getLivewire();

        array_pop($livewire->mountedActions);

        $livewire->callMountedAction();
    }

    protected function countHookCall(string $hook): void
    {
        $this->hookLog[$hook] = ($this->hookLog[$hook] ?? 0) + 1;
    }

    protected function notify(string $title): void
    {
        Notification::make()
            ->title($title)
            ->success()
            ->send();
    }
}
