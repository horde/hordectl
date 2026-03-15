<?php

namespace Horde\Hordectl\Repository;

use Horde_Group_Base as GroupDriver;

/**
 * Resource Group handles querying and formatting
 * group representations
 */
class Group
{
    private $_driver;
    public function __construct(GroupDriver $driver)
    {
        $this->_driver = $driver;
    }

    /**
     * Export Group names and members
     *
     * We don't export IDs as these don't reproduce well
     * TODO: handle email
     *
     */
    public function export()
    {
        $items = [];
        foreach ($this->_driver->listAll() as $groupId => $groupName) {
            $items[] = [
                'groupName' => $groupName,
                'groupMembers' => $this->_driver->listUsers($groupId),
            ];
        }
        return $items;
    }

    public function import(array $item)
    {
        // check if driver is readonly
        if ($this->_driver->readOnly()) {
            return;
        }
        // get gid if item already exists
        $gid = $this->getGroupIdByName($item['groupName']);
        // else create and get key
        if (empty($gid)) {
            $gid = $this->_driver->create($item['groupName']);
        }
        // TODO: Handle group email
        // ensure group has all wanted members
        if (!empty($item['groupMembers'])) {
            $members = $this->_driver->listUsers($gid);
            foreach ($item['groupMembers'] as $candidate) {
                if (!in_array($candidate, $members)) {
                    $this->_driver->addUser($gid, $candidate);
                }
            }
        }
    }

    /**
     * Resolve group names to Ids
     *
     * In yaml, we generally reference groups by name
     * We don't have any means to import groups by a specific gid
     *
     * However, most resources relate to groups by gid
     * Gid are also not really portable over backends
     *
     * Sql gids are numeric, Ldap gids are strings
     */
    public function getGroupIdByName(string $name): string
    {
        $id = '';
        foreach ($this->_driver->search($name) as $gid => $gname) {
            // search is fuzzy. Find exact match or none
            if ($name == $gname) {
                $id = $gid;
                break;
            }
        }
        return (string) $id;
    }

    public function exists(string $gid): bool
    {
        return $this->_driver->exists($gid);
    }

    public function getGroupNameById(string $gid): string
    {
        return $this->_driver->getName($gid);
    }
}
