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
 * @copyright Copyright 2005-2013 Purdue University. All rights reserved.
 * @license   http://www.gnu.org/licenses/lgpl-3.0.html LGPLv3
 * @since     Class available since release 1.3.2
 */

namespace Hubzero\Database\Relationship;

/**
 * Database base relationship
 *
 * This is essentially the base relationship for 1-1 relationships.
 * Multiplicitous relationships will override all methods that are
 * otherwise singular in this class.
 */
class Relationship
{
	/**
	 * The primary model
	 *
	 * @var \Hubzero\Database\Relational|static
	 **/
	protected $model = null;

	/**
	 * The related model
	 *
	 * @var \Hubzero\Database\Relational|static
	 **/
	protected $related = null;

	/**
	 * The local key (probably 'id')
	 *
	 * @var string
	 **/
	protected $localKey = null;

	/**
	 * The related key (probably 'modelName_id')
	 *
	 * @var string
	 **/
	protected $relatedKey = null;

	/**
	 * Constructs a new object instance
	 *
	 * @param  \Hubzero\Database\Relational|static $model      the primary model
	 * @param  \Hubzero\Database\Relational|static $related    the related model
	 * @param  \Hubzero\Database\Relational|static $localKey   the local key
	 * @param  \Hubzero\Database\Relational|static $relatedKey the related key
	 * @return void
	 * @since  1.3.2
	 **/
	public function __construct($model, $related, $localKey, $relatedKey)
	{
		$this->model      = $model;
		$this->related    = $related;
		$this->localKey   = $localKey;
		$this->relatedKey = $relatedKey;
	}

	/**
	 * Handles calls to undefined methods, assuming they should be passed up to the model
	 *
	 * @param  string $name the method name being called
	 * @param  array  $arguments the method arguments provided
	 * @return mixed
	 * @since  1.3.2
	 **/
	public function __call($name, $arguments)
	{
		return call_user_func_array(array($this->constrain(), $name), $arguments);
	}

	/**
	 * Returns the key name of the primary table
	 *
	 * @return string
	 * @since  1.3.2
	 **/
	public function getLocalKey()
	{
		return $this->localKey;
	}

	/**
	 * Returns the key name of the related table
	 *
	 * @return string
	 * @since  1.3.2
	 **/
	public function getRelatedKey()
	{
		return $this->relatedKey;
	}

	/**
	 * Fetch results of relationship
	 *
	 * @return \Hubzero\Database\Relational
	 * @since  1.3.2
	 **/
	public function rows()
	{
		return $this->constrain()->row();
	}

	/**
	 * Constrains the relationship content to the applicable rows on the related model
	 *
	 * @return object
	 * @since  1.3.2
	 **/
	public function constrain()
	{
		return $this->related->whereEquals($this->relatedKey, $this->model->{$this->localKey});
	}

	/**
	 * Gets keys based on a given constraint
	 *
	 * @param  closure $constraint the constraint function to apply
	 * @return array
	 * @since  1.3.2
	 **/
	public function getConstrainedKeys($constraint)
	{
		$this->related->select($this->relatedKey);

		return $this->getConstrained($constraint)->fieldsByKey($this->relatedKey);
	}

	/**
	 * Gets rows based on given constraint
	 *
	 * @param  closure $constraint the constraint function to apply
	 * @return \Hubzero\Database\Rows
	 * @since  1.3.2
	 **/
	public function getConstrainedRows($constraint)
	{
		$this->related->select($this->related->getQualifiedFieldName('*'));

		return $this->getConstrained($constraint);
	}

	/**
	 * Gets the constrained count
	 *
	 * @param  int $count the count to limit by
	 * @return array
	 * @since  1.3.2
	 **/
	public function getConstrainedKeysByCount($count)
	{
		$relatedKey = $this->relatedKey;

		return $this->getConstrainedKeys(function($related) use ($count, $relatedKey)
		{
			$related->group($relatedKey)->having('COUNT(*)', '>=', $count);
		});
	}

	/**
	 * Gets the constrained items
	 *
	 * @param  closure $constraint the constraint function to apply
	 * @return \Hubzero\Database\Rows
	 * @since  1.3.2
	 **/
	protected function getConstrained($constraint)
	{
		call_user_func_array($constraint, array($this->related));

		// Note that rows is called on the base relational model, not on this relationship,
		// thus it is not calling the constrain method...which is how we want it to work.
		// Constraining here would not make sense as that would limit our result to 1 entry.
		return $this->related->rows();
	}

