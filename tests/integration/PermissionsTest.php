<?php

namespace A17\Twill\Tests\Integration;

use A17\Twill\Models\User;
use A17\Twill\Models\Role;
use A17\Twill\Models\Group;
use A17\Twill\PermissionAuthServiceProvider;
use Illuminate\Support\Facades\Mail;

class PermissionsTest extends PermissionsTestBase
{
    protected function getPackageProviders($app)
    {
        // This config must be set before loading TwillServiceProvider to select
        // between AuthServiceProvider and PermissionAuthServiceProvider
        $app['config']->set('twill.enabled.permissions-management', true);

        PermissionAuthServiceProvider::disableCache();

        return parent::getPackageProviders($app);
    }

    public function createUser($role, $group=null)
    {
        $user = $this->makeUser();
        $user->role_id = $role->id;
        $user->save();

        if ($group) {
            $group->users()->attach($user->id);
        }

        return $user;
    }

    public function createRole($roleName)
    {
        return Role::create([
            'name' => $roleName,
            'published' => true,
            'in_everyone_group' => true,
        ]);
    }

    public function createGroup($groupName)
    {
        return Group::create([
            'name' => $groupName,
            'published' => true,
        ]);
    }

    public function withGlobalPermission($target, $permissionName, $callback)
    {
        $target->grantGlobalPermission($permissionName);

        $callback();

        $target->revokeGlobalPermission($permissionName);
    }

    public function withModulePermission($target, $module, $permissionName, $callback)
    {
        $model = getModelByModuleName($module);

        $target->grantModulePermission($permissionName, $model);

        $callback();

        $target->revokeModulePermission($permissionName, $model);
    }

    public function withItemPermission($target, $item, $permissionName, $callback)
    {
        $target->grantModuleItemPermission($permissionName, $item);

        $callback();

        $target->revokeModuleItemPermission($permissionName, $item);
    }

    public function testRolePermissions()
    {
        app('config')->set('twill.permissions.level', 'role');

        $role = $this->createRole('Tester');
        $user = $this->createUser($role);

        $tempRole = $this->createRole('Temporary');
        $tempUser = $this->createUser($tempRole);

        // User is logged in
        $this->loginUser($user);

        // User can access settings if permitted
        $this->httpRequestAssert('/twill/settings/seo', 'GET', [], 403);
        $this->withGlobalPermission($role, 'edit-settings', function () {
            $this->httpRequestAssert('/twill/settings/seo', 'GET', [], 200);
        });

        // User can access media library & files if permitted
        $this->httpRequestAssert('/twill/media-library/medias?page=1&type=image', 'GET', [], 403);
        $this->httpRequestAssert('/twill/file-library/files?page=1', 'GET', [], 403);
        $this->withGlobalPermission($role, 'access-media-library', function () {
            $this->httpRequestAssert('/twill/media-library/medias?page=1&type=image', 'GET', [], 200);
            $this->httpRequestAssert('/twill/file-library/files?page=1', 'GET', [], 200);
        });

        // User can edit media library & files if permitted
        $this->httpRequestAssert('/twill/media-library/medias', 'POST', [], 403);
        $this->httpRequestAssert('/twill/file-library/files', 'POST', [], 403);
        $this->withGlobalPermission($role, 'edit-media-library', function () {
            $this->httpRequestAssert('/twill/media-library/medias', 'POST', [], 200);
            $this->httpRequestAssert('/twill/file-library/files', 'POST', [], 200);
        });

        // User can access own profile
        $this->httpRequestAssert("/twill/users/{$user->id}/edit", 'GET', [], 200);

        // User can't access other profiles
        $this->httpRequestAssert("/twill/users/{$tempUser->id}/edit", 'GET', [], 403);

        // User can edit users if permitted
        $this->httpRequestAssert("/twill/users", 'GET', [], 403);
        $this->withGlobalPermission($role, 'edit-users', function () use ($tempUser) {
            $this->httpRequestAssert("/twill/users", 'GET', [], 200);
            $this->httpRequestAssert("/twill/users/{$tempUser->id}/edit", 'GET', [], 200);
        });

        // User can edit roles if permitted
        $this->httpRequestAssert("/twill/roles", 'GET', [], 403);
        $this->withGlobalPermission($role, 'edit-user-roles', function () use ($tempRole) {
            $this->httpRequestAssert("/twill/roles", 'GET', [], 200);
            $this->httpRequestAssert("/twill/roles/{$tempRole->id}/edit", 'GET', [], 200);
        });

        // User can't access groups list (feature not enabled with `twill.permissions.level` === 'role')
        $this->httpRequestAssert("/twill/groups", 'GET', [], 403);


        $post = $this->createPost();

        // User can access items list if permitted
        $this->httpRequestAssert("/twill/posts", 'GET', [], 403);
        $this->withModulePermission($role, 'posts', 'view-module', function () {
            $this->httpRequestAssert("/twill/posts", 'GET', [], 200);
        });

        // User can edit item if permitted
        $this->httpRequestAssert("/twill/posts/{$post->id}/edit", 'GET', [], 403);
        $this->withModulePermission($role, 'posts', 'edit-module', function () use ($post) {
            $this->httpRequestAssert("/twill/posts/{$post->id}/edit", 'GET', [], 200);
        });

        // User can create item if permitted
        $this->httpRequestAssert('/twill/posts', 'POST', [], 403);
        $this->withModulePermission($role, 'posts', 'edit-module', function () {
            $this->httpRequestAssert('/twill/posts', 'POST', [], 200);
        });

        // User can manage modules if permitted
        $this->httpRequestAssert("/twill/posts/{$post->id}/edit", 'GET', [], 403);
        $this->httpRequestAssert('/twill/posts', 'POST', [], 403);
        $this->withGlobalPermission($role, 'manage-modules', function () use ($post) {
            $this->httpRequestAssert("/twill/posts/{$post->id}/edit", 'GET', [], 200);
            $this->httpRequestAssert('/twill/posts', 'POST', [], 200);
        });
    }

