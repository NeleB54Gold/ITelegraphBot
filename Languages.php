<?php

class Languages
{
	# Translations ID on Redis		(Optional)	[String]
	public $project_name = 'ITGB';
	# Translations file name		(Required)	[String]
	public $file_name = 'translations.json';
	# OneSkyApp API					(Optional)	[Array]
	public $oneskyapp = [
		# API Key
		'api-key'		=> "",
		# Secret API Key
		'secret'		=> "",
		# Platform ID
		'platform-id'	=> 168533,
		# File name on the platform
		'file_name'		=> 'en_strings.json',
		# True: if you want to save the translations on translations file
		'save_file'		=> 1
	];
	# The cache time				(Optional, Required for Redis) [false: no cache/int: time in seconds]
	private $cache_time = 60 * 60 * 3;
	# Default user language			(Required)	[String of language ID]
	private $user_language = 'en';
	# NeleBot X Database class		(Optional, Required for Redis) [Database Class]
	private $db = [];
	
	# Load configs
	function __construct($user_language = 'en', $db = []) {
		$this->user_language = $user_language;
		if (isset($db->configs) and $db->configs['redis']['status']) {
			$this->db = $db;
			$this->redisCheck();
		} else {
			if (!$this->translations = json_decode(file_get_contents($this->file_name), true)) {
				return ['ok' => 0, 'error_code' => 500, 'description' => 'Unable to get translations data!'];
			}
		}
	}
	
	# Set the default language
	public function setLanguage($user_language = 'en') {
		return $this->user_language = $user_language;
	}
	
	# Load the translations on Redis for more speed (Redis only)
	private function redisCheck () {
		if (!$this->db->rget('tr-' . $this->project_name . '-status')) {
			$this->db->rset('tr-' . $this->project_name . '-status', true, $this->cache_time);
			$trs = $this->getAllTranslations();
			if ($trs['ok']) {
				$this->db->rdel($this->db->rkeys('tr-' . $this->project_name . '*'));
				$this->db->rset('tr-' . $this->project_name . '-status', true, $this->cache_time);
				foreach ($trs['result'] as $lang => $strings) {
					$lang = explode('-', $lang, 2)[0];
					foreach($strings as $stringn => $translation) {
						$this->db->rset('tr-' . $this->project_name . '-' . $lang . '-' . $stringn, $translation, $this->cache_time);
					}
				}
				return 1;
			} else {
				$this->db->rdel('tr-' . $this->project_name . '-status');
			}
		}
		return;
	}
	
	# Reload translations
	public function reload () {
		if (isset($this->db->configs) and $this->db->configs['redis']['status']) {
			$this->db->rdel('tr-' . $this->project_name . '-status');
			return $this->redisCheck();
		}
		return 0;
	}
	
	# Get the translation from string ID
	public function getTranslation($string, $args = [], $user_lang = 'def') {
		if ($user_lang == 'def') {
			$lang = $this->user_language;
		} else {
			$lang = strtolower($user_lang);
		}
		$string = str_replace(' ', '', $string);
		if (isset($this->db->configs)) {
			if ($lang !== 'en' and $t_string = $this->db->rget('tr-' . $this->project_name . '-' . $lang . '-' . $string)) {
			} elseif ($t_string = $this->db->rget('tr-' . $this->project_name . '-en-' . $string)) {
			} else {
				$t_string = '👾';
			}
		} else {
			if ($lang !== 'en' and $t_string = $this->translations[$lang][$string]) {
				
			} elseif ($t_string = $this->translations['en'][$string]) {
				
			} else {
				$t_string = '🤖';
			}
		}
		if (!empty($args) and is_array($args)) {
			$args = array_values($args);
			foreach(range(0, count($args) - 1) as $num) {
				$t_string = str_replace('[' . $num . ']', $args[$num], $t_string);
			}
		}
		return $t_string;
	}
	
	# Get all translations from the file, oneskyapp or from the current script
	public function getAllTranslations () {
		if (isset($this->translations)) {
			return ['ok' => 1, 'result' => $this->translations];
		} elseif ($this->oneskyapp['platform-id']) {
			date_default_timezone_set('GMT');
			$time = time();
			$args = [
				'api-key'		=> $this->oneskyapp['api-key'],
				'timestamp'		=> $time,
				'dev-hash'		=> md5($time . $this->oneskyapp['secret'])
			];
			$args['platform-id'] = $this->oneskyapp['platform-id'];
			$url = "http://api.oneskyapp.com/2/string/output?" . http_build_query($args);
			if (!isset($this->curl)) $this->curl = curl_init();
			curl_setopt_array($this->curl, [
				CURLOPT_URL				=> $url,
				CURLOPT_POST			=> 0,
				CURLOPT_TIMEOUT			=> 2,
				CURLOPT_RETURNTRANSFER	=> 1
			]);
			$r = json_decode(curl_exec($this->curl), 1);
			if (isset($r['translation'])) {
				if (isset($r['translation'][$this->oneskyapp['file_name']]) and !empty($r['translation'][$this->oneskyapp['file_name']])) {
					if ($this->oneskyapp['save_file']) {
						file_put_contents($this->file_name, json_encode($r['translation'][$this->oneskyapp['file_name']]));
					}
					return ['ok' => 1, 'result' => $r['translation'][$this->oneskyapp['file_name']]];
				} else {
					return ['ok' => 0, 'result' => [], 'notice' => 'File name not found'];
				}
			} else {
				return ['ok' => 0, 'result'	=> $r];
			}
		} elseif (file_exists($this->file_name)) {
			$file = file_get_contents($this->file_name);
			if ($file) {
				if ($translations = json_decode($file, 1)) {
					return ['ok' => 1, 'result' => $translations];
				}
				return ['ok' => 1, 'result' => [], 'notice' => 'Failed to get JSON format from the file!'];
			}
			return ['ok' => 0, 'result' => [], 'notice' => 'The file is empty!'];
		} else {
			return ['ok' => 1, 'result' => [], 'notice' => 'No configs for translations'];
		}
	}
}

?>
