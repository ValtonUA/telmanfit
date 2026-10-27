<?php

namespace Tests\Postgres;

use App\Filament\Resources\Library\LibraryItemResource;
use App\Filament\Resources\Library\Pages\CreateLibraryItem;
use App\Filament\Resources\Library\Pages\EditLibraryItem;
use App\Filament\Resources\Library\Pages\ListLibraryItems;
use App\Models\LibraryItem;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

class LibraryFilamentTest extends PostgresTestCase
{
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installAuthSchema();
        if (! Schema::hasTable('library_items')) {
            (require database_path('migrations/2026_10_27_000200_create_library_items.php'))->up();
        }
        $this->actor = User::findOrFail(DB::table('users')->insertGetId([
            'name' => 'Синтетичний admin бібліотеки', 'role' => 'admin',
        ]));
        $this->actingAs($this->actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_filament_create_publish_unpublish_and_admin_only(): void
    {
        Livewire::test(ListLibraryItems::class)->assertSuccessful();
        Livewire::test(CreateLibraryItem::class)->fillForm([
            'type' => 'article',
            'title' => 'Синтетична стаття',
            'summary' => 'Лише для тесту',
            'content_markdown' => '## Заголовок',
            'external_url' => null,
            'cover_image_url' => null,
            'status' => 'draft',
        ])->call('create')->assertHasNoFormErrors();

        $item = LibraryItem::query()->firstOrFail();
        $this->assertSame('draft', $item->status);
        $this->assertNull($item->published_at);

        Livewire::test(EditLibraryItem::class, ['record' => $item->id])->fillForm([
            'status' => 'published',
        ])->call('save')->assertHasNoFormErrors();
        $this->assertSame('published', $item->fresh()->status);
        $this->assertNotNull($item->fresh()->published_at);

        Livewire::test(EditLibraryItem::class, ['record' => $item->id])->fillForm([
            'status' => 'draft',
        ])->call('save')->assertHasNoFormErrors();
        $this->assertSame('draft', $item->fresh()->status);
        $this->assertNull($item->fresh()->published_at);

        $this->assertFalse(LibraryItemResource::canDelete($item));
        $this->assertFalse(LibraryItemResource::canDeleteAny());

        foreach (['coach', 'client'] as $role) {
            DB::table('users')->where('id', $this->actor->id)->update(['role' => $role]);
            $this->actingAs($this->actor->fresh());
            Livewire::test(CreateLibraryItem::class)->assertForbidden();
            Livewire::test(EditLibraryItem::class, ['record' => $item->id])->assertForbidden();
            $this->assertFalse(LibraryItemResource::canViewAny());
            $this->assertFalse(LibraryItemResource::canCreate());
            $this->assertFalse(LibraryItemResource::canEdit($item));
        }
        $this->assertSame(1, LibraryItem::query()->count());
    }
}
