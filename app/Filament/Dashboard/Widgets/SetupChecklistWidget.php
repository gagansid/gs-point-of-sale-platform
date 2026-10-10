<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets;

use App\Actions\Catalog\ApplyMenuTemplate;
use App\Exceptions\BusinessException;
use App\Models\User;
use App\Support\MenuTemplates;
use App\Support\SetupProgress;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;

/**
 * Beranda: checklist "Mulai berjualan" untuk bisnis baru (onboarding ADR 0009, S5).
 * Hilang otomatis setelah semua langkah selesai. Template menu hanya saat katalog kosong.
 */
final class SetupChecklistWidget extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected string $view = 'filament.dashboard.widgets.setup-checklist';

    protected static bool $isLazy = false;

    // Paling atas, di atas statistik penjualan
    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->hasPermission('product.manage')
            && ! SetupProgress::isComplete($user->tenant);
    }

    /**
     * @return list<array{key: string, label: string, hint: string, done: bool, url: string|null}>
     */
    public function getSteps(): array
    {
        $user = auth()->user();

        return $user instanceof User ? SetupProgress::steps($user->tenant) : [];
    }

    public function applyTemplateAction(): Action
    {
        return Action::make('applyTemplate')
            ->label('Pakai template menu')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->visible(fn (): bool => SetupProgress::catalogIsEmpty() && (auth()->user()?->can('product.manage') ?? false))
            ->modalHeading('Pakai template menu')
            ->modalDescription('Kategori, produk, dan opsi contoh dibuat sekaligus. Harga & nama bisa diubah setelahnya.')
            ->modalSubmitActionLabel('Buat menu')
            ->modalWidth('lg')
            ->schema([
                Radio::make('template')->hiddenLabel()->options(MenuTemplates::options())->default('cafe')->required(),
            ])
            ->action(function (array $data, Action $action): void {
                $user = auth()->user();
                abort_unless($user instanceof User, 403);

                try {
                    $count = app(ApplyMenuTemplate::class)->handle((string) $data['template'], $user);
                } catch (BusinessException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()
                    ->title("{$count} produk contoh dibuat")
                    ->body('Ubah nama & harga sesuai menu Anda di menu Produk.')
                    ->send();
            });
    }
}
