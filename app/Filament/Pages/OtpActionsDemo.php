<?php

namespace App\Filament\Pages;

use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every case from the specification, in one place:
 *
 * 1. A bare action goes straight to the one-time code modal.
 * 2. An action with a form shows the form first, then the code modal.
 * 3. `requiresConfirmation()` shows its confirmation first, then the code modal.
 * 4. Both together: form, confirmation, then the code modal.
 * 5. `disableWhenNoOtp()` / `hideWhenNoOtp()` react to whether the user set anything up.
 *
 * Plus the places an action can live: inside a dropdown group, on a table row, and
 * as a bulk action over selected records.
 */
class OtpActionsDemo extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.otp-actions-demo';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'OTP actions';

    public function getTitle(): string | Htmlable
    {
        return 'OTP actions';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bare')
                ->label('Bare action')
                ->color('primary')
                ->requiresOtpConfirmation()
                ->action(function (): void {
                    $this->notify('Bare action ran.');
                }),

            Action::make('withForm')
                ->label('Action with a form')
                ->color('info')
                ->schema([
                    TextInput::make('amount')
                        ->label('Amount')
                        ->numeric()
                        ->required(),
                    TextInput::make('recipient')
                        ->label('Recipient')
                        ->required(),
                ])
                ->requiresOtpConfirmation()
                ->action(function (array $data): void {
                    $this->notify("Sent {$data['amount']} to {$data['recipient']}.");
                }),

            Action::make('withConfirmation')
                ->label('Action with a confirmation')
                ->color('warning')
                ->requiresConfirmation()
                ->requiresOtpConfirmation()
                ->action(function (): void {
                    $this->notify('Confirmed action ran.');
                }),

            Action::make('withFormAndConfirmation')
                ->label('Form, confirmation and a code')
                ->color('danger')
                ->schema([
                    TextInput::make('reason')
                        ->label('Reason')
                        ->required(),
                ])
                ->requiresConfirmation()
                ->requiresOtpConfirmation()
                ->action(function (array $data): void {
                    $this->notify("Everything confirmed: {$data['reason']}.");
                }),

            // Only large amounts are worth challenging for, so the condition decides
            // once the form has been validated.
            Action::make('conditional')
                ->label('Only over 1000')
                ->color('info')
                ->schema([
                    TextInput::make('amount')
                        ->label('Amount')
                        ->numeric()
                        ->required(),
                ])
                ->requiresOtpConfirmation(fn (Action $action): bool => ($action->getData()['amount'] ?? 0) > 1000)
                ->action(function (array $data): void {
                    $this->notify("Transferred {$data['amount']}.");
                }),

            // Closing the code modal normally returns the user to this form with what
            // they entered still in it. This one abandons the whole thing instead.
            Action::make('withFormAbandonedOnClose')
                ->label('Cancel closes everything')
                ->color('info')
                ->schema([
                    TextInput::make('amount')
                        ->label('Amount')
                        ->numeric()
                        ->required(),
                ])
                ->requiresOtpConfirmation(cancelsOnClose: true)
                ->action(function (array $data): void {
                    $this->notify("Transferred {$data['amount']}.");
                }),

            // Two pauses in a row on one action: the generator fills in a field that the
            // user left empty, then the code is asked for, and the action finally runs
            // with everything together. Conditions accumulate, so they queue up in the
            // order they were registered.
            Action::make('createInvoice')
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
                        ->label('Generate a reference')
                        ->modalHeading('Generate a reference')
                        ->modalDescription('This is added to the invoice you were filling in, which is then submitted as a whole.')
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

                            // The paused action's schema state lives here, so writing to
                            // it fills in the field that the user left empty.
                            data_set($livewire->mountedActions, '0.data.reference', $reference);

                            array_pop($livewire->mountedActions);

                            $livewire->callMountedAction();
                        }),
                ])
                ->requiresOtpConfirmation()
                ->action(function (array $data): void {
                    $this->notify("Invoice {$data['reference']} for {$data['client']}, {$data['amount']}.");
                }),

            ActionGroup::make([
                // Grouped actions are cloned into the group, so the confirmation has to
                // survive being cloned.
                Action::make('grouped')
                    ->label('Grouped action')
                    ->requiresOtpConfirmation()
                    ->action(function (): void {
                        $this->notify('Grouped action ran.');
                    }),

                Action::make('disabledWithoutOtp')
                    ->label('Disabled without a method')
                    ->requiresOtpConfirmation()
                    ->disableWhenNoOtp()
                    ->action(function (): void {
                        $this->notify('Ran an action that needs a configured method.');
                    }),

                Action::make('hiddenWithoutOtp')
                    ->label('Hidden without a method')
                    ->requiresOtpConfirmation()
                    ->hideWhenNoOtp()
                    ->action(function (): void {
                        $this->notify('Ran an action that is hidden without a method.');
                    }),
            ])
                ->label('More')
                ->button(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(User::query())
            ->defaultPaginationPageOption(5)
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('email')
                    ->searchable(),
            ])
            ->recordActions([
                Action::make('impersonate')
                    ->requiresOtpConfirmation()
                    ->action(function (User $record): void {
                        $this->notify("Impersonated {$record->name}.");
                    }),

                Action::make('anonymise')
                    ->requiresConfirmation()
                    ->requiresOtpConfirmation()
                    ->color('danger')
                    ->action(function (User $record): void {
                        $this->notify("Anonymised {$record->name}.");
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('deactivate')
                    ->requiresConfirmation()
                    ->requiresOtpConfirmation()
                    ->color('danger')
                    ->action(function (Collection $records): void {
                        $this->notify("Deactivated {$records->count()} users.");
                    }),
            ]);
    }

    protected function notify(string $title): void
    {
        Notification::make()
            ->title($title)
            ->success()
            ->send();
    }
}
