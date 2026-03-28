<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TestResource\Pages\CreateTest;
use App\Filament\Resources\TestResource\Pages\EditTest;
use App\Filament\Resources\TestResource\Pages\GenerateTest;
use App\Filament\Resources\TestResource\Pages\ListTests;
use App\Filament\Resources\TestResource\Schemas\TestForm;
use App\Filament\Resources\TestResource\Tables\TestTable;
use App\Models\Test;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class TestResource extends Resource
{
    protected static ?string $model = Test::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-code-bracket-square';

    protected static ?string $navigationLabel = 'Tests';

    protected static ?string $modelLabel = 'Test';

    protected static ?string $pluralModelLabel = 'Tests';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return TestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TestTable::configure($table);
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
            'index' => ListTests::route('/'),
            'create' => CreateTest::route('/create'),
            'edit' => EditTest::route('/{record}/edit'),
            'generate' => GenerateTest::route('/{record}/generate'),
        ];
    }
}
