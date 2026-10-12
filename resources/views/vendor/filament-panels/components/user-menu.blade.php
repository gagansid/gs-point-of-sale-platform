{{--
    Override gs.POS dari vendor/filament/filament/resources/views/components/user-menu.blade.php (Filament 5.10).
    Perubahan: trigger posisi topbar, kartu akun di atas menu, tema dipindah ke tombol di samping menu (lihat komentar
    "gs.POS"). Bandingkan ulang setiap upgrade Filament
    (docs/standards/ui/filament-theme.md §2).
--}}
@props([
    'position' => null,
])

@php
    use Filament\Actions\Action;
    use Filament\Enums\UserMenuPosition;
    use Filament\Support\Facades\FilamentView;
    use Filament\Support\Icons\Heroicon;
    use Filament\Support\View\ComponentAttributeBag;
    use Filament\View\PanelsIconAlias;
    use Filament\View\PanelsRenderHook;
    use Illuminate\Support\Arr;

    $user = filament()->auth()->user();

    $userName = filament()->getUserName($user);

    $items = $this->getUserMenuItems();

    $itemsBeforeAndAfterThemeSwitcher = collect($items)
        ->groupBy(fn (Action $item): bool => $item->getSort() < 0, preserveKeys: true)
        ->all();
    $itemsBeforeThemeSwitcher = $itemsBeforeAndAfterThemeSwitcher[true] ?? collect();
    $itemsAfterThemeSwitcher = $itemsBeforeAndAfterThemeSwitcher[false] ?? collect();

    $hasProfileHeader = $itemsBeforeThemeSwitcher->has('profile') &&
        blank(($item = Arr::first($itemsBeforeThemeSwitcher))->getUrl()) &&
        (! $item->hasAction());

    if ($itemsBeforeThemeSwitcher->has('profile')) {
        $itemsBeforeThemeSwitcher = $itemsBeforeThemeSwitcher->prepend($itemsBeforeThemeSwitcher->pull('profile'), 'profile');
    }

    $multiGroupAfterTheme = $this->hasMultipleUserMenuItemGroups();
    $afterThemeItemGroups = $multiGroupAfterTheme ? $this->getUserMenuItemGroupsAfterTheme() : [];

    $position ??= filament()->getUserMenuPosition();

    $isSidebarCollapsibleOnDesktop = filament()->isSidebarCollapsibleOnDesktop();
@endphp

{{ FilamentView::renderHook(PanelsRenderHook::USER_MENU_BEFORE) }}

<x-filament::dropdown
    :placement="($position === UserMenuPosition::Topbar) ? 'bottom-end' : 'top-end'"
    :teleport="$position === UserMenuPosition::Topbar"
    :attributes="
        \Filament\Support\prepare_inherited_attributes($attributes)
            ->class(['fi-user-menu'])
    "
