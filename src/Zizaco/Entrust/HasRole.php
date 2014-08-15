<?php namespace Zizaco\Entrust;

use Symfony\Component\Process\Exception\InvalidArgumentException;
use Config;

trait HasRole
{
    /**
     * Many-to-Many relations with Role
     */
    public function roles()
    {
        return $this->belongsToMany(Config::get('entrust::role'), Config::get('entrust::assigned_roles_table'));
    }

    /**
     * Checks if the user has a Role by its name
     *
     * @param string $name Role name.
     *
     * @access public
     *
     * @return boolean
     */
    public function hasRole( $name )
    {
		foreach ($this->getRoleList() as $role) {
            if( $role->name == $name )
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user has a permission by its name
     *
     * @param string $permission Permission string.
     *
     * @access public
     *
     * @return boolean
     */
    public function can( $permission )
    {
	$this->load('roles'); // FIXME Temporarily forcing reload of model to avoid desync bug.
        foreach ($this->getRoleList() as $role) {
            // Deprecated permission value within the role table.
            if( is_array($role->permissions) && in_array($permission, $role->permissions) )
            {
                return true;
            }

            // Validate against the Permission table
		foreach ($role->perms as $perm) {
                if($perm->name == $permission) {
                    return true;
                }
            }
        }

        return false;
    }

	/**
	* Check if user has a permission by its id.
	*
	* @param int $id Permission id.
	*
	* @access public
	*
	* @return boolean
	*/
	public function canId($id)
	{
		$this->load('roles'); // FIXME Temporarily forcing reload of model to avoid desync bug.
		foreach ($this->getRoleList() as $role) {
			foreach ($role->perms as $perm) {
				if ($perm->id == $id) return true;
			}
		}
		return false;
	}

	/**
	Retrieve a list of permission ids available to the user.

	@return array
	*/
	public function getPermIds()
	{
		$this->load('roles'); // FIXME Temporarily forcing reload of model to avoid desync bug.
		$idList = array();
		foreach ($this->getRoleList() as $role) {
			foreach ($role->perms as $perm) {
				$idList[] = $perm->id;
			}
		}
		return array_unique($idList);
	}

	/**
	Retrieve a list of all roles available to the user,
	including those accessed via inheritance.

	@return \Illuminate\Support\Collection
	*/
	public function getRolelist()
	{
		$queue = clone $this->roles;
		$roleList = new \Illuminate\Support\Collection;
		while (!$queue->isEmpty()) {
			$role = $queue->shift();
			if (! $roleList->contains($role)) {
				$roleList->push($role);
				$queue = $queue->merge($role->descendants());
			}
		}
		return $roleList;
	}

	/**
	Retrieve a list of only those roles received via inheritance.
	This may include roles the user owns directly, if roles they own
	are dominated by other roles they own.

	@return \Illuminate\Support\Collection
	*/
	public function getInheritedRoleList()
	{
		// Add descendants of the user's roles to the queue.
		$queue = new \Illuminate\Support\Collection;
		foreach ($this->roles as $rootRole) {
			$queue = $queue->merge($rootRole->descendants());
		}

		// Iterate through the queue, adding all elements to the role list.
		$roleList = new \Illuminate\Support\Collection;
		while (!$queue->isEmpty()) {
			$role = $queue->shift();
			if (! $roleList->contains($role)) {
				$roleList->push($role);
				$queue = $queue->merge($role->descendants());
			}
		}
		return $roleList;
	}

    /**
     * Checks role(s) and permission(s) and returns bool, array or both
     * @param string|array $roles Array of roles or comma separated string
     * @param string|array $permissions Array of permissions or comma separated string.
     * @param array $options validate_all (true|false) or return_type (boolean|array|both) Default: false | boolean
     * @return array|bool
     * @throws InvalidArgumentException
     */
    public function ability( $roles, $permissions, $options=array() ) {
        // Convert string to array if that's what is passed in.
        if(!is_array($roles)){
            $roles = explode(',', $roles);
        }
        if(!is_array($permissions)){
            $permissions = explode(',', $permissions);
        }

        // Set up default values and validate options.
        if(!isset($options['validate_all'])) {
            $options['validate_all'] = false;
        } else {
            if($options['validate_all'] != true && $options['validate_all'] != false) {
                throw new InvalidArgumentException();
            }
        }
        if(!isset($options['return_type'])) {
            $options['return_type'] = 'boolean';
        } else {
            if($options['return_type'] != 'boolean' &&
                $options['return_type'] != 'array' &&
                $options['return_type'] != 'both') {
                throw new InvalidArgumentException();
            }
        }

        // Loop through roles and permissions and check each.
        $checkedRoles = array();
        $checkedPermissions = array();
        foreach($roles as $role) {
            $checkedRoles[$role] = $this->hasRole($role);
        }
        foreach($permissions as $permission) {
            $checkedPermissions[$permission] = $this->can($permission);
        }

        // If validate all and there is a false in either
        // Check that if validate all, then there should not be any false.
        // Check that if not validate all, there must be at least one true.
        if(($options['validate_all'] && !(in_array(false,$checkedRoles) || in_array(false,$checkedPermissions))) ||
            (!$options['validate_all'] && (in_array(true,$checkedRoles) || in_array(true,$checkedPermissions)))) {
            $validateAll = true;
        } else {
            $validateAll = false;
        }

        // Return based on option
        if($options['return_type'] == 'boolean') {
            return $validateAll;
        } elseif($options['return_type'] == 'array') {
            return array('roles' => $checkedRoles, 'permissions' => $checkedPermissions);
        } else {
            return array($validateAll, array('roles' => $checkedRoles, 'permissions' => $checkedPermissions));
        }

    }

    /**
     * Alias to eloquent many-to-many relation's
     * attach() method
     *
     * @param mixed $role
     *
     * @access public
     *
     * @return void
     */
    public function attachRole( $role )
    {
        if( is_object($role))
            $role = $role->getKey();

        if( is_array($role))
            $role = $role['id'];

        $this->roles()->attach( $role );
    }

    /**
     * Alias to eloquent many-to-many relation's
     * detach() method
     *
     * @param mixed $role
     *
     * @access public
     *
     * @return void
     */
    public function detachRole( $role )
    {
        if( is_object($role))
            $role = $role->getKey();

        if( is_array($role))
            $role = $role['id'];

        $this->roles()->detach( $role );
    }

    /**
     * Attach multiple roles to a user
     *
     * @param $roles
     * @access public
     * @return void
     */
    public function attachRoles($roles)
    {
        foreach ($roles as $role)
        {
            $this->attachRole($role);
        }
    }

    /**
     * Detach multiple roles from a user
     *
     * @param $roles
     * @access public
     * @return void
     */
    public function detachRoles($roles)
    {
        foreach ($roles as $role)
        {
            $this->detachRole($role);
        }
    }
}
