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

namespace Hubzero\Database;

use Hubzero\Database\Relationship\BelongsToOne;
use Hubzero\Database\Relationship\OneToMany;
use Hubzero\Database\Relationship\ManyToMany;
use Hubzero\Database\Relationship\OneToManyThrough;
use Hubzero\Database\Relationship\OneToOne;

use Hubzero\Error\Exception\BadMethodCallException;
use Hubzero\Error\Exception\RuntimeException;

/**
 * Database ORM base class
 *
 * @uses \Hubzero\Error\Exception\BadMethodCallException to handle calls to undefined methods
 * @uses \Hubzero\Error\Exception\RuntimeException       to handle scenarios with undefined rows
 * // @FIXME: handle content that needs to be parsed (wiki/html)
 * // @FIXME: handle dates
 */
class Relational implements \IteratorAggregate, \ArrayAccess
{
	/*
	 * Errors trait for error message handling
	 **/
	use Traits\ErrorBag;

	/**
	 * Database state constants
	 **/
	const STATE_UNPUBLISHED = 0;
	const STATE_PUBLISHED   = 1;
	const STATE_DELETED     = 2;

	/**
	 * Database access constants
	 **/
	const ACCESS_PUBLIC     = 1;
	const ACCESS_REGISTERED = 2;
	const ACCESS_PRIVATE    = 2;

	/**
	 * The database model name
	 *
	 * This will defined as the static/calling class' name.
	 * It's used when building relationships between classes.
	 *
	 * @var string
	 **/
	private $modelName = null;

	/**
	 * The internal array of methods of this model
	 *
	 * We do a lot of reflection checks on the model,
	 * so this should save us some time by storing the results
	 * for future reference.
	 *
	 * @var array
	 **/
	private $methods = [];

	/**
	 * The database query object
	 *
	 * @var Hubzero\Database\Query
	 **/
	private $query = null;

	/**
	 * The database connection used by the query object
	 *
	 * @var Hubzero\Database\Driver|object
	 **/
	private static $connection = null;

	/**
	 * The relationships on this model
	 *
	 * @var array
	 **/
	private $relationships = [];

	/**
	 * The forwards for the model (i.e. other places to look for attributes)
	 *
	 * @var array
	 **/
	private $forwards = [];

	/**
	 * The includes set on the model for eager loading
	 *
	 * @var string
	 **/
	private $includes = [];

	/**
	 * The model data returned as the result of a query, or set for saving
	 *
	 * @var array
	 **/
	private $attributes = [];

	/**
	 * The table to which the class pertains
	 *
	 * This will default to #__{namespace}_{modelName} unless otherwise
	 * overwritten by a given subclass. Definition of this property likely
	 * indicates some derivation from standard naming conventions.
	 *
	 * @var string
	 **/
	protected $table = null;

	/**
	 * The table namespace
	 *
	 * This is likely just the component name, and will most likely
	 * be set by all subclasses. This follows the convention of 
	 * prefixing/namespacing database tables with #__componentname_*.
	 * @FIXME: could we infer this once our models are properly namespaced?
	 *
	 * @var string
	 **/
	protected $namespace = null;

	/**
	 * The table primary key name
	 *
	 * It defaults to 'id', but can be overwritten by a subclass.
	 *
	 * @var string
	 **/
	protected $pk = 'id';

	/**
	 * Fields and their validation criteria
	 *
	 * @var array
	 * @see \Hubzero\Database\Rules
	 **/
	protected $rules = [];

	/**
	 * Default order by for select queries
	 *
	 * This can be overwritten in a model or by calling
	 * the order method on the query object.
	 *
	 * @var string
	 **/
	public $orderBy = 'id';

	/**
	 * Default order direction for select queries
	 *
	 * @var string
	 **/
	public $orderDir = 'asc';

	/**
	 * The pagination object
	 *
	 * This will also get set on the rows object if applicable.
	 *
	 * @var \Hubzero\Database\Pagination|null
	 **/
	public $pagination = null;

	/**
	 * Automatic fields to populate every time a row is touched
	 *
	 * @var array
	 **/
	public $always = [];

	/**
	 * Automatic fields to populate every time a row is created
	 *
	 * @var array
	 **/
	public $initiate = [];

	/**
	 * Automatic fields to populate every time a row is updated
	 *
	 * @var array
	 **/
	public $renew = [];

	/**
	 * Constructs an object instance
	 *
	 * @return void
	 * @since  1.3.2
	 **/
	public function __construct()
	{
		// Set model name
		$this->modelName = with(new \ReflectionClass($this))->getShortName();

		// If table name isn't explicitly set, build it
		$namespace   = (!$this->namespace ? '' : $this->namespace . '_');
		$plural      = \Hubzero\Utility\Inflector::pluralize(strtolower($this->getModelName()));
		$this->table = $this->table ?: '#__' . $namespace . $plural;

		// Set up connection and query object
		$this->newQuery();

		// Store methods for later
		$this->methods = get_class_methods($this);

		// Run extra setup. This is so subclasses don't have to overwrite
		// the constructor and then call parent::__construct().
		// They can instead just add a setup() method.
		$this->setup();
	}

