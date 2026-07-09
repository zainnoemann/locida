<?php

namespace App\Filament\Resources\TestResource\Pages;

use App\Filament\Resources\TestResource;
use App\Models\Test;
use Filament\Resources\Pages\Page;

class ReportTest extends Page
{
    public Test $record;
    
    protected static string $resource = TestResource::class;

    protected string $view = 'filament.pages.report-test';

    public function mount(Test $record): void
    {
        $this->record = $record;
    }

    public function getTitle(): string
    {
        return "{$this->record->name} Report";
    }

    public function getHeading(): string
    {
        return "{$this->record->name} Report";
    }

    public function getBreadcrumbs(): array
    {
        return [
            static::getResource()::getUrl() => static::getResource()::getPluralModelLabel(),
            static::getResource()::getUrl('edit', ['record' => $this->record]) => $this->record->name,
            static::getResource()::getUrl('report', ['record' => $this->record]) => 'Report',
        ];
    }
}
