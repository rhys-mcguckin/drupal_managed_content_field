<?php

namespace Drupal\Tests\managed_content_field\Traits;

trait LoginAdminTrait {

  protected $admin_user;

  /**
   * Creates an user with admin permissions and log in.
   *
   * @param array $additional_permissions
   *   Additional permissions that will be granted to admin user.
   * @param bool $reset_permissions
   *   Flag to determine if default admin permissions will be replaced by
   *   $additional_permissions.
   *
   * @return object
   *   Newly created and logged in user object.
   */
  public function loginAsAdmin($additional_permissions = [], $reset_permissions = FALSE) {
    $permissions = [
      'administer content types',
      'administer nodes',
      'administer node fields',
      'administer node display',
      'administer node form display',
    ];

    if ($reset_permissions) {
      $permissions = $additional_permissions;
    }
    elseif (!empty($additional_permissions)) {
      $permissions = array_merge($permissions, $additional_permissions);
    }

    $this->admin_user = $this->drupalCreateUser($permissions);
    $this->drupalLogin($this->admin_user);
    return $this->admin_user;
  }

}