	/**
	 * Processes calls to inaccessible or undefined instance methods
	 *
	 * @param  string $name the method name being called
	 * @param  array  $arguments the method arguments provided
	 * @return mixed
	 * @throws \Hubzero\Error\Exception\BadMethodCallException if called method does not exist in
	 *                                                         this class or the query class, or
	 *                                                         as a helper* method on the current class.
	 * @since 1.3.2
	 **/
	public function __call($name, $arguments)
	{
		// See if method is available as a helper method on current class
		if ($this->hasHelper($name)) return $this->callHelper($name, $arguments);

		// See if method is available as a transformer on current class
		if ($this->hasTransformer($name)) return $this->callTransformer($name, $arguments);

		// See if we need to call a query method
		if (in_array($name, get_class_methods($this->query)))
		{
			// @FIXME: hack to fully qualify field names in one location...is there a better way/location?
			if ((substr($name, 0, 5) == 'where' || substr($name, 0, 7) == 'orWhere') && $name != 'whereRaw' && $name != 'orWhereRaw')
			{
				$arguments[0] = (strpos($arguments[0], '.') === false)
								? $this->getQualifiedFieldName($arguments[0])
								: $arguments[0];
			}

			// Call method and get type of response
			$result = call_user_func_array(array($this->query, $name), $arguments);
			$class  = __NAMESPACE__ . '\\Query';
			// We never want to return an instance of the query class, because
			// we want to be able to chain methods together that are on the model
			// itself.  Plus we auto-forward calls to query functions, so they'll
			// get there eventually anyway.
			return ($result instanceof $class) ? $this : $result;
		}

		// This method doesn't exist
		throw new BadMethodCallException("'{$name}' method does not exist.", 500);
	}

	/**
	 * Processes calls to inaccessible or undefined static methods
	 *
	 * This is here primarily so we can statically call query class
	 * methods directly on a newly created object
	 * For example: Model::whereEquals('field', 'yes');
	 *
	 * @param  string $name the method name being called
	 * @param  array  $arguments the method arguments provided
	 * @return mixed
	 * @since  1.3.2
	 **/
	public static function __callStatic($name, $arguments)
	{
		return call_user_func_array(array(new static, $name), $arguments);
	}

	/**
	 * Gets attributes set on model dynmically
	 *
	 * @param  string $name the name of the var to retrieve
	 * @return mixed
	 * @since  1.3.2
	 **/
	public function __get($name)
	{
		// First, see if a transformer is available on the model
		if ($this->hasTransformer($name)) return $this->callTransformer($name);

		// Next check for an attribute on the model
		if (isset($this->attributes[$name]))
		{
			return $this->attributes[$name];
		}

		// Check forwarding
		if (!empty($this->forwards))
		{
			foreach ($this->forwards as $forward)
			{
				// We take the first one we find, so in theory, if multiple forwards exist with
				// the same name, you'd have to prioritize them somehow.
				if ($var = $this->makeRelationship($name)->getRelationship($forward)->$name)
				{
					return $var;
				}
			}
		}

		// Finally, we'll assume we're looking for a relationship
		if (in_array($name, $this->methods))
		{
			return $this->makeRelationship($name)->getRelationship($name);
		}
	}

	/**
	 * Intercepts calls to copy the object so we can make a true clone of the attached query
	 *
	 * PHP, when cloning, does a shallow copy, hence the need for this intercept.
	 *
	 * @return void
	 * @since  1.3.2
	 **/
	public function __clone()
	{
		$this->query = clone $this->query;
	}

	/**
	 * Runs extra setup code when creating a new model
	 *
	 * @return void
	 * @since  1.3.2
	 **/
	public function setup()
	{
		// Overload in subclass to do something here...nothing by default
	}

	/**
	 * Sets the database connection to be used by the query builder
	 *
	 * @param  object $connection the connection to set
	 * @return void
	 * @since  1.3.2
	 **/
	public static function setDefaultConnection($connection)
	{
		self::$connection = $connection;
	}

	/**
	 * Gets an attribute by key
	 *
	 * This will not retrieve properties directly attached to the model,
	 * even if they are public - those should be accessed directly!
	 *
	 * Also, make sure to access properties in transformers using the get method.
	 * Otherwise you'll just get stuck in a loop!
	 *
	 * @param  string $key the attribute key to get
	 * @param  mixed  $default the value to provide, should the key be non-existent
	 * @return mixed
	 * @since  1.3.2
	 **/
	public function get($key, $default=null)
	{
		return $this->hasAttribute($key) ? $this->attributes[$key] : $default;
	}

	/**
	 * Sets attributes (i.e. fields) on the model
	 *
	 * This must be used when setting data to be saved. Otherwise, the properties
	 * will be attached directly to the model itself and not included in the save.
	 *
	 * @param  array|string $key   the key to set, or array of key/value pairs
	 * @param  mixed        $value the value to set if key is string
	 * @return $this
	 * @since  1.3.2
	 **/
	public function set($key, $value=null)
	{
		if (is_array($key) || is_object($key))
		{
			foreach ($key as $k => $v)
			{
				$this->attributes[$k] = $v;
			}
		}
		else
		{
			$this->attributes[$key] = $value;
		}

		return $this;
	}

	/**
	 * Returns a new empty model
	 *
	 * @return static
	 * @since  1.3.2
	 **/
	public static function blank()
	{
		return new static;
	}

	/**
	 * Construct a new object instance, setting the passed in results on the object
	 *
	 * @param  object $results the results to set on the new model
	 * @return static
	 * @since  1.3.2
	 **/
	public static function newFromResults($results)
	{
		$instance = self::blank();
		$instance->set($results);

		return $instance;
	}

	/**
	 * Copies the current model (likely used to maintain query parameters between multiple queries)
	 *
	 * @return $this
	 * @since  1.3.2
	 **/
	public function copy()
	{
		return clone $this;
	}

