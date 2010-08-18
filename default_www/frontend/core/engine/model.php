<?php

/**
 * FrontendModel
 *
 * @package		frontend
 * @subpackage	core
 *
 * @author 		Tijs Verkoyen <tijs@netlash.com>
 * @since		2.0
 */
class FrontendModel
{
	/**
	 * cached module-settings
	 *
	 * @var	array
	 */
	private static $moduleSettings = array();


	/**
	 * Add parameters to an URL
	 *
	 * @return	string
	 * @param	string $URL			The URL to append the parameters too.
	 * @param	array $parameters	The parameters as key-value-pairs
	 */
	public static function addURLParameters($URL, array $parameters)
	{
		// redefine
		$URL = (string) $URL;

		// no parameters means no appending
		if(empty($parameters)) return $URL;

		// build querystring
		$queryString = http_build_query($parameters, null, '&amp;');

		// already GET parameters?
		if(mb_strpos($URL, '?') !== false) return $URL .= '&'. $queryString;

		// no GET-parameters defined before
		else return $URL .= '?'. $queryString;
	}


	/**
	 * Get (or create and get) a database-connection
	 * @later split the write and read connection
	 *
	 * @return	SpoonDatabase
	 * @param	bool[optional] $write	Do you want the write-connection or not?
	 */
	public static function getDB($write = false)
	{
		// redefine
		$write = (bool) $write;

		// do we have a db-object ready?
		if(!Spoon::isObjectReference('database'))
		{
			// create instance
			$db = new SpoonDatabase(DB_TYPE, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE);

			// utf8 compliance & MySQL-timezone
			$db->execute('SET CHARACTER SET utf8, NAMES utf8, time_zone = "+0:00";');

			// store
			Spoon::setObjectReference('database', $db);
		}

		return Spoon::getObjectReference('database');
	}


