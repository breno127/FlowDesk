<?php

namespace App\Providers\Filament;

use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    /**
     * Verde-sálvia: tons 50–400 pastel (fundos, badges, realces) e 600+ mais firmes
     * para botões — 600 com texto branco = contraste 5.0:1 (WCAG AA).
     */
    private const SAGE = [
        50 => '242, 247, 244',
        100 => '228, 240, 233',
        200 => '204, 227, 214',
        300 => '174, 210, 190',
        400 => '141, 190, 163',
        500 => '106, 165, 135',
        600 => '67, 122, 93',
        700 => '56, 102, 80',
        800 => '47, 84, 67',
        900 => '40, 70, 56',
        950 => '21, 38, 30',
    ];

    /**
     * Neutros levemente quentes. Claro: fundo off-white (50) e cards brancos.
     * Escuro: cinza chumbo fosco (900 superfícies / 950 fundo) — sem preto puro.
     */
    private const GRAPHITE = [
        50 => '247, 248, 247',
        100 => '240, 242, 241',
        200 => '228, 231, 229',
        300 => '207, 212, 209',
        400 => '156, 163, 160',
        500 => '107, 115, 112',
        600 => '79, 86, 83',
        700 => '61, 67, 65',
        800 => '52, 56, 60',
        900 => '40, 43, 47',
        950 => '30, 32, 35',
    ];

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName(config('app.name'))
            // Visual corporativo: claro por padrão (o usuário ainda pode alternar para escuro)
            ->defaultThemeMode(ThemeMode::Light)
            ->font('Inter')
            ->colors([
                'primary' => self::SAGE,
                'success' => self::SAGE,
                'danger' => Color::Red,
                'warning' => Color::Amber,
                'info' => Color::Sky,
                'gray' => self::GRAPHITE,
            ])
            ->maxContentWidth(MaxWidth::Full)
            ->sidebarCollapsibleOnDesktop()
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->navigationGroups([
                NavigationGroup::make('Atendimento'),
                NavigationGroup::make('Clientes'),
                NavigationGroup::make('SLA'),
                NavigationGroup::make('Configuração')->collapsed(),
                NavigationGroup::make('Acessos')->collapsed(),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