	/**
	 * Outputs attributes in JSON encoded format
	 *
	 * @return string
	 * @since  1.3.2
	 **/
	public function toJson()
	{
		return json_encode($this->attributes);
	}

	/**
	 * Outputs attributes as array
	 *
	 * @return array
	 * @since  1.3.2
	 **/
	public function toArray()
	{
		return $this->attributes;
	}

	/**
	 * Outputs attributes as object
	 *
	 * @return object
	 * @since  1.3.2
	 **/
	public function toObject()
	{
		return (object)$this->attributes;
	}

	/**
	 * Checks to see if the current model has a helper by the given name
	 *
	 * @param  string $name the helper name to check for
	 * @return bool
	 * @since  1.3.2
	 **/
	public function hasHelper($name)
	{
		return in_array('helper' . ucfirst($name), $this->methods);
	}

	/**
	 * Calls the requested helper, passing the given arguments
	 *
	 * @param  string $name      the helper name to call
	 * @param  array  $arguments arguments to pass with the method call
	 * @return mixed
	 * @since  1.3.2
	 **/
	public function callHelper($name, $arguments)
	{
		return call_user_func_array(array($this, 'helper' . ucfirst($name)), $arguments);
	}

	/**
	 * Checks to see if the current model has a transformer by the given name
	 *
	 * @param  string $name the transformer name to check for
	 * @return bool
	 * @since  1.3.2
	 **/
	public function hasTransformer($name)
	{
		return in_array('transform' . ucfirst($this->snakeToCamel($name)), $this->methods);
	}

	/**
	 * Calls the requested transformer, passing the given arguments
	 *
	 * @param  string $name      the transformer name to call
	 * @param  array  $arguments arguments to pass with the method call
	 * @return mixed
	 * @since  1.3.2
	 **/
	public function callTransformer($name, $arguments=array())
	{
		return call_user_func_array(array($this, 'transform' . ucfirst($this->snakeToCamel($name))), $arguments);
	}

	/**
	 * Takes a snake-cased string and camel cases it
	 *
	 * @param  string $text the string to camel case
	 * @return string
	 * @since  1.3.2
	 **/
	public function snakeToCamel($text)
	{
		if (strpos($text, '_') !== false)
		{
			$bits = explode('_', $text);
			$bits = array_map('ucfirst', $bits);
			$text = lcfirst(implode('', $bits));
		}

		return $text;
	}

	/**
	 * Resets the current model, likely for another query to be performed on it
	 *
	 * @return $this
	 * @since  1.3.2
	 **/
	private function reset()
	{
		$this->clearAttributes();
		$this->newQuery();
		return $this;
	}

	/**
	 * Sets a fresh query object on the model, seeding it with helpful defaults
	 *
	 * @return $this
	 * @since  1.3.2
	 **/
	private function newQuery()
	{
		$this->query = with(new Query(self::$connection))->select('*')->from($this->getTableName());
		return $this;
	}

	/**
	 * Checks to see if the requested attribute is set on the model
	 *
	 * @return bool
	 * @since  1.3.2
	 **/
	public function hasAttribute($key)
	{
		return isset($this->attributes[$key]);
	}

	/**
	 * Clears data attributes set on the current model
	 *
	 * @return void
	 * @since  1.3.2
	 **/
	private function clearAttributes()
	{
		$this->attributes = array();
	}

	/**
	 * Determines if the current model is new by looking for the presence of a primary key attribute
	 *
	 * @return bool
	 * @since  1.3.2
	 **/
	public function isNew()
	{
		return (!$this->hasAttribute($this->getPrimaryKey()) || !$this->{$this->getPrimaryKey()});
	}

	/**
	 * Retrieves the current model's table name
	 *
	 * @return string
	 * @since  1.3.2
	 **/
	public function getTableName()
	{
		return $this->table;
	}

	/**
	 * Retrieves the current model's primary key name
	 *
	 * @return string
	 * @since  1.3.2
	 **/
	public function getPrimaryKey()
	{
		return $this->pk;
	}

	/**
	 * Gets the value of the primary key
	 *
	 * @return mixed
	 * @since  1.3.2
	 **/
	public function getPkValue()
	{
		return isset($this->attributes[$this->getPrimaryKey()]) ? $this->attributes[$this->getPrimaryKey()] : null;
	}

	/**
	 * Creates the fully qualified field name by prepending the table name
	 *
	 * @return string
	 * @since  1.3.2
	 **/
	public function getQualifiedFieldName($field)
	{
		return $this->getTableName() . '.' . $field;
	}

	/**
	 * Retrieves the model's name
	 *
	 * @return string
	 * @since  1.3.2
	 **/
	public function getModelName()
	{
		return $this->modelName;
	}

	/**
	 * Retrieves the model's namespace
	 *
	 * @return string
	 * @since  1.3.2
	 **/
	public function getNamespace()
	{
		return $this->namespace;
	}

	/**
	 * Retrieves the model rules
	 *
	 * @return array
	 * @since  1.3.2
	 **/
	public function getRules()
	{
		return $this->rules;
	}

	/**
	 * Adds a new rule to the validation set
	 *
	 * @param   string  $key   the field to which the rule applies
	 * @param   mixed   $rule  the rule to add
	 * @return  $this
	 * @since   1.3.2
	 **/
	public function addRule($key, $rule)
	{
		$this->rules[$key] = $rule;

		return $this;
	}

