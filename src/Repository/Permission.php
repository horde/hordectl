<?php

namespace Horde\Hordectl\Repository;

use Horde_Core_Perms;
use Horde_Perms_Base;

/**
 * Resource Group handles querying and formatting
 * group representations
 */
class Permission
{
    private $_perms;
    private $_corePerms;
    private $_groupRepo;

    public function __construct(Horde_Perms_Base $perms, Horde_Core_Perms $corePerms, Group $group)
    {
        $this->_perms = $perms;
        $this->_corePerms = $corePerms;
        $this->_groupRepo = $group;
    }
    public function export()
    {
        $items = [];
        foreach ($this->_perms->getTree() as $permId => $permName) {
            // Filter parent node
            if ($permName == "-1") {
                continue;
            }
            $permission = $this->_perms->getPermission($permName);
            $data = $permission->getData();
            $groupPerms = $this->_exportGroupPermissions($permission);

            $items[] = [
                //    'permId' => $permId,
                'permName' => $permName,
                'type' => $data['type'],
                'users' => $data['users'] ?? [],
                'groups' => $groupPerms,
                'default' => $permission->getDefaultPermissions(),
                'guest' => $permission->getGuestPermissions(),
                'creator' => $permission->getCreatorPermissions(),
            ];
        }
        return $items;
    }

    protected function _exportGroupPermissions($permission): array
    {
        $perms = [];
        foreach ($permission->getGroupPermissions() as $gid => $level) {
            if (!$this->_groupRepo->exists($gid)) {
                continue;
            }
            $perms[$this->_groupRepo->getGroupNameById($gid)] = $level;
        }
        return $perms;
    }

    /**
     * Import a single permission
     *
     * This will overwrite the permission with the definition from yaml.
     */
    public function import(array $item)
    {
        // Get group id's from group names for the backend
        if (isset($item['groups'])) {
            $item['groups'] = $this->_importGroupPermissions($item['groups']);
        }
        $propKeys = array_flip(
            ['creator', 'default', 'guest', 'users', 'groups', 'type']
        );
        // Don't spill unrelated keys into the permission's data section
        $props = array_intersect_key($item, $propKeys);
        if ($this->_perms->exists($item['permName'])) {
            $permission = $this->_perms->getPermission($item['permName']);
            $permission->data = $props;
            $permission->save();
        } else {
            $permission = $this->_perms->newPermission($item['permName'], $item['type']);
            $permission->data = $props;
            $this->_perms->addPermission($permission);
        }
    }

    private function _importGroupPermissions(array $groupPerms)
    {
        $internalFormat = [];
        foreach ($groupPerms as $groupName => $level) {
            $gid = (string) $this->_groupRepo->getGroupIdByName($groupName);
            // If the group is not present, skip it
            if (empty($gid)) {
                continue;
            }
            $internalFormat[$gid] = $level;
        }
        return $internalFormat;
    }
}
