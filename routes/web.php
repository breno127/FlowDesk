<?php

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

// A raiz leva direto ao painel (login, se não autenticado)
Route::get('/', fn () => redirect(Filament::getPanel('admin')->getUrl()));