    public function testGroupPermissions()
    {
        app('config')->set('twill.permissions.level', 'roleGroup');

        $role = $this->createRole('Tester');
        $group = $this->createGroup('Beta');
        $user = $this->createUser($role, $group);

        // User is logged in
        $this->loginUser($user);

        // User role can edit groups if permitted
        $this->httpRequestAssert("/twill/groups", 'GET', [], 403);
        $this->httpRequestAssert("/twill/groups/{$group->id}/edit", 'GET', [], 403);
        $this->withGlobalPermission($role, 'edit-user-groups', function () use ($group) {
            $this->httpRequestAssert("/twill/groups", 'GET', [], 200);
            $this->httpRequestAssert("/twill/groups/{$group->id}/edit", 'GET', [], 200);
        });

        // User role can't edit Everyone group
        $this->withGlobalPermission($role, 'edit-user-groups', function () {
            $everyoneGroup = Group::getEveryoneGroup();
            $this->httpRequestAssert("/twill/groups/{$everyoneGroup->id}/edit", 'GET', [], 403);
        });


        $post = $this->createPost();

        // User group can access items list if permitted
        $this->httpRequestAssert("/twill/posts", 'GET', [], 403);
        $this->withModulePermission($group, 'posts', 'view-module', function () {
            $this->httpRequestAssert("/twill/posts", 'GET', [], 200);
        });

        // User group can edit item if permitted
        $this->httpRequestAssert("/twill/posts/{$post->id}/edit", 'GET', [], 403);
        $this->withModulePermission($group, 'posts', 'edit-module', function () use ($post) {
            $this->httpRequestAssert("/twill/posts/{$post->id}/edit", 'GET', [], 200);
        });

        // User group can create item if permitted
        $this->httpRequestAssert('/twill/posts', 'POST', [], 403);
        $this->withModulePermission($group, 'posts', 'edit-module', function () {
            $this->httpRequestAssert('/twill/posts', 'POST', [], 200);
        });
    }

    public function testModulePermissions()
    {
        app('config')->set('twill.permissions.level', 'roleGroupModule');

        $role = $this->createRole('Tester');
        $group = $this->createGroup('Beta');
        $user = $this->createUser($role, $group);

        // User is logged in
        $this->loginUser($user);


        $post = $this->createPost();

        // User role can manage module if permitted
        $this->httpRequestAssert("/twill/posts/{$post->id}/edit", 'GET', [], 403);
        $this->httpRequestAssert('/twill/posts', 'POST', [], 403);
        $this->withModulePermission($role, 'posts', 'manage-module', function () use ($post) {
            $this->httpRequestAssert("/twill/posts/{$post->id}/edit", 'GET', [], 200);
            $this->httpRequestAssert('/twill/posts', 'POST', [], 200);
        });

        // User group can access items list if permitted
        $this->httpRequestAssert("/twill/posts", 'GET', [], 403);
        $this->withItemPermission($group, $post, 'view-item', function () {
            $this->httpRequestAssert("/twill/posts", 'GET', [], 200);
        });

        // User group can edit item if permitted
        $this->httpRequestAssert("/twill/posts/{$post->id}/edit", 'GET', [], 403);
        $this->withItemPermission($group, $post, 'edit-item', function () use ($post) {
            $this->httpRequestAssert("/twill/posts/{$post->id}/edit", 'GET', [], 200);
        });
    }