>
    <x-slot name="trigger">
        @if ($position === UserMenuPosition::Topbar)
            {{-- gs.POS: menu akun ala gs-task-tracker — nama + avatar + caret --}}
            <button
                aria-label="{{ __('filament-panels::layout.actions.open_user_menu.label') }}"
                type="button"
                class="fi-user-menu-trigger gs-account-trigger"
            >
                <span class="gs-account-name">{{ $userName }}</span>

                <x-filament-panels::avatar.user :user="$user" loading="lazy" />

                {{ \Filament\Support\generate_icon_html(Heroicon::OutlinedChevronDown, attributes: (new ComponentAttributeBag())->class(['gs-account-caret'])) }}
            </button>
        @else
            <button
                aria-label="{{ filled($userName) ? $userName : __('filament-panels::layout.actions.open_user_menu.label') }}"
                type="button"
                class="fi-user-menu-trigger"
            >
                <x-filament-panels::avatar.user :user="$user" loading="lazy" />

                <span
                    @if ($isSidebarCollapsibleOnDesktop)
                        x-show="$store.sidebar.isOpen"
                    @endif
                    class="fi-user-menu-trigger-text"
                >
                    {{ $userName }}
                </span>

                {{
                    \Filament\Support\generate_icon_html(Heroicon::OutlinedChevronUp, alias: PanelsIconAlias::USER_MENU_TOGGLE_BUTTON, attributes: new ComponentAttributeBag([
                        'x-show' => $isSidebarCollapsibleOnDesktop ? '$store.sidebar.isOpen' : null,
                    ]))
                }}
            </button>
        @endif
    </x-slot>

    {{-- gs.POS: kartu akun di atas menu (foto, nama, email, role · bisnis) --}}
    @if ($position === UserMenuPosition::Topbar)
        <div class="gs-account-card">
            <x-filament-panels::avatar.user :user="$user" loading="lazy" />
            <div class="gs-account-card-text">
                <strong>{{ $userName }}</strong>
                @if (filled($user?->email))
                    <span>{{ $user->email }}</span>
                @endif
                @if ($user instanceof \App\Models\User)
                    <span>{{ $user->role->getLabel() }} · {{ $user->tenant?->name }}</span>
                @endif
            </div>
        </div>
    @endif

    @if ($hasProfileHeader)
        @php
            $item = $itemsBeforeThemeSwitcher['profile'];
            $itemColor = $item->getColor();
            $itemIcon = $item->getIcon();

            unset($itemsBeforeThemeSwitcher['profile']);
        @endphp

        {{ FilamentView::renderHook(PanelsRenderHook::USER_MENU_PROFILE_BEFORE) }}

        <x-filament::dropdown.header :color="$itemColor" :icon="$itemIcon">
            {{ $item->getLabel() }}
        </x-filament::dropdown.header>

        {{ FilamentView::renderHook(PanelsRenderHook::USER_MENU_PROFILE_AFTER) }}
    @endif

    @if ($itemsBeforeThemeSwitcher->isNotEmpty())
        <x-filament::dropdown.list>
            @foreach ($itemsBeforeThemeSwitcher as $key => $item)
                @if ($key === 'profile')
                    {{ FilamentView::renderHook(PanelsRenderHook::USER_MENU_PROFILE_BEFORE) }}

                    {{ $item }}

                    {{ FilamentView::renderHook(PanelsRenderHook::USER_MENU_PROFILE_AFTER) }}
                @else
                    {{ $item }}
                @endif
            @endforeach
        </x-filament::dropdown.list>
    @endif

    {{-- gs.POS: tema tidak di dalam menu; tombol tema ada di samping nama (setelah dropdown) --}}

    @if ($multiGroupAfterTheme && $afterThemeItemGroups !== [])
        @foreach ($afterThemeItemGroups as $afterThemeGroup)
            <x-filament::dropdown.list>
                @foreach ($afterThemeGroup as $key => $item)
                    @if ($key === 'profile')
                        {{ FilamentView::renderHook(PanelsRenderHook::USER_MENU_PROFILE_BEFORE) }}

                        {{ $item }}

                        {{ FilamentView::renderHook(PanelsRenderHook::USER_MENU_PROFILE_AFTER) }}
                    @else
                        {{ $item }}
                    @endif
                @endforeach
            </x-filament::dropdown.list>
        @endforeach
    @elseif ($itemsAfterThemeSwitcher->isNotEmpty())
        <x-filament::dropdown.list>
            @foreach ($itemsAfterThemeSwitcher as $key => $item)
                @if ($key === 'profile')
                    {{ FilamentView::renderHook(PanelsRenderHook::USER_MENU_PROFILE_BEFORE) }}

                    {{ $item }}

                    {{ FilamentView::renderHook(PanelsRenderHook::USER_MENU_PROFILE_AFTER) }}
                @else
                    {{ $item }}
                @endif
            @endforeach
        </x-filament::dropdown.list>
    @endif
</x-filament::dropdown>

{{-- gs.POS: tombol tema satu klik (terang → gelap → ikut sistem) di kanan menu akun --}}
@if (filament()->hasDarkMode() && (! filament()->hasDarkModeForced()) && filament()->hasThemeSwitcher())
    <button
        type="button"
        class="gs-theme-toggle"
        x-data="{ theme: localStorage.getItem('theme') || @js(filament()->getDefaultThemeMode()->value) }"
        x-on:click="theme = ({ light: 'dark', dark: 'system', system: 'light' })[theme] ?? 'light'; $dispatch('theme-changed', theme)"
        x-bind:aria-label="({ light: 'Tema terang', dark: 'Tema gelap', system: 'Tema ikut sistem' })[theme] + ' — klik untuk ganti'"
        x-tooltip="{ content: () => ({ light: 'Tema terang', dark: 'Tema gelap', system: 'Ikut sistem' })[theme], theme: $store.theme }"
    >
        <span x-show="theme === 'light'">{{ \Filament\Support\generate_icon_html(Heroicon::OutlinedSun) }}</span>
        <span x-show="theme === 'dark'" x-cloak>{{ \Filament\Support\generate_icon_html(Heroicon::OutlinedMoon) }}</span>
        <span x-show="theme === 'system'" x-cloak>{{ \Filament\Support\generate_icon_html(Heroicon::OutlinedComputerDesktop) }}</span>
    </button>
@endif

{{ FilamentView::renderHook(PanelsRenderHook::USER_MENU_AFTER) }}
