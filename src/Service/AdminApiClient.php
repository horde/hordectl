<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Hordectl
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Hordectl\Service;

use Horde\Hordectl\Service\AdminApi\ApplicationList;
use Horde\Hordectl\Service\AdminApi\Group;
use Horde\Hordectl\Service\AdminApi\GroupList;
use Horde\Hordectl\Service\AdminApi\HealthCheckResult;
use Horde\Hordectl\Service\AdminApi\HordeInfo;
use Horde\Hordectl\Service\AdminApi\Identity;
use Horde\Hordectl\Service\AdminApi\IdentityList;
use Horde\Hordectl\Service\AdminApi\Permission;
use Horde\Hordectl\Service\AdminApi\PermissionList;
use Horde\Hordectl\Service\AdminApi\User;
use Horde\Hordectl\Service\AdminApi\UserList;
use Horde\Hordectl\Service\AdminApi\RequestFactory\AddGroupMemberRequestFactory;
use Horde\Hordectl\Service\AdminApi\RequestFactory\ApplicationsRequestFactory;
use Horde\Hordectl\Service\AdminApi\RequestFactory\CreateGroupRequestFactory;
use Horde\Hordectl\Service\AdminApi\RequestFactory\CreateIdentityRequestFactory;
use Horde\Hordectl\Service\AdminApi\RequestFactory\CreatePermissionRequestFactory;
use Horde\Hordectl\Service\AdminApi\RequestFactory\CreateUserRequestFactory;
use Horde\Hordectl\Service\AdminApi\RequestFactory\DeleteGroupRequestFactory;
use Horde\Hordectl\Service\AdminApi\RequestFactory\DeleteIdentityRequestFactory;
use Horde\Hordectl\Service\AdminApi\RequestFactory\DeletePermissionRequestFactory;
use Horde\Hordectl\Service\AdminApi\RequestFactory\DeleteUserRequestFactory;
use Horde\Hordectl\Service\AdminApi\RequestFactory\GetGroupMembersRequestFactory;
use Horde\Hordectl\Service\AdminApi\RequestFactory\GetIdentityRequestFactory;
use Horde\Hordectl\Service\AdminApi\RequestFactory\GroupRequestFactory;
use Horde\Hordectl\Service\AdminApi\RequestFactory\GroupsRequestFactory;
use Horde\Hordectl\Service\AdminApi\RequestFactory\HealthCheckRequestFactory;
use Horde\Hordectl\Service\AdminApi\RequestFactory\InfoRequestFactory;
use Horde\Hordectl\Service\AdminApi\RequestFactory\ListIdentitiesRequestFactory;
use Horde\Hordectl\Service\AdminApi\RequestFactory\PatchUserPasswordRequestFactory;
use Horde\Hordectl\Service\AdminApi\RequestFactory\PermissionRequestFactory;
use Horde\Hordectl\Service\AdminApi\RequestFactory\PermissionsRequestFactory;
use Horde\Hordectl\Service\AdminApi\RequestFactory\RemoveGroupMemberRequestFactory;
use Horde\Hordectl\Service\AdminApi\RequestFactory\SetDefaultIdentityRequestFactory;
use Horde\Hordectl\Service\AdminApi\RequestFactory\SetGroupMembersRequestFactory;
use Horde\Hordectl\Service\AdminApi\RequestFactory\UpdateIdentityRequestFactory;
use Horde\Hordectl\Service\AdminApi\RequestFactory\UpdatePermissionRequestFactory;
use Horde\Hordectl\Service\AdminApi\RequestFactory\UserRequestFactory;
use Horde\Hordectl\Service\AdminApi\RequestFactory\UsersRequestFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use Exception;