	/**
	 * Get total number of rows
	 *
	 * @return int
	 * @since  1.3.2
	 **/
	public function total()
	{
		$total = $this->select($this->getPrimaryKey(), 'count', true)->rows()->first()->count;
		$this->reset();

		return $total;
	}

	/**
	 * Counts rows, fetching them first
	 *
	 * The {@link \Hubzero\Database\Rows} class also has a count method, which is used
	 * to count rows after they've already been fetched.
	 *
	 * @return int
	 * @since  1.3.2
	 **/
	public function count()
	{
		return $this->rows()->count();
	}

	/**
	 * Gets the results of the established query
	 *
	 * @return \Hubzero\Database\Rows
	 * @since  1.3.2
	 **/
	public function rows()
	{
		// Fetch the results
		$rows = $this->rowsFromRaw($this->query->fetch());
		$rows = $this->parseIncluding($rows);

		// Set a few things on the rows object that might be helpful
		$rows->pagination = $this->pagination;
		$rows->orderBy    = $this->orderBy;
		$rows->orderDir   = $this->orderDir;
		return $rows;
	}

	/**
	 * Gets the first/only row from the established query
	 *
	 * Not quite the same as rows, in that we're assuming an intentional
	 * call to only get one row wouldn't want any pagination info included.
	 *
	 * @return \Hubzero\Database\Relational|static
	 * @since  1.3.2
	 **/
	public function row()
	{
		$row = $this->query->fetch('row');

		return ($row) ? self::newFromResults($row) : self::blank();
	}

	/**
	 * Sets the results of the query on new models and returns a Rows collection
	 *
	 * @param  array $data the data to set on the model
	 * @return \Hubzero\Database\Rows
	 * @since  1.3.2
	 **/
	public function rowsFromRaw($data)
	{
		$rows = new Rows;

		if ($data && count($data) > 0)
		{
			foreach ($data as $row)
			{
				$rows->push(self::newFromResults($row));
			}
		}

		return $rows;
	}

	/**
	 * Triggers when attempting to iterator over the object, so we know to fetch results
	 *
	 * We go ahead and use a copy, that way future calls to the same model will
	 * continue to have the initial query elements set in place
	 *
	 * @return \Hubzero\Database\Rows
	 * @since  1.3.2
	 **/
	public function getIterator()
	{
		return $this->copy()->rows();
	}

	/**
	 * Sets the atrributes key with value
	 *
	 * @param  array|string $key   the key to set, or array of key/value pairs
	 * @param  mixed        $value the value to set if key is string
	 * @return void
	 * @since  1.3.2
	 **/
	public function offsetSet($key, $value)
	{
		if (is_array($key) || is_object($key))
		{
			foreach ($key as $k => $v)
			{
				$this->attributes[$k] = $v;
			}
		}
		else
		{
			$this->attributes[$key] = $value;
		}
	}

	/**
	 * Checks to see if the requested attribute is set on the model
	 *
	 * @return bool
	 * @since  1.3.2
	 **/
	public function offsetExists($key)
	{
		return $this->hasAttribute($key);
	}

	/**
	 * Unsets the requested attribute from the model
	 *
	 * @return void
	 * @since  1.3.2
	 **/
	public function offsetUnset($key)
	{
		unset($this->attributes[$key]);
	}

	/**
	 * Gets an attribute by key
	 *
	 * @param  string $key the attribute key to get
	 * @return mixed
	 * @since  1.3.2
	 **/
	public function offsetGet($key)
	{
		return $this->get($key);
	}

	/**
	 * Retrieves one row by primary key value provided
	 *
	 * @param  mixed $id the primary key field value to use to retrieve one row
	 * @return \Hubzero\Database\Relational|static
	 * @since  1.3.2
	 **/
	public static function one($id)
	{
		$instance = self::blank();
		return $instance->whereEquals($instance->getPrimaryKey(), $id)->rows()->seek($id);
	}

	/**
	 * Retrieves one row by primary key, throwing a new exception if not found
	 *
	 * @param  mixed $id the primary key field value to use to retrieve one row
	 * @return \Hubzero\Database\Relational|static
	 * @throws Hubzero\Error\Exception\RuntimeException
	 * @since  1.3.2
	 **/
	public static function oneOrFail($id)
	{
		$row = self::one($id);

		// Make sure we have a valid row
		if ($row === false)
		{
			throw new RuntimeException("Failed to retrieve a model with a primary key of {$id}", 404);
		}

		return $row;
	}

	/**
	 * Retrieves one row by primary key, returning an empty row if not found
	 *
	 * @param  mixed $id the primary key field value to use to retrieve one row
	 * @return \Hubzero\Database\Relational|static
	 * @since  1.3.2
	 **/
	public static function oneOrNew($id)
	{
		$row = self::one($id);

		// See if we have a valid row
		if ($row === false) $row = self::blank();

		return $row;
	}

	/**
	 * Returns all rows (unless otherwise limited)
	 *
	 * @param  string|array $columns the columns to select
	 * @return \Hubzero\Database\Relational|static
	 * @since  1.3.2
	 **/
	public static function all($columns=null)
	{
		return self::blank();
	}

	/**
	 * Retrieves only the most recent applicable row
	 *
	 * This orders results by the limiter, and grabs the first one.
	 * It by default assumes you want to order by created date.
	 *
	 * @param  string $limiter the column name to use to determine the latest row
	 * @return \Hubzero\Database\Relational|static
	 * @since  1.3.2
	 **/
	public function latest($limiter='created')
	{
		return $this->order($limiter, 'desc')->limit(1)->rows()->first();
	}

