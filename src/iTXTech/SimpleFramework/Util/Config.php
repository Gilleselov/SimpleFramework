<?php

/*
 *
 * SimpleFramework
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author iTX Technologies
 * @link https://itxtech.org
 *
 */

namespace iTXTech\SimpleFramework\Util;

use iTXTech\SimpleFramework\Console\Logger;

class Config{
	const DETECT = -1; //Detect by file extension
	const PROPERTIES = 0; // .properties
	const CNF = Config::PROPERTIES; // .cnf
	const JSON = 1; // .js, .json
	const YAML = 2; // .yml, .yaml
	//const EXPORT = 3; // .export, .xport
	const SERIALIZED = 4; // .sl
	const ENUM = 5; // .txt, .list, .enum
	const ENUMERATION = Config::ENUM;

	/** @var array */
	private $config = [];

	private $nestedCache = [];

	/** @var string */
	private $file;
	/** @var boolean */
	private $correct = false;
	/** @var integer */
	private $type = Config::DETECT;

	public static $formats = [
		"properties" => Config::PROPERTIES,
		"cnf" => Config::CNF,
		"conf" => Config::CNF,
		"config" => Config::CNF,
		"json" => Config::JSON,
		"js" => Config::JSON,
		"yml" => Config::YAML,
		"yaml" => Config::YAML,
		//"export" => Config::EXPORT,
		//"xport" => Config::EXPORT,
		"sl" => Config::SERIALIZED,
		"serialize" => Config::SERIALIZED,
		"txt" => Config::ENUM,
		"list" => Config::ENUM,
		"enum" => Config::ENUM,
	];

	/**
	 * @param string $file Path of the file to be loaded
	 * @param int    $type Config type to load, -1 by default (detect)
	 * @param array  $default Array with the default values that will be written to the file if it did not exist
	 * @param null   &$correct Sets correct to true if everything has been loaded correctly
	 */
	public function __construct($file, $type = Config::DETECT, $default = [], &$correct = null){
		$this->load($file, $type, $default);
		$correct = $this->correct;
	}

	/**
	 * Removes all the changes in memory and loads the file again
	 */
	public function reload(){
		$this->config = [];
		$this->nestedCache = [];
		$this->correct = false;
		$this->load($this->file, $this->type);
	}

	/**
	 * @param $str
	 *
	 * @return mixed
	 */
	public static function fixYAMLIndexes($str){
		return preg_replace("#^([ ]*)([a-zA-Z_]{1}[ ]*)\\:$#m", "$1\"$2\":", $str);
	}

	/**
	 * @param       $file
	 * @param int   $type
	 * @param array $default
	 *
	 * @return bool
	 */
	public function load($file, $type = Config::DETECT, $default = []): bool{
		$this->correct = true;
		$this->type = (int) $type;
		$this->file = $file;
		if(!is_array($default)){
			$default = [];
		}
		if(!file_exists($file)){
			$this->config = $default;
			$this->save();
		}else{
			if($this->type === Config::DETECT){
				$extension = explode(".", basename($this->file));
				$extension = strtolower(trim(array_pop($extension)));
				if(isset(Config::$formats[$extension])){
					$this->type = Config::$formats[$extension];
				}else{
					$this->correct = false;
				}
			}
			if($this->correct === true){
				$content = file_get_contents($this->file);
				switch($this->type){
					case Config::PROPERTIES:
					case Config::CNF:
						$this->parseProperties($content);
						break;
					case Config::JSON:
						$this->config = json_decode($content, true);
						break;
					case Config::YAML:
						$content = self::fixYAMLIndexes($content);
						$this->config = yaml_parse($content);
						break;
					case Config::SERIALIZED:
						$this->config = unserialize($content);
						break;
					case Config::ENUM:
						$this->parseList($content);
						break;
					default:
						$this->correct = false;

						return false;
				}
				if(!is_array($this->config)){
					$this->config = $default;
				}
				if($this->fillDefaults($default, $this->config) > 0){
					$this->save();
				}
			}else{
				return false;
			}
		}

		return true;
	}

	public function check(): bool{
		return $this->correct === true;
	}

	public function save(?int $option = null) : bool{
		if($this->correct === true){
			try{
				$content = null;
				switch($this->type){
					case Config::PROPERTIES:
					case Config::CNF:
						$content = $this->writeProperties();
						break;
					case Config::JSON:
						$content = json_encode($this->config, $option ?? (JSON_PRETTY_PRINT | JSON_BIGINT_AS_STRING));
						break;
					case Config::YAML:
						$content = yaml_emit($this->config, $option ?? YAML_UTF8_ENCODING);
						break;
					case Config::SERIALIZED:
						$content = serialize($this->config);
						break;
					case Config::ENUM:
						$content = implode("\r\n", array_keys($this->config));
						break;
				}
				file_put_contents($this->file, $content);
			}catch(\Throwable $e){
				Logger::error("Could not save Config " . $this->file . ": " . $e->getMessage());
				Logger::logException($e);
			}
			return true;
		}else{
			return false;
		}
	}

	/**
	 * @param $k
	 *
	 * @return boolean|mixed
	 */
	public function __get($k){
		return $this->get($k);
	}

