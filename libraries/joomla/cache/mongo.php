<?php
/**
 * @package     Joomla.Platform
 * @subpackage  Cache
 *
 * @copyright   Copyright (C) 2005 - 2012 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

defined('JPATH_PLATFORM') or die;

/**
 * MongoDB cache driver for the Joomla Platform.
 *
 * @package     Joomla.Platform
 * @subpackage  Cache
 * @since       13.1
 */
class JCacheMongo extends JCache
{
	/**
	 * @var    MongoCollection  The MongoDB collection object for the cache collection.
	 * @since  13.1
	 */
	private $_collection;

	/**
	 * Constructor.
	 *
	 * @param   JRegistry  $options  Caching options object.
	 *
	 * @since   13.1
	 * @throws  RuntimeException
	 */
	public function __construct(JRegistry $options = null)
	{
		parent::__construct($options);

		if (!extension_loaded('mongo') || !class_exists('Mongo'))
		{
			throw new RuntimeException('MongoDB not supported.');
		}
	}

	/**
	 * Store the cached data by id.
	 *
	 * @param   string  $cacheId  The cache data id
	 * @param   mixed   $data     The data to store
	 *
	 * @return  JCache  This object for method chaining.
	 *
	 * @since   13.1
	 * @throws  RuntimeException
	 */
	public function store($cacheId, $data)
	{
		if ($this->exists($cacheId, true))
		{
			$this->set($cacheId, $data, $this->options->get('ttl'));
		}
		else
		{
			$this->add($cacheId, $data, $this->options->get('ttl'));
		}

		if ($this->options->get('runtime'))
		{
			self::$runtime[$cacheId] = $data;
		}

		return $this;
	}

	/**
	 * Method to add a storage entry.
	 *
	 * @param   string   $key    The storage entry identifier.
	 * @param   mixed    $value  The data to be stored.
	 * @param   integer  $ttl    The number of seconds before the stored data expires.
	 *
	 * @return  void
	 *
	 * @since   13.1
	 * @throws  RuntimeException
	 */
	protected function add($key, $value, $ttl)
	{
		$this->_connect();

		$data = array(
			'_id' => $key,
			'ttl' => time() + (int) $ttl,
			'data' => $value
		);

		$safe = (bool) $this->options->get('mongo.safe', false);

		try
		{
			$this->_collection->insert($data, array('safe' => $safe));
		}
		catch (Exception $e)
		{
			throw new RuntimeException(sprintf('Unable to add cache entry for %s. Error message `%s`.', $key, $e->getMessage()), 500, $e);
		}
	}

	/**
	 * Method to determine whether a storage entry has been set for a key.
	 *
	 * @param   string   $key             The storage entry identifier.
	 * @param   boolean  $includeExpired  Should expired data be counted when checking existence.
	 *
	 * @return  boolean
	 *
	 * @since   13.1
	 */
	protected function exists($key, $includeExpired = false)
	{
		$this->_connect();

		try
		{
			$data = $this->_collection->findOne(array('_id' => $key));
		}
		catch (Exception $e)
		{
			throw new RuntimeException(sprintf('Unable to check cache entry for %s. Error message `%s`.', $key, $e->getMessage()), 500, $e);
		}

		return $includeExpired ? !empty($data) : (!empty($data) && $data['ttl'] > time());
	}

	/**
	 * Method to get a storage entry value from a key.
	 *
	 * @param   string  $key  The storage entry identifier.
	 *
	 * @return  mixed
	 *
	 * @since   13.1
	 * @throws  RuntimeException
	 */
	protected function fetch($key)
	{
		$this->_connect();

		try
		{
			$data = $this->_collection->findOne(array('_id' => $key));
		}
		catch (Exception $e)
		{
			throw new RuntimeException(sprintf('Unable to fetch cache entry for %s. Error message `%s`.', $key, $e->getMessage()), 500, $e);
		}

		// If we had a miss just return null.
		return (!empty($data) && $data['ttl'] > time()) ? $data['data'] : null;
	}