    public function testUserModulePermissions()
    {
        app('config')->set('twill.permissions.level', 'roleGroupModule');

        $role = $this->createRole('Tester');
        $user = $this->createUser($role);

        // User is logged in
        $this->loginUser($user);


        $post = $this->createPost();

        // User can access items list if permitted
        $this->httpRequestAssert("/twill/posts", 'GET', [], 403);
        $this->withItemPermission($user, $post, 'view-item', function () {
            $this->httpRequestAssert("/twill/posts", 'GET', [], 200);
        });

        // User can edit item if permitted
        $this->httpRequestAssert("/twill/posts/{$post->id}/edit", 'GET', [], 403);
        $this->withItemPermission($user, $post, 'edit-item', function () use ($post) {
            $this->httpRequestAssert("/twill/posts/{$post->id}/edit", 'GET', [], 200);
        });
    }

    public function testEveryoneGroup()
    {
        Mail::fake();

        app('config')->set('twill.permissions.level', 'roleGroupModule');

        $everyoneGroup = Group::getEveryoneGroup();

        $userRole = $this->createRole('Tester');
        $userRole->grantGlobalPermission('edit-users');
        $userRole->grantGlobalPermission('edit-user-roles');
        $user = $this->createUser($userRole);

        // User is logged in
        $this->loginUser($user);

        // Everyone group is empty
        $everyoneGroup->users()->detach();
        $this->assertEquals(0, $everyoneGroup->users()->count());

        $newRole = $this->createRole('New Role');

        // A new user with a role in Everyone group is automatically added to the group
        $this->httpRequestAssert('/twill/users', 'POST', [
            'name' => 'Bob',
            'email' => 'bob@test.test',
            'role_id' => $newRole->id,
            'published' => true,
        ], 200);
        $this->assertEquals(1, $everyoneGroup->users()->count());

        // Removing a role from Everyone group removes all users from the group
        $this->httpRequestAssert("/twill/roles/{$newRole->id}", 'PUT', [
            'name' => 'Tester',
            'published' => true,
            'in_everyone_group' => false,
        ], 200);
        $this->assertEquals(0, $everyoneGroup->users()->count());

        // Adding a role in Everyone group adds all users to the group
        $this->httpRequestAssert("/twill/roles/{$newRole->id}", 'PUT', [
            'name' => 'Tester',
            'published' => true,
            'in_everyone_group' => true,
        ], 200);
        $this->assertEquals(1, $everyoneGroup->users()->count());
    }

    public function testRoleBasedAccessLevel()
    {
        app('config')->set('twill.permissions.level', 'roleGroupModule');

        Role::truncate();
        User::truncate();

        list($role1, $role2, $role3) = collect([1, 2, 3])->map(function ($i) {
            $role = $this->createRole("Role $i");
            $role->position = $i;
            $role->save();

            $role->grantGlobalPermission('edit-users');
            $role->grantGlobalPermission('edit-user-roles');

            return $role;
        })->all();

        $userRole1 = $this->createUser($role1);
        $userRole2_A = $this->createUser($role2);
        $userRole2_B = $this->createUser($role2);
        $userRole3 = $this->createUser($role3);

        $this->assertEquals(3, Role::count());
        $this->assertEquals(4, User::count());


        // User is logged in
        $this->loginUser($userRole2_A);

        // User can't edit higher level roles
        $this->httpRequestAssert("/twill/roles/{$role1->id}/edit", 'GET', [], 403);

        // User can edit equal or lower level roles
        $this->httpRequestAssert("/twill/roles/{$role2->id}/edit", 'GET', [], 200);
        $this->httpRequestAssert("/twill/roles/{$role3->id}/edit", 'GET', [], 200);

        // User can't edit higher level users
        $this->httpRequestAssert("/twill/users/{$userRole1->id}/edit", 'GET', [], 403);

        // User can edit equal or lower level users
        $this->httpRequestAssert("/twill/users/{$userRole2_B->id}/edit", 'GET', [], 200);
        $this->httpRequestAssert("/twill/users/{$userRole3->id}/edit", 'GET', [], 200);

        // User can't assign higher level roles
        $this->httpRequestAssert("/twill/users", 'POST', [
            'name' => 'Test',
            'email' => 'test@test.test',
            'role_id' => $role1->id,
            'published' => true,
        ]);
        $this->assertSee('The selected role id is invalid');
        $this->assertEquals(4, User::count());

        // User can assign equal level roles
        $this->httpRequestAssert("/twill/users", 'POST', [
            'name' => 'Test',
            'email' => 'test@test.test',
            'role_id' => $role2->id,
            'published' => true,
        ]);
        $this->assertDontSee('The selected role id is invalid');
        $this->assertEquals(5, User::count());

        // User can assign lower level roles
        $this->httpRequestAssert("/twill/users", 'POST', [
            'name' => 'Test2',
            'email' => 'test2@test.test',
            'role_id' => $role3->id,
            'published' => true,
        ]);
        $this->assertDontSee('The selected role id is invalid');
        $this->assertEquals(6, User::count());
    }
}