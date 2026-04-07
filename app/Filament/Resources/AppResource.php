<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AppResource\Pages\CreateApp;
use App\Filament\Resources\AppResource\Pages\EditApp;
use App\Filament\Resources\AppResource\Pages\GenerateApp;
use App\Filament\Resources\AppResource\Pages\ListApps;
use App\Filament\Resources\AppResource\Schemas\AppForm;
use App\Filament\Resources\AppResource\Tables\AppTable;
use App\Models\App;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class AppResource extends Resource
{
    protected static ?string $model = App::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationLabel = 'Apps';

    protected static ?string $modelLabel = 'App';

    protected static ?string $pluralModelLabel = 'Apps';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return AppForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AppTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApps::route('/'),
            'create' => CreateApp::route('/create'),
            'edit' => EditApp::route('/{record}/edit'),
            'generate' => GenerateApp::route('/{record}/generate'),
        ];
    }
}