	/**
	 * @param $k
	 * @param $v
	 */
	public function __set($k, $v){
		$this->set($k, $v);
	}

	/**
	 * @param $k
	 *
	 * @return boolean
	 */
	public function __isset($k){
		return $this->exists($k);
	}

	/**
	 * @param $k
	 */
	public function __unset($k){
		$this->remove($k);
	}

	/**
	 * @param $key
	 * @param $value
	 */
	public function setNested($key, $value){
		$vars = explode(".", $key);
		$base = array_shift($vars);

		if(!isset($this->config[$base])){
			$this->config[$base] = [];
		}

		$base =& $this->config[$base];

		while(count($vars) > 0){
			$baseKey = array_shift($vars);
			if(!isset($base[$baseKey])){
				$base[$baseKey] = [];
			}
			$base =& $base[$baseKey];
		}

		$base = $value;
		$this->nestedCache[$key] = $value;
	}

	/**
	 * @param       $key
	 * @param mixed $default
	 *
	 * @return mixed
	 */
	public function getNested($key, $default = null){
		if(isset($this->nestedCache[$key])){
			return $this->nestedCache[$key];
		}

		$vars = explode(".", $key);
		$base = array_shift($vars);
		if(isset($this->config[$base])){
			$base = $this->config[$base];
		}else{
			return $default;
		}

		while(count($vars) > 0){
			$baseKey = array_shift($vars);
			if(is_array($base) and isset($base[$baseKey])){
				$base = $base[$baseKey];
			}else{
				return $default;
			}
		}

		return $this->nestedCache[$key] = $base;
	}

	/**
	 * @param       $k
	 * @param mixed $default
	 *
	 * @return boolean|mixed
	 */
	public function get($k, $default = false){
		return ($this->correct and isset($this->config[$k])) ? $this->config[$k] : $default;
	}

	/**
	 * @param string $k key to be set
	 * @param mixed  $v value to set key
	 */
	public function set($k, $v = true){
		$this->config[$k] = $v;
		foreach($this->nestedCache as $nestedKey => $nvalue){
			if(substr($nestedKey, 0, strlen($k) + 1) === ($k . ".")){
				unset($this->nestedCache[$nestedKey]);
			}
		}
	}

	/**
	 * @param array $v
	 */
	public function setAll($v){
		$this->config = $v;
	}

	/**
	 * @param      $k
	 * @param bool $lowercase If set, searches Config in single-case / lowercase.
	 *
	 * @return boolean
	 */
	public function exists($k, $lowercase = false){
		if($lowercase === true){
			$k = strtolower($k); //Convert requested  key to lower
			$array = array_change_key_case($this->config, CASE_LOWER); //Change all keys in array to lower
			return isset($array[$k]); //Find $k in modified array
		}else{
			return isset($this->config[$k]);
		}
	}

	/**
	 * @param $k
	 */
	public function remove($k){
		unset($this->config[$k]);
	}

	/**
	 * @param bool $keys
	 *
	 * @return array
	 */
	public function getAll($keys = false){
		return ($keys === true ? array_keys($this->config) : $this->config);
	}

	/**
	 * @param array $defaults
	 */
	public function setDefaults(array $defaults){
		$this->fillDefaults($defaults, $this->config);
	}

	/**
	 * @param $default
	 * @param $data
	 *
	 * @return integer
	 */
	private function fillDefaults($default, &$data){
		$changed = 0;
		foreach($default as $k => $v){
			if(is_array($v)){
				if(!isset($data[$k]) or !is_array($data[$k])){
					$data[$k] = [];
				}
				$changed += $this->fillDefaults($v, $data[$k]);
			}elseif(!isset($data[$k])){
				$data[$k] = $v;
				++$changed;
			}
		}

		return $changed;
	}

	/**
	 * @param $content
	 */
	private function parseList($content){
		foreach(explode("\n", trim(str_replace("\r\n", "\n", $content))) as $v){
			$v = trim($v);
			if($v == ""){
				continue;
			}
			$this->config[$v] = true;
		}
	}

	/**
	 * @return string
	 */
	private function writeProperties(){
		$content = "#Properties Config file\r\n#" . date("D M j H:i:s T Y") . "\r\n";
		foreach($this->config as $k => $v){
			if(is_bool($v) === true){
				$v = $v === true ? "on" : "off";
			}elseif(is_array($v)){
				$v = implode(";", $v);
			}
			$content .= $k . "=" . $v . "\r\n";
		}

		return $content;
	}

	/**
	 * @param $content
	 */
	private function parseProperties($content){
		if(preg_match_all('/([a-zA-Z0-9\-_\.]*)=([^\r\n]*)/u', $content, $matches) > 0){ //false or 0 matches
			foreach($matches[1] as $i => $k){
				$v = trim($matches[2][$i]);
				switch(strtolower($v)){
					case "on":
					case "true":
					case "yes":
						$v = true;
						break;
					case "off":
					case "false":
					case "no":
						$v = false;
						break;
				}
				if(isset($this->config[$k])){
					Logger::debug("[Config] Repeated property " . $k . " on file " . $this->file);
				}
				$this->config[$k] = $v;
			}
		}
	}

}