/**
 * Horde Admin REST API Client
 *
 * PSR-18 HTTP client for Horde admin API endpoints.
 * Authenticates using Bearer token (admin_secret).
 *
 * @category Horde
 * @package  Hordectl
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class AdminApiClient
{
    /**
     * Constructor
     *
     * @param AdminApiConfig $config API configuration (endpoint + secret)
     * @param ClientInterface $httpClient PSR-18 HTTP client
     * @param RequestFactoryInterface $requestFactory PSR-17 request factory
     */
    public function __construct(
        private AdminApiConfig $config,
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory
    ) {}

    /**
     * Get Horde installation info
     *
     * Calls /api/v1/admin/info to retrieve version and paths.
     *
     * @return HordeInfo
     * @throws RuntimeException on API error
     */
    public function getInfo(): HordeInfo
    {
        $factory = new InfoRequestFactory(
            $this->config,
            $this->requestFactory
        );

        $request = $factory->create();
        $response = $this->httpClient->sendRequest($request);

        $this->validateResponse($response, 200);

        $data = json_decode((string) $response->getBody(), true);

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new RuntimeException(
                $this->parseErrorResponse($response)
            );
        }

        return HordeInfo::fromApiResponse($data['data']);
    }

    /**
     * List all applications
     *
     * Calls /api/v1/admin/applications to retrieve application list.
     *
     * @return ApplicationList
     * @throws RuntimeException on API error
     */
    public function listApplications(): ApplicationList
    {
        $factory = new ApplicationsRequestFactory(
            $this->config,
            $this->requestFactory
        );

        $request = $factory->create();
        $response = $this->httpClient->sendRequest($request);

        $this->validateResponse($response, 200);

        $data = json_decode((string) $response->getBody(), true);

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new RuntimeException(
                $this->parseErrorResponse($response)
            );
        }

        return ApplicationList::fromApiResponse($data['data']);
    }

    /**
     * List all users
     *
     * Calls /api/v1/admin/users to retrieve paginated user list.
     *
     * @param int $page Page number (1-indexed)
     * @param int $perPage Users per page (default: 50, max: 100)
     * @return UserList
     * @throws RuntimeException on API error
     */
    public function listUsers(int $page = 1, int $perPage = 50): UserList
    {
        $factory = new UsersRequestFactory(
            $this->config,
            $this->requestFactory,
            $page,
            $perPage
        );

        $request = $factory->create();
        $response = $this->httpClient->sendRequest($request);

        $this->validateResponse($response, 200);

        $data = json_decode((string) $response->getBody(), true);

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new RuntimeException(
                $this->parseErrorResponse($response)
            );
        }

        return UserList::fromApiResponse(
            $data['data'],
            $data['pagination'] ?? []
        );
    }

    /**
     * Get single user by username
     *
     * Calls /api/v1/admin/users/{username} to retrieve user details.
     *
     * @param string $username Username to retrieve
     * @return User
     * @throws RuntimeException on API error
     */
    public function getUser(string $username): User
    {
        $factory = new UserRequestFactory(
            $this->config,
            $this->requestFactory,
            $username
        );

        $request = $factory->create();
        $response = $this->httpClient->sendRequest($request);

        $this->validateResponse($response, 200);

        $data = json_decode((string) $response->getBody(), true);

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new RuntimeException(
                $this->parseErrorResponse($response)
            );
        }

        return User::fromApiResponse($data['data']);
    }

    /**
     * Change user password
     *
     * Calls /api/v1/admin/users/{username}/password to update password.
     *
     * @param string $username Username whose password to change
     * @param string $newPassword New password
     * @return bool True on success
     * @throws RuntimeException on API error
     */
    public function patchUserPassword(string $username, string $newPassword): bool
    {
        // StreamFactory needed for request body
        $streamFactory = $this->requestFactory instanceof StreamFactoryInterface
            ? $this->requestFactory
            : new \Horde\Http\StreamFactory();

        $factory = new PatchUserPasswordRequestFactory(
            $this->config,
            $this->requestFactory,
            $streamFactory,
            $username,
            $newPassword
        );

        $request = $factory->create();
        $response = $this->httpClient->sendRequest($request);

        $this->validateResponse($response, 200);

        $data = json_decode((string) $response->getBody(), true);

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new RuntimeException(
                $this->parseErrorResponse($response)
            );
        }

        return true;
    }

    /**
     * Create new user
     *
     * Calls /api/v1/admin/users (POST) to create a new user.
     *
     * @param string $username Username to create
     * @param string $password User password
     * @return User Created user object
     * @throws RuntimeException on API error
     */
    public function createUser(string $username, string $password): User
    {
        $factory = new CreateUserRequestFactory(
            $this->config,
            $this->requestFactory,
            $username,
            $password
        );

        $request = $factory->create();
        $response = $this->httpClient->sendRequest($request);

        $this->validateResponse($response, 201);

        $data = json_decode((string) $response->getBody(), true);

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new RuntimeException(
                $this->parseErrorResponse($response)
            );
        }

        return User::fromApiResponse($data['data']);
    }

    /**
     * Delete user
     *
     * Calls DELETE /api/v1/admin/users/:username to delete a user.
     *
     * @param string $username Username to delete
     * @return bool True on success
     * @throws RuntimeException on API error
     */
    public function deleteUser(string $username): bool
    {
        $factory = new DeleteUserRequestFactory(
            $this->config,
            $this->requestFactory,
            $username
        );

        $request = $factory->create();
        $response = $this->httpClient->sendRequest($request);

        $this->validateResponse($response, 200);

        $data = json_decode((string) $response->getBody(), true);

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new RuntimeException(
                $this->parseErrorResponse($response)
            );
        }

        return true;
    }

    /**
     * Rotate admin secret
     *
     * Generates new admin_secret, updates Horde config via API,
     * and returns the new secret for saving to hordectl config.
     *
     * @param string $newSecret New admin_secret to set
     * @return bool True on success
     * @throws RuntimeException on API error or not implemented
     */
    public function rotateSecret(string $newSecret): bool
    {
        // TODO: Implement when endpoint exists
        // POST /api/v1/admin/config/rotate-secret
        // Body: { "new_secret": "..." }
        throw new RuntimeException('Secret rotation not yet implemented');
    }

    /**
     * List all identities for user
     *
     * Calls /api/v1/admin/identities/:username to retrieve identity list.
     *
     * @param string $username Username whose identities to list
     * @return IdentityList
     * @throws RuntimeException on API error
     */
    public function listIdentities(string $username): IdentityList
    {
        $factory = new ListIdentitiesRequestFactory(
            $this->config,
            $this->requestFactory,
            $username
        );

        $request = $factory->create();
        $response = $this->httpClient->sendRequest($request);

        $this->validateResponse($response, 200);

        $data = json_decode((string) $response->getBody(), true);

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new RuntimeException(
                $this->parseErrorResponse($response)
            );
        }

        return IdentityList::fromApiResponse($data['data']);
    }

    /**
     * Create identity for user
     *
     * @param string $username Username to create identity for
     * @param array $identityData Identity data (id, from_addr, fullname, etc.)
     * @return Identity Created identity
     * @throws RuntimeException on API error
     */
    public function createIdentity(string $username, array $identityData): Identity
    {
        // StreamFactory needed for request body
        $streamFactory = $this->requestFactory instanceof StreamFactoryInterface
            ? $this->requestFactory
            : new \Horde\Http\StreamFactory();

        $factory = new CreateIdentityRequestFactory(
            $this->config,
            $this->requestFactory,
            $streamFactory,
            $username,
            $identityData
        );

        $request = $factory->create();
        $response = $this->httpClient->sendRequest($request);

        $this->validateResponse($response, 201);

        $data = json_decode((string) $response->getBody(), true);

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new RuntimeException(
                $this->parseErrorResponse($response)
            );
        }

        return Identity::fromApiResponse($data['data']['identity']);
    }

    /**
     * Get single identity by username and index
     *
     * @param string $username Username
     * @param int $index Identity index
     * @return Identity Identity data
     * @throws RuntimeException on API error
     */
    public function getIdentity(string $username, int $index): Identity
    {
        $factory = new GetIdentityRequestFactory(
            $this->config,
            $this->requestFactory,
            $username,
            $index
        );

        $request = $factory->create();
        $response = $this->httpClient->sendRequest($request);

        $this->validateResponse($response, 200);

        $data = json_decode((string) $response->getBody(), true);

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new RuntimeException(
                $this->parseErrorResponse($response)
            );
        }

        return Identity::fromApiResponse($data['data']['identity']);
    }

    /**
     * Update identity for user
     *
     * @param string $username Username
     * @param int $index Identity index
     * @param array $identityData Partial identity data to update
     * @return Identity Updated identity
     * @throws RuntimeException on API error
     */
    public function updateIdentity(string $username, int $index, array $identityData): Identity
    {
        // StreamFactory needed for request body
        $streamFactory = $this->requestFactory instanceof StreamFactoryInterface
            ? $this->requestFactory
            : new \Horde\Http\StreamFactory();

        $factory = new UpdateIdentityRequestFactory(
            $this->config,
            $this->requestFactory,
            $streamFactory,
            $username,
            $index,
            $identityData
        );

        $request = $factory->create();
        $response = $this->httpClient->sendRequest($request);

        $this->validateResponse($response, 200);

        $data = json_decode((string) $response->getBody(), true);

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new RuntimeException(
                $this->parseErrorResponse($response)
            );
        }

        return Identity::fromApiResponse($data['data']['identity']);
    }

    /**
     * Delete identity for user
     *
     * @param string $username Username
     * @param int $index Identity index to delete
     * @return bool True on success
     * @throws RuntimeException on API error
     */
    public function deleteIdentity(string $username, int $index): bool
    {
        $factory = new DeleteIdentityRequestFactory(
            $this->config,
            $this->requestFactory,
            $username,
            $index
        );

        $request = $factory->create();
        $response = $this->httpClient->sendRequest($request);

        $this->validateResponse($response, 200);

        $data = json_decode((string) $response->getBody(), true);

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new RuntimeException(
                $this->parseErrorResponse($response)
            );
        }

        return true;
    }

    /**
     * Set default identity for user
     *
     * @param string $username Username
     * @param int $index Identity index to set as default
     * @return bool True on success
     * @throws RuntimeException on API error
     */
    public function setDefaultIdentity(string $username, int $index): bool
    {
        $factory = new SetDefaultIdentityRequestFactory(
            $this->config,
            $this->requestFactory,
            $username,
            $index
        );

        $request = $factory->create();
        $response = $this->httpClient->sendRequest($request);

        $this->validateResponse($response, 200);

        $data = json_decode((string) $response->getBody(), true);

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new RuntimeException(
                $this->parseErrorResponse($response)
            );
        }

        return true;
    }

    /**
     * List all groups
     *
     * Calls /api/v1/admin/groups to retrieve paginated group list.
     *
     * @param int $page Page number (1-indexed)
     * @param int $perPage Groups per page (default: 50, max: 100)
     * @return GroupList
     * @throws RuntimeException on API error
     */
    public function listGroups(int $page = 1, int $perPage = 50): GroupList
    {
        $factory = new GroupsRequestFactory(
            $this->config,
            $this->requestFactory,
            $page,
            $perPage
        );

        $request = $factory->create();
        $response = $this->httpClient->sendRequest($request);

        $this->validateResponse($response, 200);

        $data = json_decode((string) $response->getBody(), true);

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new RuntimeException(
                $this->parseErrorResponse($response)
            );
        }

        return GroupList::fromApiResponse(
            $data['data'],
            $data['pagination'] ?? []
        );
    }

    /**
     * Get single group by identifier
     *
     * Calls /api/v1/admin/groups/{identifier} to retrieve group details.
     *
     * @param string $identifier Group ID or name
     * @return Group
     * @throws RuntimeException on API error
     */
    public function getGroup(string $identifier): Group
    {
        $factory = new GroupRequestFactory(
            $this->config,
            $this->requestFactory,
            $identifier
        );

        $request = $factory->create();
        $response = $this->httpClient->sendRequest($request);

        $this->validateResponse($response, 200);

        $data = json_decode((string) $response->getBody(), true);

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new RuntimeException(
                $this->parseErrorResponse($response)
            );
        }

        return Group::fromApiResponse($data['data']);
    }

    /**
     * Create new group
     *
     * Calls /api/v1/admin/groups to create a new group.
     *
     * @param string $name Group name
     * @return Group Created group
     * @throws RuntimeException on API error
     */
    public function createGroup(string $name): Group
    {
        // StreamFactory needed for request body
        $streamFactory = $this->requestFactory instanceof StreamFactoryInterface
            ? $this->requestFactory
            : new \Horde\Http\StreamFactory();

        $factory = new CreateGroupRequestFactory(
            $this->config,
            $this->requestFactory,
            $streamFactory,
            $name
        );

        $request = $factory->create();
        $response = $this->httpClient->sendRequest($request);

        $this->validateResponse($response, 201);

        $data = json_decode((string) $response->getBody(), true);

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new RuntimeException(
                $this->parseErrorResponse($response)
            );
        }

        return Group::fromApiResponse($data['data']);
    }

    /**
     * Delete group
     *
     * @param string $identifier Group ID or name to delete
     * @return bool True on success
     * @throws RuntimeException on API error
     */
    public function deleteGroup(string $identifier): bool
    {
        $factory = new DeleteGroupRequestFactory(
            $this->config,
            $this->requestFactory,
            $identifier
        );

        $request = $factory->create();
        $response = $this->httpClient->sendRequest($request);

        $this->validateResponse($response, 200);

        $data = json_decode((string) $response->getBody(), true);

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new RuntimeException(
                $this->parseErrorResponse($response)
            );
        }

        return true;
    }

    /**
     * Get group members
     *
     * @param string $identifier Group ID or name
     * @return array List of usernames
     * @throws RuntimeException on API error
     */
    public function getGroupMembers(string $identifier): array
    {
        $factory = new GetGroupMembersRequestFactory(
            $this->config,
            $this->requestFactory,
            $identifier
        );

        $request = $factory->create();
        $response = $this->httpClient->sendRequest($request);

        $this->validateResponse($response, 200);

        $data = json_decode((string) $response->getBody(), true);

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new RuntimeException(
                $this->parseErrorResponse($response)
            );
        }

        return $data['data'] ?? [];
    }

    /**
     * Add member to group
     *
     * @param string $identifier Group ID or name
     * @param string $username Username to add
     * @return Group Updated group with members
     * @throws RuntimeException on API error
     */
    public function addGroupMember(string $identifier, string $username): Group
    {
        // StreamFactory needed for request body
        $streamFactory = $this->requestFactory instanceof StreamFactoryInterface
            ? $this->requestFactory
            : new \Horde\Http\StreamFactory();

        $factory = new AddGroupMemberRequestFactory(
            $this->config,
            $this->requestFactory,
            $streamFactory,
            $identifier,
            $username
        );

        $request = $factory->create();
        $response = $this->httpClient->sendRequest($request);

        $this->validateResponse($response, 200);

        $data = json_decode((string) $response->getBody(), true);

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new RuntimeException(
                $this->parseErrorResponse($response)
            );
        }

        return Group::fromApiResponse($data['data']);
    }

    /**
     * Remove member from group
     *
     * @param string $identifier Group ID or name
     * @param string $username Username to remove
     * @return Group Updated group with members
     * @throws RuntimeException on API error
     */
    public function removeGroupMember(string $identifier, string $username): Group
    {
        $factory = new RemoveGroupMemberRequestFactory(
            $this->config,
            $this->requestFactory,
            $identifier,
            $username
        );

        $request = $factory->create();
        $response = $this->httpClient->sendRequest($request);

        $this->validateResponse($response, 200);

        $data = json_decode((string) $response->getBody(), true);

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new RuntimeException(
                $this->parseErrorResponse($response)
            );
        }

        return Group::fromApiResponse($data['data']);
    }

    /**
     * Set group members (replace all)
     *
     * Calls /api/v1/admin/groups/{identifier}/members to replace all members.
     *
     * @param string $identifier Group ID or name
     * @param array $members List of usernames
     * @return bool True on success
     * @throws RuntimeException on API error
     */
    public function setGroupMembers(string $identifier, array $members): bool
    {
        // StreamFactory needed for request body
        $streamFactory = $this->requestFactory instanceof StreamFactoryInterface
            ? $this->requestFactory
            : new \Horde\Http\StreamFactory();

        $factory = new SetGroupMembersRequestFactory(
            $this->config,
            $this->requestFactory,
            $streamFactory,
            $identifier,
            $members
        );

        $request = $factory->create();
        $response = $this->httpClient->sendRequest($request);

        $this->validateResponse($response, 200);

        $data = json_decode((string) $response->getBody(), true);

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new RuntimeException(
                $this->parseErrorResponse($response)
            );
        }

        return true;
    }

    /**
     * List all permissions
     *
     * Calls /api/v1/admin/permissions to get all permissions.
     *
     * @return PermissionList List of permissions
     * @throws RuntimeException on API error
     */
    public function listPermissions(): PermissionList
    {
        $factory = new PermissionsRequestFactory(
            $this->config,
            $this->requestFactory
        );

        $request = $factory->create();
        $response = $this->httpClient->sendRequest($request);

        $this->validateResponse($response, 200);

        $data = json_decode((string) $response->getBody(), true);

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new RuntimeException(
                $this->parseErrorResponse($response)
            );
        }

        return PermissionList::fromApiResponse($data['data']);
    }

    /**
     * Get permission by name
     *
     * Calls /api/v1/admin/permissions/{name} to get permission details.
     *
     * @param string $name Permission name
     * @return Permission Permission details
     * @throws RuntimeException on API error
     */
    public function getPermission(string $name): Permission
    {
        $factory = new PermissionRequestFactory(
            $this->config,
            $this->requestFactory,
            $name
        );

        $request = $factory->create();
        $response = $this->httpClient->sendRequest($request);

        $this->validateResponse($response, 200);

        $data = json_decode((string) $response->getBody(), true);

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new RuntimeException(
                $this->parseErrorResponse($response)
            );
        }

        return Permission::fromApiResponse($data['data']);
    }

    /**
     * Create new permission
     *
     * Calls /api/v1/admin/permissions to create a permission.
     *
     * @param string $name Permission name
     * @param string $type Permission type (matrix, boolean, int)
     * @param array $data Permission data (users, groups, default, guest, creator)
     * @return Permission Created permission
     * @throws RuntimeException on API error
     */
    public function createPermission(string $name, string $type, array $data): Permission
    {
        // StreamFactory needed for request body
        $streamFactory = $this->requestFactory instanceof StreamFactoryInterface
            ? $this->requestFactory
            : new \Horde\Http\StreamFactory();

        $factory = new CreatePermissionRequestFactory(
            $this->config,
            $this->requestFactory,
            $streamFactory,
            $name,
            $type,
            $data
        );

        $request = $factory->create();
        $response = $this->httpClient->sendRequest($request);

        $this->validateResponse($response, 201);

        $responseData = json_decode((string) $response->getBody(), true);

        if (!isset($responseData['success']) || $responseData['success'] !== true) {
            throw new RuntimeException(
                $this->parseErrorResponse($response)
            );
        }

        return Permission::fromApiResponse($responseData['data']);
    }

    /**
     * Update existing permission
     *
     * Calls /api/v1/admin/permissions/{name} to update permission data.
     *
     * @param string $name Permission name
     * @param array $data Permission data to update (users, groups, default, guest, creator)
     * @return Permission Updated permission
     * @throws RuntimeException on API error
     */
    public function updatePermission(string $name, array $data): Permission
    {
        // StreamFactory needed for request body
        $streamFactory = $this->requestFactory instanceof StreamFactoryInterface
            ? $this->requestFactory
            : new \Horde\Http\StreamFactory();

        $factory = new UpdatePermissionRequestFactory(
            $this->config,
            $this->requestFactory,
            $streamFactory,
            $name,
            $data
        );

        $request = $factory->create();
        $response = $this->httpClient->sendRequest($request);

        $this->validateResponse($response, 200);

        $responseData = json_decode((string) $response->getBody(), true);

        if (!isset($responseData['success']) || $responseData['success'] !== true) {
            throw new RuntimeException(
                $this->parseErrorResponse($response)
            );
        }

        return Permission::fromApiResponse($responseData['data']);
    }

    /**
     * Delete permission
     *
     * @param string $name Permission name to delete
     * @return bool True on success
     * @throws RuntimeException on API error
     */
    public function deletePermission(string $name): bool
    {
        $factory = new DeletePermissionRequestFactory(
            $this->config,
            $this->requestFactory,
            $name
        );

        $request = $factory->create();
        $response = $this->httpClient->sendRequest($request);

        $this->validateResponse($response, 200);

        $data = json_decode((string) $response->getBody(), true);

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new RuntimeException(
                $this->parseErrorResponse($response)
            );
        }

        return true;
    }

    /**
     * Check health of Horde subsystem
     *
     * @param string $subsystem Subsystem to check (db, cache, session, logger, auth, jwt, all)
     * @return HealthCheckResult Health check result
     * @throws RuntimeException on API error
     */
    public function checkHealth(string $subsystem = 'all'): HealthCheckResult
    {
        $factory = new HealthCheckRequestFactory(
            $this->config,
            $this->requestFactory,
            $subsystem
        );

        $request = $factory->create();
        $response = $this->httpClient->sendRequest($request);

        $this->validateResponse($response, 200);

        $data = json_decode((string) $response->getBody(), true);

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new RuntimeException(
                $this->parseErrorResponse($response)
            );
        }

        return HealthCheckResult::fromApiResponse($data['data']);
    }

    /**
     * Check health of all subsystems
     *
     * @return array<string, HealthCheckResult> Map of subsystem name to health check result
     * @throws RuntimeException on API error
     */
    public function checkAllHealth(): array
    {
        $factory = new HealthCheckRequestFactory(
            $this->config,
            $this->requestFactory,
            'all'
        );

        $request = $factory->create();
        $response = $this->httpClient->sendRequest($request);

        $this->validateResponse($response, 200);

        $data = json_decode((string) $response->getBody(), true);

        if (!isset($data['success']) || $data['success'] !== true) {
            throw new RuntimeException(
                $this->parseErrorResponse($response)
            );
        }

        // Convert each subsystem result to HealthCheckResult
        $results = [];
        foreach ($data['data'] as $subsystem => $result) {
            $results[$subsystem] = HealthCheckResult::fromApiResponse($result);
        }

        return $results;
    }

    /**
     * Validate HTTP response status code
     *
     * @param ResponseInterface $response HTTP response
     * @param int $expectedStatus Expected status code
     * @throws RuntimeException if status doesn't match
     */
    private function validateResponse(
        ResponseInterface $response,
        int $expectedStatus
    ): void {
        if ($response->getStatusCode() !== $expectedStatus) {
            throw new RuntimeException(
                $this->parseErrorResponse($response)
            );
        }
    }

    /**
     * Parse error response from Horde admin API
     *
     * Extracts error details from JSON response body.
     *
     * @param ResponseInterface $response HTTP response
     * @return string Error message
     */
    private function parseErrorResponse(ResponseInterface $response): string
    {
        $statusCode = $response->getStatusCode();
        $reasonPhrase = $response->getReasonPhrase();

        try {
            $body = json_decode((string) $response->getBody(), true);

            if (isset($body['error']['message'])) {
                return sprintf(
                    '%d %s: %s (code: %s)',
                    $statusCode,
                    $reasonPhrase,
                    $body['error']['message'],
                    $body['error']['code'] ?? 'UNKNOWN'
                );
            }
        } catch (Exception $e) {
            // JSON parse failed, use generic message
        }

        return sprintf('%d %s', $statusCode, $reasonPhrase);
    }
}
