<?php
/**
 * HUBzero CMS
 *
 * Copyright 2005-2013 Purdue University. All rights reserved.
 *
 * This file is part of: The HUBzero(R) Platform for Scientific Collaboration
 *
 * The HUBzero(R) Platform for Scientific Collaboration (HUBzero) is free
 * software: you can redistribute it and/or modify it under the terms of
 * the GNU Lesser General Public License as published by the Free Software
 * Foundation, either version 3 of the License, or (at your option) any
 * later version.
 *
 * HUBzero is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * HUBzero is a registered trademark of Purdue University.
 *
 * @package   hubzero-cms
 * @author    Sam Wilson <samwilson@purdue.edu>
 * @copyright Copyright 2005-2015 Purdue University. All rights reserved.
 * @license   http://www.gnu.org/licenses/lgpl-3.0.html LGPLv3
 * @since     Class available since release 2.0.0
 */

namespace Hubzero\Database\Traits;

/**
 * Error message bag for shared error handling logic
 */
trait ErrorBag
{
	/**
	 * Errors that have been declared
	 *
	 * @var  array
	 **/
	private $errors = array();

	/**
	 * Sets all errors at once, overwritting any existing errors
	 *
	 * @param   array  $errors  The errors to set
	 * @return  $this
	 * @since   2.0.0
	 **/
	public function setErrors($errors)
	{
		$this->errors = $errors;
		return $this;
	}

	/**
	 * Adds error to the existing set
	 *
	 * @param   string  $error  The error to add
	 * @return  $this
	 * @since   2.0.0
	 **/
	public function addError($error)
	{
		$this->errors[] = $error;
		return $this;
	}

	/**
	 * Returns all errors
	 *
	 * @return  array
	 * @since   2.0.0
	 **/
	public function getErrors()
	{
		return $this->errors;
	}

	/**
	 * Returns the first error
	 *
	 * @return  string
	 * @since   2.0.0
	 **/
	public function getError()
	{
		return (isset($this->errors[0])) ? $this->errors[0] : '';
	}
}