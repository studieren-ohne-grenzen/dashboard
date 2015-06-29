<?php
namespace SOG\Dashboard\Authentication;

use Zend\Ldap\Attribute;

class LdapGroup
{
	protected $attributes;
	
	public function __construct(array $attributes = [])
	{
		$this->attributes = $attributes;
	}
	
	/**
	 * @return array Returns all attributes of the user, indexed by property
	 */
	public function getAttributes()
	{
		return $this->attributes;
	}
	
	public function getSingleAttribute($attribName, $index)
	{
		return Attribute::getAttribute($this->attributes, $attribName, $index);
	}
	
}