	/**
	 * Saves the current model to the database
	 *
	 * @return bool
	 * @since  1.3.2
	 **/
	public function save()
	{
		// Validate
		if (!$this->validate()) return false;

		// See if we're creating or updating
		$method = $this->isNew() ? 'create' : 'modify';
		$result = $this->$method($this->attributes);

		// If creating, result is our new id, so set that back on the model
		if ($this->isNew())
		{
			$this->set($this->getPrimaryKey(), $result);
			Event::trigger($this->getTableName() . '.new', ['model' => $this]);
		}

		return $result;
	}

	/**
	 * Inserts a new row into the database
	 *
	 * @return bool
	 * @since  1.3.2
	 **/
	private function create()
	{
		// Add any automatic fields
		$this->parseAutomatics('initiate');

		return $this->query->push($this->getTableName(), $this->attributes);
	}

	/**
	 * Updates an existing item in the database
	 *
	 * @return bool
	 * @since  1.3.2
	 **/
	private function modify()
	{
		// Add any automatic fields
		$this->parseAutomatics('renew');

		// Return the result of the query
		return $this->query->alter(
			$this->getTableName(),
			$this->getPrimaryKey(),
			$this->getPkValue(),
			$this->attributes
		);
	}

	/**
	 * Parses for automatically fillable fields
	 *
	 * @param  string $scope the scope of rules to parse and run
	 * @return $this
	 * @since  1.3.2
	 **/
	private function parseAutomatics($scope='always')
	{
		$automatics = array_merge($this->$scope, $this->always);

		if (!empty($automatics))
		{
			foreach ($automatics as $field)
			{
				if (strpos($field, '_'))
				{
					$bits   = explode('_', $field);
					$bits   = array_map('ucfirst', $bits);
					$method = implode('', $bits);
				}
				else
				{
					$method = ucfirst($field);
				}

				$method = 'automatic' . $method;
				// Pass the data to the method in case it needs to make use of another field's value
				$this->set($field, $this->$method($this->attributes));
			}
		}

		return $this;
	}

	/**
	 * Saves the current model and any subsequent attached models
	 *
	 * @return bool
	 * @since  1.3.2
	 **/
	public function saveAndPropagate()
	{
		if (!$this->save())
		{
			return false;
		}

		// Loop through the relationships and save
		// Both rows and models know how to save, so it doesn't matter
		// which of the two the particular relationship returned
		foreach ($this->getRelationships() as $relationship)
		{
			if (!$relationship->save())
			{
				$this->setErrors($relationship->getErrors());
				return false;
			}
		}

		return true;
	}

	/**
	 * Deletes the existing/current model
	 *
	 * @return bool
	 * @since  1.3.2
	 **/
	public function destroy()
	{
		// If it has an associated Joomla asset entry, try deleting that first
		if ($this->hasAttribute('asset_id'))
		{
			if (!Asset::destroy($this)) return false;
		}

		return $this->query->remove(
			$this->getTableName(),
			$this->getPrimaryKey(),
			$this->getPkValue()
		);
	}

	/**
	 * Checks out the current model to the provided user
	 *
	 * @param  string $userId optional userId for whome the row should be checked out
	 * @return $this
	 * @since  1.3.2
	 **/
	public function checkout($userId=null)
	{
		$userId = $userId ?: User::get('id');
		$this->set('checked_out', $userId)
		     ->set('checked_out_time', Date::toSql())
		     ->save();
	}

	/**
	 * Checks back in the current model
	 *
	 * @return $this
	 * @since  1.3.2
	 **/
	public function checkin()
	{
		// @FIXME: need to be able to get database null date format here?
		if (!$this->isNew())
		{
			$this->set('checked_out', '0')
			     ->set('checked_out_time', '0000-00-00 00:00:00')
			     ->save();
		}
	}

	/**
	 * Checks to see if the current model is checked out by someone else
	 *
	 * @return bool
	 * @since  1.3.2
	 **/
	public function isCheckedOut()
	{
		return ($this->checked_out && $this->checked_out != User::get('id'));
	}

	/**
	 * Selects applicable rows on the relation and limits current query accordingly
	 *
	 * NOTE: whereas other 'where' clauses can be called statically due to their
	 * location in the query builder class, this method cannot be as it is attached
	 * directly to the model itself.
	 *
	 * @param  string  $relationship the relationship name
	 * @param  closure $constraint   the constraint to apply to the related query
	 * @param  int     $depth        the depth level of the clause, for sub clauses
	 * @return $this
	 * @since  1.3.2
	 **/
	public function whereRelatedHas($relationship, $constraint, $depth=0)
	{
		$rel  = $this->$relationship();
		$keys = $rel->getConstrainedKeys($constraint);

		return $this->where($rel->getLocalKey(), 'IN', $keys, 'and', $depth);
	}

	/**
	 * Selects applicable rows on the relation and limits current query accordingly
	 *
	 * NOTE: whereas other 'where' clauses can be called statically due to their
	 * location in the query builder class, this method cannot be as it is attached
	 * directly to the model itself.
	 *
	 * @param  string  $relationship the relationship name
	 * @param  closure $constraint   the constraint to apply to the related query
	 * @param  int     $depth        the depth level of the clause, for sub clauses
	 * @return $this
	 * @since  1.3.2
	 **/
	public function orWhereRelatedHas($relationship, $constraint, $depth=0)
	{
		$rel  = $this->$relationship();
		$keys = $rel->getConstrainedKeys($constraint);

		return $this->where($rel->getLocalKey(), 'IN', $keys, 'or', $depth);
	}