	/**
	 * Get related keys from a given row set
	 *
	 * @param  \Hubzero\Database\Rows $rows the rows from which to grab the related keys
	 * @return array
	 * @since  1.3.2
	 **/
	public function getRelatedKeysFromRows($rows)
	{
		return $rows->fieldsByKey($this->getRelatedKey());
	}

	/**
	 * Joins the related table together for the pending query
	 *
	 * @return $this
	 * @since  1.3.2
	 **/
	public function join()
	{
		// We do a left outer join here because we're not trying to limit the primary table's results
		// This function is primarily used when needing to sort by a field in the joined table
		$this->model->select($this->model->getQualifiedFieldName('*'))
		            ->join($this->related->getTableName(),
		                   $this->model->getQualifiedFieldName($this->localKey),
		                   $this->related->getQualifiedFieldName($this->relatedKey),
		                   'LEFT OUTER');

		return $this;
	}

	/**
	 * Associates the model provided back to the model by way of their proper keys
	 *
	 * Because this is a singular relationship, we never expect to have more than one
	 * model at at time.
	 *
	 * @param  object  $model    the model to associate
	 * @param  closure $callback a callback to potentially append additional data
	 * @return object
	 * @since  1.3.2
	 **/
	public function associate($model, $callback=null)
	{
		$model->set($this->relatedKey, $this->model->getPkValue());

		if (isset($callback) && is_callable($callback))
		{
			call_user_func_array($callback, [$model]);
		}

		return $model;
	}

	/**
	 * Saves a new related model with the given data
	 *
	 * @param  array $data the data being saved on the new model
	 * @return bool
	 * @since  1.3.2
	 **/
	public function save($data)
	{
		$related = $this->related;
		$model   = $related::newFromResults($data);

		return $this->associate($model)->save();
	}

	/**
	 * Loads the relationship content with the provided data
	 *
	 * @param  array  $rows the rows that we'll be seeding
	 * @param  string $data the data to seed
	 * @param  string $name the name of the relationship
	 * @return object
	 * @since  1.3.2
	 **/
	public function seedWithData($rows, $data, $name)
	{
		return $this->seed($rows, $data, $name);
	}

	/**
	 * Loads the relationship content, and sets it on the related model
	 *
	 * This is used when pre-loading relationship content
	 * via ({@link \Hubzero\Database\Relational::including()})
	 *
	 * @param  array   $rows       the rows that we'll be seeding
	 * @param  string  $name       the relationship name that we'll use to attach to the rows
	 * @param  closure $constraint the constraint function to limit related items
	 * @param  string  $subs       the nested relationships that should be passed on to the child
	 * @return object
	 * @since  1.3.2
	 **/
	public function seedWithRelation($rows, $name, $constraint=null, $subs=null)
	{
		if (!$keys = $rows->fieldsByKey($this->localKey)) return $rows;

		$relations = $this->getRelations($keys, $constraint);

		if (isset($subs)) $relations->including($subs);

		$resultsByRelatedKey = $this->getResultsByRelatedKey($relations);

		return $this->seed($rows, $resultsByRelatedKey , $name);
	}

	/**
	 * Gets the relations that will be seeded on to the provided rows
	 *
	 * @param  array   $keys       the keys for which to fetch related items
	 * @param  closure $constraint the constraint function to limit related items
	 * @return array
	 * @since  1.3.2
	 **/
	protected function getRelations($keys, $constraint=null)
	{
		if (isset($constraint)) call_user_func_array($constraint, array($this->related));

		return $this->related->whereIn($this->relatedKey, array_unique($keys));
	}

	/**
	 * Sorts the relations into arrays keyed by the related key
	 *
	 * @param  array $relations the relations to sort
	 * @return array
	 * @since  1.3.2
	 **/
	protected function getResultsByRelatedKey($relations)
	{
		return $relations->rows();
	}

	/**
	 * Seeds the given rows with data
	 *
	 * @param  \Hubzero\Database\Rows $rows the rows to seed on to
	 * @param  \Hubzero\Database\Rows $data the data from which to seed
	 * @param  string                 $name the relationship name
	 * @return array
	 * @since  1.3.2
	 **/
	protected function seed($rows, $data, $name)
	{
		foreach ($rows as $row)
		{
			if ($related = $data->seek($row->{$this->localKey}))
			{
				$row->addRelationship($name, $related);
			}
			else
			{
				$related = $this->related;
				$row->addRelationship($name, $related::blank());
			}
		}

		return $rows;
	}
}