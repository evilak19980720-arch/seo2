<?php

namespace Tests\Feature;

use App\Filament\Pages\MenuManagement;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\ArticleTasks\ArticleTaskResource;
use App\Filament\Resources\Sites\SiteResource;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use App\Support\Settings;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminMenuUsersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($this->admin);
    }

    public function test_menu_names_groups_and_order_can_be_edited(): void
    {
        $this->assertArrayHasKey('site_resource', MenuManagement::items());

        Livewire::test(MenuManagement::class)
            ->set('data.groups.系统', '系统设置')
            ->set('data.items.site_resource.label', '我的站点')
            ->set('data.items.site_resource.sort', '9')
            ->set('data.items.article_resource.label', '文章列表')
            ->set('data.items.article_resource.group', '站点')
            ->call('save');

        $this->assertSame('我的站点', SiteResource::getNavigationLabel());
        $this->assertSame(9, SiteResource::getNavigationSort());
        $this->assertSame('站点', ArticleResource::getNavigationGroup());
        $this->assertSame('文章列表', ArticleTaskResource::getNavigationParentItem(), 'the queue stays under the renamed item');
        $this->assertSame('系统设置', MenuManagement::getNavigationGroup());
        $this->assertSame(['系统' => '系统设置'], Settings::get('admin_menu')['groups']);

        $this->get('https://admin.test/admin/menu')->assertOk()->assertSee('我的站点')->assertSee('系统设置');

        Livewire::test(MenuManagement::class)->callAction('reset');
        $this->assertSame([], Settings::get('admin_menu'));
    }

    public function test_group_names_must_stay_unique(): void
    {
        Livewire::test(MenuManagement::class)->set('data.groups.系统', '站点')->call('save');

        $this->assertSame([], Settings::get('admin_menu'));
    }

    public function test_admin_accounts_can_be_managed(): void
    {
        $this->get('https://admin.test/admin/users')->assertOk()->assertSee($this->admin->email);

        Livewire::test(ManageUsers::class)
            ->callAction('create', ['name' => 'Ana', 'email' => 'ana@test.dev', 'role' => User::ROLE_EDITOR, 'password' => 'secret123'])
            ->assertHasNoActionErrors();
        $ana = User::where('email', 'ana@test.dev')->sole();
        $this->assertTrue(password_verify('secret123', $ana->password));
        $this->assertFalse($ana->isAdmin());

        Livewire::test(ManageUsers::class)
            ->callAction(TestAction::make(EditAction::getDefaultName())->table($ana), ['name' => 'Ana B', 'password' => ''])
            ->assertHasNoActionErrors();
        $this->assertSame('Ana B', $ana->fresh()->name);
        $this->assertTrue(password_verify('secret123', $ana->fresh()->password), 'an empty password keeps the old one');

        Livewire::test(ManageUsers::class)->callAction(TestAction::make(DeleteAction::getDefaultName())->table($ana));
        $this->assertNull(User::find($ana->id));

        $this->actingAs(User::factory()->create(['role' => User::ROLE_EDITOR]));
        $this->get('https://admin.test/admin/users')->assertForbidden();
    }

    public function test_the_last_admin_keeps_the_role(): void
    {
        Livewire::test(ManageUsers::class)
            ->callAction(TestAction::make(EditAction::getDefaultName())->table($this->admin), ['role' => User::ROLE_EDITOR])
            ->assertHasActionErrors(['role']);

        $this->assertTrue($this->admin->fresh()->isAdmin());
    }
}