	/**
	 * Selects rows where related table has at least x number of entries
	 *
	 * NOTE: whereas other 'where' clauses can be called statically due to their
	 * location in the query builder class, this method cannot be as it is attached
	 * directly to the model itself.
	 *
	 * @param  string $relationship the relationship name to constrain against
	 * @param  int    $count        the minimum number of rows required
	 * @param  int    $depth        the depth level of the clause, for sub clauses
	 * @return $this
	 * @since  1.3.2
	 **/
	public function whereRelatedHasCount($relationship, $count=1, $depth=0)
	{
		$rel  = $this->$relationship();
		$keys = $rel->getConstrainedKeysByCount($count);

		return $this->where($rel->getLocalKey(), 'IN', $keys, 'and', $depth);
	}

	/**
	 * Limits current model based on conditions of relationship
	 *
	 * @FIXME: decide whether or not to use this
	 *
	 * This is NOT currently used. The problem here has to do with relationship data.
	 * If you constrain based on a relationship, and then later on end up wanting to access
	 * properties of that relationship, it will currently do two queries.  Instead, we
	 * could get the data with the original constraint and attach it to the models in a
	 * similar fashion to the way that including() works.
	 *
	 * To make this work, data would need to be stored on the object, and then seeded
	 * after the model rows are fetched (like parseIncludes() works now).
	 *
	 * @return $this
	 * @since  1.3.2
	 **/
	private function whereRelated($relationship, $constraint)
	{
		$this->data = [];
		$keys       = null;

		// Parse for nested relationships
		if (strpos($name, '.'))
		{
			// If we have a nested name, pull out the first one
			list($name, $subs)  = explode('.', $name, 2);
			$relationship       = $this->$name();
			$this->data[$name]  = $relationship->whereRelated($subs, $constraint);
		}
		else
		{
			$relationship       = $this->$name();
			$this->data[$name]  = $relationship->getConstrainedRows($constraint);
		}

		// Update keys to only include those in this and previous results
		$keys = is_null($keys) ? $relationship->getRelatedKeysFromRows($this->data[$name])
		                       : array_intersect($keys, $relationship->getRelatedKeysFromRows($this->data[$name]));

		// Only keep unique keys
		$keys = array_unique($keys);

		// Set our where clause if needed
		if (!empty($keys)) $this->whereIn($relationship->getLocalKey(), $keys);

		return $this;
	}

	/**
	 * Seeds the rows with any pre-fetched data
	 *
	 * @FIXME: decide whether or not to use this
	 *
	 * @param  \Hubzero\Database\Rows $rows the rows to seed
	 * @return \Hubzero\Database\Rows
	 * @since  1.3.2
	 **/
	private function seed($rows)
	{
		// Set our constrained (pre-fetched data) back on the rows
		foreach ($this->data as $relationship => $data)
		{
			$rows = $this->$relationship()->seedWithData($rows, $data, $relationship);
		}

		return $rows;
	}

	/**
	 * Applies a where clause comparing a field to the current juser id
	 *
	 * NOTE: whereas other 'where' clauses can be called statically due to their
	 * location in the query builder class, this method cannot be as it is attached
	 * directly to the model itself.
	 *
	 * @param  string $column the field to use for ownership, defaulting to 'created_by'
	 * @return $this
	 * @since  1.3.2
	 **/
	public function whereIsMine($column='created_by')
	{
		$this->whereEquals($column, User::get('id'));
		return $this;
	}

	/**
	 * Validates the set data attributes against the model rules
	 *
	 * @return bool
	 * @since  1.3.2
	 **/
	public function validate()
	{
		$validity = Rules::validate($this->attributes, $this->getRules());

		if ($validity === true) return true;

		$this->setErrors($validity);
		return false;
	}

	/**
	 * Chunks the retrieved data based on a given chunk limit
	 *
	 * @param  int $size the chunk size
	 * @return $this
	 * @since  1.3.2
	 **/
	public function paginate($size)
	{
		// @FIXME: implement!
		return $this;
	}

	/**
	 * Retrieves a chuck of data based on standard pagination parameters
	 *
	 * @param  string $start the request variable used to denote limit start
	 * @param  string $limit the request variable used to denote limit of results to return
	 * @return $this
	 * @since  1.3.2
	 **/
	public function paginated($start='start', $limit='limit')
	{
		$this->pagination = Pagination::init($this->getModelName(), $this->copy()->total(), $start, $limit);

		// Set start and limit on query
		$this->start($this->pagination->start);
		$this->limit($this->pagination->limit);

		return $this;
	}

	/**
	 * Sets the ordering based on the established request variables
	 *
	 * @param  string $orderBy  the request variable used to denote ordering column
	 * @param  string $orderDir the request variable used to denote ordering direction
	 * @return $this
	 * @since  1.3.2
	 **/
	public function ordered($orderBy='orderby', $orderDir='orderdir')
	{
		// Look for our request vars of interest
		$this->orderBy  = Request::getCmd($orderBy,  $this->getState('orderby',  $this->orderBy));
		$this->orderDir = Request::getCmd($orderDir, $this->getState('orderdir', $this->orderDir));

		$qualifiedOrderBy = $this->orderBy;

		// If we have a '.' we'll assume the prefix is a relationship name
		if (strpos($this->orderBy, '.') !== false)
		{
			list($relationship, $field) = explode('.', $this->orderBy);

			// We have to join to apply the order by clause
			$relationship     = $this->$relationship()->join();
			$qualifiedOrderBy = $relationship->getQualifiedFieldName($field);
		}

		// Apply order clause
		$this->order($qualifiedOrderBy, $this->orderDir);

		// Set state for future use
		$this->setState('orderby',  $this->orderBy);
		$this->setState('orderdir', $this->orderDir);

		return $this;
	}