	/**
	 * Get a module setting
	 *
	 * @return	mixed
	 * @param	string $module					The module wherefor a setting has to be retrieved.
	 * @param	string $name					The name of the setting to be retrieved.
	 * @param	mixed[optional] $defaultValue	A value that will be stored if the setting isn't present.
	 */
	public static function getModuleSetting($module, $name, $defaultValue = null)
	{
		// redefine
		$module = (string) $module;
		$name = (string) $name;

		// get them all
		if(empty(self::$moduleSettings))
		{
			// get db
			$db = self::getDB();

			// fetch settings
			$settings = (array) $db->getRecords('SELECT ms.module, ms.name, ms.value
													FROM modules_settings AS ms
													INNER JOIN modules AS m ON ms.module = m.name
													WHERE m.active = ?;', 'Y');

			// loop settings and cache them, also unserialize the values
			foreach($settings as $row) self::$moduleSettings[$row['module']][$row['name']] = unserialize($row['value']);
		}

		// if the setting doesn't exists, store it (it will be available from te cache)
		if(!isset(self::$moduleSettings[$module][$name])) self::setModuleSetting($module, $name, $defaultValue);

		// return
		return self::$moduleSettings[$module][$name];
	}


	/**
	 * Get all module settings at once
	 *
	 * @return	array
	 * @param	string $module	The module wherefor all settings has to be retrieved.
	 */
	public static function getModuleSettings($module)
	{
		// redefine
		$module = (string) $module;

		// get them all
		if(empty(self::$moduleSettings[$module]))
		{
			// get db
			$db = self::getDB();

			// fetch settings
			$settings = (array) $db->getRecords('SELECT ms.module, ms.name, ms.value
													FROM modules_settings AS ms;');

			// loop settings and cache them, also unserialize the values
			foreach($settings as $row) self::$moduleSettings[$row['module']][$row['name']] = unserialize($row['value']);
		}

		// validate again
		if(!isset(self::$moduleSettings[$module])) return array();

		// return
		return self::$moduleSettings[$module];
	}


	/**
	 * Get all data for a page
	 *
	 * @return	array
	 * @param	int $pageId		The pageId wherefor the data will be retrieved.
	 */
	public static function getPage($pageId)
	{
		// redefine
		$pageId = (int) $pageId;

		// get database instance
		$db = self::getDB();

		// get data
		$record = (array) $db->getRecord('SELECT p.id, p.revision_id, p.template_id, p.title, p.navigation_title, p.navigation_title_overwrite, p.data,
												m.title AS meta_title, m.title_overwrite AS meta_title_overwrite,
												m.keywords AS meta_keywords, m.keywords_overwrite AS meta_keywords_overwrite,
												m.description AS meta_description, m.description_overwrite AS meta_description_overwrite,
												m.custom AS meta_custom,
												m.url, m.url_overwrite,
												t.path AS template_path, t.data as template_data
											FROM pages AS p
											INNER JOIN meta AS m ON p.meta_id = m.id
											INNER JOIN pages_templates AS t ON p.template_id = t.id
											WHERE p.id = ? AND p.status = ? AND p.hidden = ? AND p.language = ?
											LIMIT 1;',
											array($pageId, 'active', 'N', FRONTEND_LANGUAGE));

		// validate
		if(empty($record)) return array();

		// unserialize page data and template data
		if(isset($record['data']) && $record['data'] != '') $record['data'] = unserialize($record['data']);
		if(isset($record['template_data']) && $record['template_data'] != '') $record['template_data'] = @unserialize($record['template_data']);

		// get blocks
		$record['blocks'] = (array) $db->getRecords('SELECT pb.extra_id, pb.html,
														pe.module AS extra_module, pe.type AS extra_type, pe.action AS extra_action, pe.data AS extra_data
														FROM pages_blocks AS pb
														LEFT OUTER JOIN pages_extras AS pe ON pb.extra_id = pe.id
														WHERE pb.revision_id = ? AND pb.status = ?
														ORDER BY pb.id;',
														array($record['revision_id'], 'active'));

		// loop blocks
		foreach($record['blocks'] as $index => $row)
		{
			// unserialize data if it is available
			if(isset($row['data'])) $record['blocks'][$index]['data'] = unserialize($row['data']);
		}

		return $record;
	}


	/**
	 * Get the UTC date in a specific format. Use this method when inserting dates in the database!
	 *
	 * @return	string
	 * @param	string[optional] $format	The format wherin the data will be returned, if not provided we will return it in MySQL-datetime-format.
	 * @param	int[optional] $timestamp	A UNIX-timestamp that will be used as base.
	 */
	public static function getUTCDate($format = null, $timestamp = null)
	{
		// init var
		$format = ($format !== null) ? (string) $format : 'Y-m-d H:i:s';

		// no timestamp given
		if($timestamp === null) return gmdate($format);

		// timestamp given
		return gmdate($format, (int) $timestamp);
	}


	/**
	 * General method to check if something is spam
	 *
	 * @return	bool
	 * @param	string $content				The content that was submitted
	 * @param	string $permalink			The permanent location of the entry the comment was submitted to
	 * @param	string[optional] $author	Commenters name
	 * @param	string[optional] $email		Commenters email address
	 * @param	string[optional] $url		Commenters URL
	 * @param	string[optional] $type		May be blank, comment, trackback, pingback, or a made up value like "registration"
	 */
	public static function isSpam($content, $permaLink, $author = null, $email = null, $URL = null, $type = 'comment')
	{
		// get some settings
		$akismetKey = self::getModuleSetting('core', 'akismet_key');

		// invalid key, so we can't detect spam
		if($akismetKey === '') return false;

		// require the class
		require_once PATH_LIBRARY .'/external/akismet.php';

		// create new instance
		$akismet = new Akismet($akismetKey, SITE_URL);

		// set properties
		$akismet->setTimeOut(10);
		$akismet->setUserAgent('Fork CMS/2.0');

		// try it to decide it the item is spam
		try
		{
			// check with Akismet if the item is spam
			return $akismet->isSpam($content, $author, $email, $URL, $permaLink, $type);
		}

		// catch exceptions
		catch(Exception $e)
		{
			// in debug mode we want to see exceptions, otherwise the fallback will be triggered
			if(SPOON_DEBUG) throw $e;
		}

		// when everything fails
		return false;
	}


	/**
	 * Store a modulesetting
	 *
	 * @return	void
	 * @param	string $module		The module wherefor a setting has to be stored.
	 * @param	string $name		The name of the setting.
	 * @param	mixed $value		The value (will be serialized so make sure the type is correct).
	 */
	public static function setModuleSetting($module, $name, $value)
	{
		// redefine
		$module = (string) $module;
		$name = (string) $name;
		$value = serialize($value);

		// get db
		$db = self::getDB(true);

		// store
		$db->execute('INSERT INTO modules_settings (module, name, value)
						VALUES (?, ?, ?)
						ON DUPLICATE KEY UPDATE value = ?;',
						array($module, $name, $value, $value));

		// store in cache
		self::$moduleSettings[$module][$name] = unserialize($value);
	}
}

?>