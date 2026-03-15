<?php

namespace Horde\Hordectl\Repository;

use Horde_Auth_Base as AuthDriver;
use Horde_Core_Factory_Identity as IdentityDriver;
use Horde_Auth;

//use \Horde\Hordectl\Compat\Horde_Core_Factory_Identity as IdentityDriver;
/**
 * Resource User handles querying and formatting
 * user representations
 */
class User
{
    private $_driver;
    private $_identity;
    public function __construct(AuthDriver $driver, IdentityDriver $identity)
    {
        $this->_driver = $driver;
        $this->_identity = $identity;
    }

    /**
     * Export user uid's
     *
     * TODO: handle primary identity (name, email)
     *
     */
    public function export()
    {
        $items = [];
        foreach ($this->_driver->listUsers() as $uid) {
            $identities = $this->_identity->create($uid);
            $baseItem = [
                'userUid' => $uid,
                'isLocked' => $this->_driver->hasCapability('lock')
                    ? $this->_driver->isLocked($uid)
                       : false,
            ];
            $identities = [];

            foreach ($this->_identity->create($uid) as $id => $identity) {
                $identities[$id] = [
                    'id'        => $identity['id'],
                    'fullname'  => $identity['fullname'],
                    'from_addr' => $identity['from_addr'],
                    'location'  => $identity['location'],
                ];
            }
            $baseItem['identities'] = $identities;
            $items[] = $baseItem;
        }
        return $items;
    }

    public function import(array $item)
    {
        // check if driver is readonly
        if (!$this->_driver->hasCapability('add')) {
            return;
        }
        if (!$this->_driver->exists($item['userUid'])) {
            $password = empty($item['password'])
                ? Horde_Auth::genRandomPassword()
                : $item['password'];
            $credentials = ['password' => $password];
            $this->_driver->addUser($item['userUid'], $credentials);
        }
        // TODO: Handle locked users and expiration
        $this->_saveIdentities($item);
    }

    private function _saveIdentities(array $item)
    {
        $identities = $this->_identity->create($item['userUid']);
        if (empty($item['identities'])) {
            return;
        }
        foreach ($item['identities'] as $id => $identity) {
            $current = $identities->get($id);
            if ($current) {
                foreach (array_keys($identity) as $key) {
                    $identities->setValue(
                        $key,
                        $identity[$key],
                        $id
                    );
                }
            } else {
                $identities->add($identity);
            }
            $identities->save();
        }
    }

    public function exists(string $uid): bool
    {
        return $this->_driver->exists($uid);
    }
}