	/**
	 * Retrieves state vars set in the model namespace
	 *
	 * @param  string $var     the var to attempt to retrieve
	 * @param  mixed  $default the default to return, should the var be unknown
	 * @return mixed
	 * @since  1.3.2
	 **/
	public function getState($var, $default=null)
	{
		return User::getState($this->getModelName() . ".{$var}", $default);
	}

	/**
	 * Sets state vars on the model namespace
	 *
	 * @param  string $key   the key under which the value will go
	 * @param  mixed  $value the value to assign to the key
	 * @return void
	 * @since  1.3.2
	 **/
	public function setState($key, $value)
	{
		User::setState($this->getModelName() . ".{$key}", $value);
	}

	/**
	 * Checks whether or not the current user is the owner/creator of the row
	 *
	 * @param  string $field the field by which creation is determined
	 * @return bool
	 * @throws \Hubzero\Error\Exception\RuntimeException if rows have not first been fetched
	 * @since  1.3.2
	 **/
	public function isCreator($field='created_by')
	{
		// Make sure we have a valid row
		if (!$this->hasAttribute($field))
		{
			throw new RuntimeException('Cannot determine creator of non-existant row(s)');
		}

		return $this->$field == User::get('id');
	}

	/**
	 * Finds the named class, checking a handful of scopes
	 *
	 * @param  string $name the name of the relationship to resolve
	 * @return object
	 * @since  1.3.2
	 * @throws \Hubzero\Error\Exception\RuntimeException if a class of name cannot be found
	 **/
	private function resolve($name)
	{
		if (!class_exists($name))
		{
			// Get the scope of the current class and check there too
			$name = with(new \ReflectionClass($this))->getNamespaceName() . '\\' . $name;

			if (!class_exists($name))
			{
				throw new RuntimeException("Relationship '{$name}' not found");
			}
		}

		return new $name;
	}

	/**
	 * Retrieves a one to one model relationship
	 *
	 * @param  string $model the name of the primary model
	 * @param  string|null $childKey the child key that point to the local key
	 * @param  string|null $thisKey the local key on the model
	 * @return \Hubzero\Database\Relationship\OneToOne
	 * @since  1.3.2
	 **/
	public function oneToOne($model, $childKey=null, $thisKey=null)
	{
		// Default the keys if not set
		$thisKey  = $thisKey  ?: $this->getPrimaryKey();
		$childKey = $childKey ?: strtolower($this->getModelName()) . '_id';

		return new OneToOne($this, $this->resolve($model), $thisKey, $childKey);
	}

	/**
	 * Retrieves a one to many model relationship
	 *
	 * @param  string $model the name of the model to relate to the current one
	 * @param  string|null $foreignKey the foreign key used to associate the many back to the model
	 * @param  string|null $thisKey the local key used to associate the many back to the model
	 * @return \Hubzero\Database\Relationship\OneToMany
	 * @since  1.3.2
	 **/
	public function oneToMany($model, $relatedKey=null, $thisKey=null)
	{
		// Default the keys if not set
		$thisKey    = $thisKey    ?: $this->getPrimaryKey();
		$relatedKey = $relatedKey ?: strtolower($this->getModelName()) . '_id';

		return new OneToMany($this, $this->resolve($model), $thisKey, $relatedKey);
	}

	/**
	 * Retrieves a many to many model relationship
	 *
	 * @param  string $model the name of the model to relate to the current one
	 * @param  string $associativeTable the name of the intermediate table used to associate model->related
	 * @param  string|null $thisKey the local key used on the associative table
	 * @param  string|null $relatedKey the related key used on the associative table
	 * @return \Hubzero\Database\Relationship\ManyToMany
	 * @since  1.3.2
	 **/
	public function manyToMany($model, $associativeTable=null, $thisKey=null, $relatedKey=null)
	{
		$related   = $this->resolve($model);
		$names     = [strtolower($this->getModelName()), strtolower($related->getModelName())];
		$namespace = (!$this->namespace ? '' : $this->namespace . '_');

		// Sort names alphabetically so both sides of manyToMany will resolve to the same table name
		sort($names);

		// Default the keys and table if not set
		$associativeTable = $associativeTable ?: '#__' . $namespace . implode('_', $names);
		$thisKey          = $thisKey          ?: strtolower($this->getModelName()) . '_id';
		$relatedKey       = $relatedKey       ?: strtolower($related->getModelName()) . '_id';

		return new ManyToMany($this, $related, $associativeTable, $thisKey, $relatedKey);
	}

	/**
	 * Retrieves a belongs to one model relationship
	 *
	 * @param  string $model the name of the model to relate to the current one
	 * @param  string|null $thisKey the local key used to associate the many back to the model
	 * @param  string|null $parentKey the parent key used to associate the model to its parent
	 * @return \Hubzero\Database\Relationship\BelongsToOne
	 * @since  1.3.2
	 **/
	public function belongsToOne($model, $thisKey=null, $parentKey=null)
	{
		$parent = $this->resolve($model);

		// Default the keys if not set
		$thisKey   = $thisKey   ?: strtolower($parent->getModelName()) . '_id';
		$parentKey = $parentKey ?: $this->getPrimaryKey();

		return new BelongsToOne($this, $parent, $thisKey, $parentKey);
	}