	/**
	 * Method to flush values from the cache storage.
	 *
	 * @param   string   $pattern  The pattern to use to empty particular items.
	 * @param   boolean  $type     Type of the pattern (prefix, regular expression, etc).
	 *
	 * @return  mixed
	 *
	 * @since   13.1
	 * @throws  RuntimeException
	 */
	protected function flush($pattern = '', $type = self::TYPE_PREFIX)
	{
		$this->_connect();

		// Flush all of cache.
		if (empty($pattern))
		{
			$safe = (bool) $this->options->get('mongo.safe', false);

			try
			{
				$this->_collection->remove(array(), array('safe' => $safe));
			}
			catch (Exception $e)
			{
				throw new RuntimeException(sprintf('Unable to flush the cache. Error message `%s`.', $e->getMessage()), 500, $e);
			}
		}
		// We are trying to flush cache entries that match a specific pattern.
		else
		{
			try
			{
				$regex = $this->_buildRegex($pattern, $type);
				$this->_collection->remove(array('_id' => $regex), array('safe' => true));
			}
			catch (Exception $e)
			{
				$patternType = (self::TYPE_PREFIX == $type) ? 'prefix' : ((self::TYPE_REGEX == $type) ? 'regular expression' : '');
				throw new RuntimeException(
					sprintf('Unable to flush the cache matching %s "%s". Error message `%s`.', $patternType, $pattern, $e->getMessage()),
					500,
					$e
				);
			}
		}
	}

	/**
	 * Method to remove a storage entry for a key.
	 *
	 * @param   string  $key  The storage entry identifier.
	 *
	 * @return  void
	 *
	 * @since   13.1
	 * @throws  RuntimeException
	 */
	protected function delete($key)
	{
		$this->_connect();

		try
		{
			$this->_collection->remove(array('_id' => $key), array('justOne' => true, 'safe' => true));
		}
		catch (Exception $e)
		{
			throw new RuntimeException(sprintf('Unable to remove cache entry for %s. Error message `%s`.', $key, $e->getMessage()), 500, $e);
		}
	}

	/**
	 * Method to set a value for a storage entry.
	 *
	 * @param   string   $key    The storage entry identifier.
	 * @param   mixed    $value  The data to be stored.
	 * @param   integer  $ttl    The number of seconds before the stored data expires.
	 *
	 * @return  void
	 *
	 * @since   13.1
	 * @throws  RuntimeException
	 */
	protected function set($key, $value, $ttl)
	{
		$this->_connect();

		$data = array(
			'_id' => $key,
			'ttl' => time() + (int) $ttl,
			'data' => $value
		);

		$safe = (bool) $this->options->get('mongo.safe', false);

		try
		{
			$this->_collection->save($data, array('safe' => $safe));
		}
		catch (Exception $e)
		{
			throw new RuntimeException(sprintf('Unable to set cache entry for %s. Error message `%s`.', $key, $e->getMessage()), 500, $e);
		}
	}

	/**
	 * Connect to the Memcached servers if the connection does not already exist.
	 *
	 * @return  void
	 *
	 * @since   13.1
	 */
	private function _connect()
	{
		// We want to only create the connection once.
		if (isset($this->_collection))
		{
			return;
		}

		$dsn = $this->_buildDsn();

		$options = array();

		if ($this->options->get('mongo.pool', true))
		{
			$options['persist'] = 'x';
		}

		$dbName = $this->options->get('mongo.database', 'cache');
		$collectionName = $this->options->get('mongo.collection', 'cache');

		$m = new Mongo($dsn, $options);
		$this->_collection = $m->selectDB($dbName)->selectCollection($collectionName);
	}

	/**
	 * Build the MongoDB connection DSN.
	 *
	 * @return  string
	 *
	 * @since   13.1
	 */
	private function _buildDsn()
	{
		$dsn = 'mongodb://';

		// Add credentials if they are present.
		if ($this->options->get('mongo.username'))
		{
			$dsn .= $this->options->get('mongo.username');

			if ($this->options->get('mongo.password'))
			{
				$dsn .= ':' . $this->options->get('mongo.password');
			}

			$dsn .= '@';
		}

		// Add the host and port.
		$dsn .= $this->options->get('mongo.host', 'localhost');

		if ($this->options->get('mongo.port'))
		{
			$dsn .= ':' . (int) $this->options->get('mongo.port', 27017);
		}

		// Add the optional database name.
		if ($this->options->get('mongo.database'))
		{
			$dsn .= '/' . $this->options->get('mongo.database', 'cache');
		}

		return $dsn;
	}

	/**
	 * Build a MongoRegex object based on a pattern.
	 *
	 * @param   string   $pattern  The pattern for which to build the regex.
	 * @param   boolean  $type     Type of the pattern (prefix, regular expression, etc).
	 *
	 * @return  MongoRegex
	 *
	 * @since   13.1
	 * @throws  RuntimeException
	 */
	private function _buildRegex($pattern = '', $type = self::TYPE_PREFIX)
	{
		switch ($type)
		{
			case self::TYPE_PREFIX:
				$pattern = '/^' . preg_quote($pattern, '/') . '/';
				break;
			case self::TYPE_REGEX:
				break;
			default:
				throw new RuntimeException(sprintf('Unsupported pattern type "%s".', $type));
				break;
		}

		return new MongoRegex($pattern);
	}
}
