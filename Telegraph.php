<?php

class Telegraph {
	public $configs = [];
	public $endpoint = 'https://api.telegra.ph/';
	
	public function __construct ($configs) {
		$this->configs['post'] = $configs['post'];
		$this->configs['timeout'] = $configs['timeout'];
		$this->configs['response'] = true;
	}
	
	public function getTempPw () {
		#	This password will be used to temporary crypt account keys!
		#	You can change it when you want, account with old pw will require the login
		#	return 'YOUR_CUSTOM_PASSWORD';
		return 'YOUR_CUSTOM_PASSWORD';
	}
	
	public function request ($url, $args = [], $post = 'def', $response = 'def', $timeout = 'def') {
		if ($post === 'def')		$post = $this->configs['post'];
		if ($response === 'def')	$response = $this->configs['response'];
		if ($timeout === 'def')		$timeout = $this->configs['timeout'];
		if (!isset($this->curl))	$this->curl = curl_init();
		curl_setopt_array($this->curl, [
			CURLOPT_URL				=> $url,
			CURLOPT_POST			=> $post,
			CURLOPT_POSTFIELDS		=> $args,
			CURLOPT_TIMEOUT			=> $timeout,
			CURLOPT_RETURNTRANSFER	=> $response
		]);
		$output = curl_exec($this->curl);
		if ($json_output = json_decode($output, 1)) return $json_output;
		if ($output) return $output;
		if ($error = curl_error($this->curl)) return ['ok' => 0, 'error_code' => 500, 'description' => 'CURL Error: ' . $error];
		return;
	}
	
	public function api ($method) {
		return $this->endpoint . $method;
	}
	
	public function createAccount ($short_name, $author_name = null, $author_url = null) {
		$args = [
			'short_name'	=> $short_name
		];
		if (!is_null($author_name)) $args['author_name'] = $author_name;
		if (!is_null($author_url)) $args['author_url'] = $author_url;
		return $this->request($this->api('createAccount'), $args);
	}
	
	public function editAccountInfo ($access_token, $short_name, $author_name = null, $author_url = null) {
		$args = [
			'access_token'	=> $access_token,
			'short_name'	=> $short_name
		];
		if (!is_null($author_name)) $args['author_name'] = $author_name;
		if (!is_null($author_url)) $args['author_url'] = $author_url;
		return $this->request($this->api('editAccountInfo'), $args);
	}
	
	public function getAccountInfo ($key, $fields = null) {
		$args = [
			'access_token'	=> $key
		];
		if (is_array($fields)) $args['fields'] = json_encode($fields);
		$result = $this->request($this->api('getAccountInfo'), $args);
		if (in_array('page_count', $fields)) {
			$result['page_count'] = count($this->getPageList($key, 0, 200)['result']['pages']);
		}
		return $result;
	}
	
	public function revokeAccessToken ($access_token) {
		$args['access_token'] = $access_token;
		return $this->request($this->api('revokeAccessToken'), $args);
	}
	
	public function createPage ($access_token, $title, $author_name = null, $author_url = null, $content, $return_content = 0) {
		$args = [
			'access_token'	=> $access_token,
			'title'			=> $title,
			'content'		=> json_encode($content)
		];
		if (!is_null($author_name)) $args['author_name'] = $author_name;
		if (!is_null($author_url)) $args['author_url'] = $author_url;
		if (is_array($return_content)) $args['return_content'] = $return_content;
		return $this->request($this->api('createPage'), $args);
	}
	
	public function editPage ($access_token, $path, $title, $author_name = null, $author_url = null, $content, $return_content = 0) {
		$args = [
			'access_token'	=> $access_token,
			'path'			=> $path,
			'title'			=> $title,
			'content'		=> json_encode($content)
		];
		if (!is_null($author_name)) $args['author_name'] = $author_name;
		if (!is_null($author_url)) $args['author_url'] = $author_url;
		if (is_array($return_content)) $args['return_content'] = $return_content;
		return $this->request($this->api('editPage'), $args);
	}
	
	public function getPage ($path, $return_content = false) {
		if ($return_content) $args['return_content'] = true;
		return $this->request($this->api('getPage') . '/' . $path, $args);
	}
	
	public function getPageList ($access_token, $offset = 0, $limit = 50) {
		$args = [
			'access_token'	=> $access_token,
			'offset'		=> $offset,
			'limit'			=> $limit
		];
		$r = $this->request($this->api('getPageList'), $args);
		if (!$r['ok']) return $r;
		foreach ($r['result']['pages'] as $page) {
			if ($page['title'] != '404: Not found') $pages[] = $page;
		}
		$r['result']['pages'] = $pages;
		return $r;
	}
	
	public function getViews ($path, $time = 0) {
		$args['path'] = $path;
		if ($time) {
			$args['year'] = date('Y', $time);
			$args['month'] = date('n', $time);
			$args['day'] = date('j', $time);
			$args['hour'] = date('G', $time);
		}
		return $this->request($this->api('getViews'), $args);
	}
	
	public function upload ($file_name, $mime_type = null) {
		$args['file'] = curl_file_create($file_name, $mime_type); 
		return $this->request('https://telegra.ph/upload', $args);
	}
}

?>