	/**
	 * Retrieves a one to many through model relationship
	 *
	 * Note that here, versus the manyToMany relationship, we assume the 'through' item
	 * actually has a formal model for it, rather than just an intermediate table name.
	 *
	 * @param  string      $model      the name of the related model to associate to the current one
	 * @param  string      $through    the name of the intermediate model
	 * @param  string|null $relatedKey the related key used to associate the model to its parent
	 * @param  string|null $localKey   the local key used to associate the many back to the model
	 * @return \Hubzero\Database\Relationship\OneToManyThrough
	 * @since  1.3.2
	 **/
	public function oneToManyThrough($model, $through, $relatedKey=null, $localKey=null)
	{
		// Format the model name and instantiate new object
		$related = $this->resolve($model);
		$through = $this->resolve($through);

		// Keys 
		$localKey   = $localKey   ?: strtolower($this->getModelName()) . '_id';
		$relatedKey = $relatedKey ?: strtolower($through->getModelName()) . '_id';

		return new OneToManyThrough($this, $related, $through->getTableName(), $localKey, $relatedKey);
	}

	/**
	 * Attaches the given model(s) to the current one via its relationship
	 *
	 * @param  string $relationship the relationship to invoke
	 * @param  array|object $models the model or models to attach
	 * @return $this
	 * @since  1.3.2
	 **/
	public function attach($relationship, $models)
	{
		$rows = new Rows;

		foreach ((array)$models as $model)
		{
			$rows->push($model);
		}

		$this->addRelationship($relationship, $this->$relationship()->associate($rows));

		return $this;
	}

	/**
	 * Sets an associated relationship to be retrieved with the current model
	 *
	 * @return $this
	 * @since  1.3.2
	 **/
	public function including()
	{
		// Divide our relationships into those that are constrained and those that are unconstrained
		foreach (func_get_args() as $relationship)
		{
			$this->includes[] = $relationship;
		}

		return $this;
	}

	/**
	 * Retrieves an associated model in conjunction with the current one
	 *
	 * @param  \Hubzero\Database\Rows $rows the rows to parse and augment
	 * @return \Hubzero\Database\Rows
	 * @since  1.3.2
	 **/
	private function parseIncluding($rows)
	{
		$subs       = null;
		$constraint = null;
		foreach ($this->includes as $relationship)
		{
			// Check for array, meaning we have relationship_name => constraint
			if (is_array($relationship)) list($relationship, $constraint) = $relationship;

			// Parse for nested relationships
			if (strpos($relationship, '.')) list($relationship, $subs) = explode('.', $relationship, 2);

			// If we have subs and a constraint, the constraint should apply to the subs, not the intermediate relation
			if (isset($subs) && isset($constraint))
			{
				$subs       = [$subs, $constraint];
				$constraint = null;
			}

			// Get the actual rows
			$rows = $this->$relationship()->seedWithRelation($rows, $relationship, $constraint, $subs);

			// Reset some vars
			$subs       = null;
			$constraint = null;
		}

		return $rows;
	}

	/**
	 * Adds alternate locations to look for model properties
	 *
	 * This method merely adds them to the list. See the __get
	 * method above for the code that actually checks for a
	 * valid attribute on the forwarding model.
	 *
	 * @return $this
	 * @since  1.3.2
	 **/
	public function forwardTo()
	{
		foreach (func_get_args() as $relationship)
		{
			$this->forwards[] = $relationship;
		}

		return $this;
	}

	/**
	 * Adds a new relationship to the current model
	 *
	 * @param  string $name the name of the relationship
	 * @param  object $model the model or rows to add
	 * @return $this
	 * @since  1.3.2
	 **/
	public function addRelationship($name, $model)
	{
		$this->relationships[$name] = $model;

		return $this;
	}

	/**
	 * Gets all relationships
	 *
	 * @return array
	 * @since  1.3.2
	 **/
	public function getRelationships()
	{
		return $this->relationships;
	}

	/**
	 * Gets the defined relationship
	 *
	 * @param  string the relationship to return
	 * @return \Hubzero\Database\Rows|\Hubzero\Database\Relational|static
	 * @since  1.3.2
	 **/
	public function getRelationship($name)
	{
		return isset($this->relationships[$name]) ? $this->relationships[$name] : null;
	}

	/**
	 * Establishes a relationship, fetching the rows as needed
	 *
	 * @param  string $name the name of the relationship
	 * @return $this
	 * @since  1.3.2
	 **/
	public function makeRelationship($name)
	{
		// See if the relationship already exists
		if (!$this->getRelationship($name))
		{
			// Get the child rows/row and set them back on the model as a relationship for future use
			$rows = call_user_func_array(array($this, $name), array())->rows();
			$this->addRelationship($name, $rows);
		}

		return $this;
	}

	/**
	 * Generates automatic created field value
	 *
	 * @return string
	 * @since  1.3.2
	 **/
	public function automaticCreated()
	{
		return Date::toSql();
	}

	/**
	 * Generates automatic created by field value
	 *
	 * @return int
	 * @since  1.3.2
	 **/
	public function automaticCreatedBy()
	{
		return User::get('id');
	}

	/**
	 * Generates automatic asset id field
	 *
	 * @return int
	 * @since  1.3.2
	 **/
	public function automaticAssetId()
	{
		return Asset::resolve($this);
	}
}