<?php
/**
 * @package     Joomla.Platform
 * @subpackage  HTML
 *
 * @copyright   Copyright (C) 2005 - 2011 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

defined('JPATH_PLATFORM') or die;

jimport('joomla.filesystem.file');

/**
 * Extended Utility class for handling JavaScript dependencies.
 * 
 * Uses Packager class from Valerio Proietti
 *
 * @package     Joomla.Platform
 * @subpackage  HTML
 * @since       11.1
 */
abstract class JHtmlScript
{

	public static $packages = array();
	
	public static $components = array();

	/**
	 * Registers a package of scripts.
	 *
	 * @param   string  $path  The path to the directory that contains the package file.
	 *
	 * @return  void
	 *
	 * @since   11.3
	 */
	public static function registerPackage($path)
	{
		self::$packages[] = $path;
	}

	/**
	 * Add a component that is required on the page.
	 *
	 * @param   array  $components  Array containing the names of the required components.
	 *
	 * @return  void
	 *
	 * @since   11.3
	 */
	public static function addComponents($components)
	{
		self::$components = array_merge(self::$components, (array)$components);
	}

	/**
	 * Build the JavaScript file
	 *
	 * @param   string   $path   Path to the folder that the JavaScript file should be stored in.
	 * @param   boolean  $debug  True if generated file should be compressed, false otherwise.
	 *
	 * @return  string  Name of the generated file (which is a hash of the required components).
	 *
	 * @since   11.3
	 */
	public static function render($path, $debug = false)
	{
		self::$components = array_unique(self::$components);
		$sorted = self::$components;
		sort($sorted);
		$filename = md5(serialize($sorted));
		$file = $path . $filename .'.js';
		
		if (!JFile::exists($file))
		{
			jimport('mootools.packager');
			$packager = new Packager(self::$packages);
			JFile::write($file, $packager->build_from_components(self::$components));
		}
		return $file;
	}
}
