<?php

namespace App\Filament\Resources\AppResource\Pages;

use App\Filament\Resources\AppResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListApps extends ListRecords
{
    protected static string $resource = AppResource::class;

    public function getTitle(): string
    {
        return 'App Sources';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add App Source'),
        ];
    }
}
