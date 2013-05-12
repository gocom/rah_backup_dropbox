<?php

/**
 * Dropbox module for rah_backup.
 *
 * Backups your site backups to your Dropbox account.
 *
 * @author  Jukka Svahn
 * @license GNU GPLv2
 * @link    https://github.com/gocom/rah_backup_dropbox
 *
 * Copyright (C) 2013 Jukka Svahn http://rahforum.biz
 * Licensed under GNU General Public License version 2
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

	new rah_backup_dropbox();

class rah_backup_dropbox {
	
	/**
	 * @var string User's consumer key
	 */
	
	protected $key;
	
	/**
	 * @var string User's consumer secret
	 */
	
	protected $secret;
	
	/**
	 * @var string Authencation string
	 */
	
	protected $token;
	
	/**
	 * @var string Callback endpoint URL
	 */
	
	protected $callback_uri;
	
	/**
	 * @var obj Storage/session handler
	 */
	
	protected $storage;
	
	/**
	 * @var obj OAuth
	 */
	
	protected $oauth;
	
	/**
	 * @var obj Dropbox API
	 */
	
	protected $dropbox;
	
	/**
	 * Connected
	 */
	
	protected $connected = false;
	
	/**
	 * Installer
	 * @param string $event Admin-side event.
	 * @param string $step Admin-side, plugin-lifecycle step.
	 */

	static public function install($event='', $step='') {
		
		global $prefs;
		
		if($step == 'deleted') {
			
			safe_delete(
				'txp_prefs',
				"name like 'rah\_backup\_dropbox\_%'"
			);
			
			return;
		}
		
		$position = 250;
		
		foreach(
			array(
				'key' => array('rah_backup_dropbox_key', ''),
				'secret' => array('rah_backup_dropbox_key', ''),
				'token' => array('rah_backup_dropbox_token', ''),
			) as $name => $val
		) {
			$n = 'rah_backup_dropbox_'.$name;
			
			if(!isset($prefs[$n])) {
				set_pref($n, $val[1], 'rah_bckp_db', PREF_ADVANCED, $val[0], $position);
			}
			
			$position++;
		}
	}

	/**
	 * Constructor
	 */

	public function __construct() {
		add_privs('plugin_prefs.rah_backup_dropbox', '1,2');
		add_privs('prefs.rah_bckp_db', '1');
		register_callback(array($this, 'sync'), 'rah_backup.created');
		register_callback(array($this, 'sync'), 'rah_backup.deleted');
		register_callback(array($this, 'authentication'), 'textpattern');
		
		if(txpinterface == 'admin') {
			register_callback(array($this, 'unlink_account'), 'prefs');
			register_callback(array(__CLASS__, 'prefs'), 'plugin_prefs.rah_backup_dropbox');
			register_callback(array(__CLASS__, 'install'), 'plugin_lifecycle.rah_backup_dropbox');
			register_callback(array($this, 'requirements'), 'rah_backup', '', 1);
		}
		
		$this->callback_uri = hu.'?rah_backup_dropbox_oauth=accesstoken';
		
		foreach(array('key', 'secret', 'token') as $name) {
			$this->$name = get_pref('rah_backup_dropbox_'.$name);
		}
	}
	
	/**
	 * Requirements check
	 */
	
	public function requirements() {
		
		if(!function_exists('curl_init')) {
			rah_backup::get()->announce(gTxt('rah_backup_dropbox_curl_missing'), 'warning');
			return;
		}
		
		if(version_compare(PHP_VERSION, '5.3.1') < 0) {
			rah_backup::get()->announce(gTxt('rah_backup_dropbox_unsupported_php', array('{version}' => PHP_VERSION)), 'warning');
			return;
		}
		
		if(!$this->token) {
			rah_backup::get()->announce(gTxt('rah_backup_dropbox_link_account'), 'information');
		}
	}
	
	/**
	 * Unlinks account
	 */
	
	public function unlink_account() {
		global $prefs;
		
		if(!gps('rah_backup_dropbox_unlink') || !has_privs('prefs')) {
			return;
		}
	
		foreach(array('key', 'secret', 'token') as $name) {
			$name = 'rah_backup_dropbox_'.$name;
			set_pref($name, '');
			$prefs[$name] = '';
			$this->$name = '';
		}
	}

	/**
	 * Authentication handler
	 */
	
	public function authentication() {
		
		$auth = (string) gps('rah_backup_dropbox_oauth');
		$method = 'auth_'.$auth;
		
		if(!$auth || $this->token !$this->key || !$this->secret || !method_exists($this, $method)) {
			return;
		}
		
		$this->$method();
	}
	
	/**
	 * Redirects user to web endpoint
	 */
	
	protected function auth_authorize() {
		if(!$this->connect()) {
			die(gTxt('rah_backup_dropbox_connection_error'));
		}
	}
	
	/**
	 * Gets token and writes it to DB
	 */
	
	protected function auth_accesstoken() {
		
		if(!$this->connect()) {
			die(gTxt('rah_backup_dropbox_connection_error'));
		}
			
		$token = $this->storage->get('access_token');
		
		if(!$token) {
			exit(gTxt('rah_backup_dropbox_token_error'));
		}
		
		set_pref('rah_backup_dropbox_token', json_encode($token), 'rah_bckp_db', 2, '', 0);
		exit(gTxt('rah_backup_dropbox_authenticated'));
	}

	/**
	 * Connect to Dropbox
	 * @return bool
	 */
	
	public function connect() {
		
		if(!$this->key || !$this->secret) {
			return false;
		}
		
		if($this->connected) {
			return true;
		}
		
		try {
			$this->storage = new \Dropbox\OAuth\Storage\Session();
		
			if($this->token) {
				$this->storage->set(json_decode($this->token), 'access_token');
			}
		
			$this->oauth = new \Dropbox\OAuth\Consumer\Curl($this->key, $this->secret, $this->storage, $this->callback_uri);
		
			if($this->token) {
				$this->dropbox = new \Dropbox\API($this->oauth);
			}
		}
		
		catch(exception $e) {
			rah_backup::get()->announce(array('Dropbox SDK said: '.$e->getMessage(), E_ERROR));
			return false;
		}
		
		$this->connected = true;
		return true;
	}
	
	/**
	 * Syncs backups
	 * @param string $event
	 * @param array $files
	 */
	
	public function sync($event, $files) {
	
		if(!$this->token || !$this->connect()) {
			return;
		}
		
		try {
			foreach(rah_backup::get()->deleted as $name => $path) {
				$this->dropbox->delete($name);
			}
			
			foreach(rah_backup::get()->created as $name => $path) {
				$this->dropbox->putFile($path, $name);
			}
		}
		
		catch(exception $e) {
			rah_backup::get()->announce(array('Dropbox SDK said: '.$e->getMessage(), E_ERROR));
		}
	}
	
	/**
	 * Redirect to the admin-side interface
	 */
	
	static public function prefs() {
		header('Location: ?event=prefs&step=advanced_prefs#prefs-rah_backup_dropbox_api_key');
		
		echo 
			'<p>'.n.
			'	<a href="?event=prefs&amp;step=advanced_prefs#prefs-rah_backup_dropbox_api_key">'.gTxt('continue').'</a>'.n.
			'</p>';
	}
}

	/**
	 * Options controller for token pref
	 * @return string HTML
	 */

	function rah_backup_dropbox_token() {
		
		if( 
			!get_pref('rah_backup_dropbox_key', '', true) || 
			!get_pref('rah_backup_dropbox_secret', '', true)
		) {
			return 
				'<span class="navlink-disabled">'.gTxt('rah_backup_dropbox_authorize').'</span>'.n.
				'<span class="information">'.gTxt('rah_backup_dropbox_set_keys', array('{save}' => gTxt('save'))).'</span>';
		}
		
		if(get_pref('rah_backup_dropbox_token')) {
			return '<a class="navlink" href="?event=prefs'.a.'step=advanced_prefs'.a.'rah_backup_dropbox_unlink=1">'.gTxt('rah_backup_dropbox_unlink').'</a>';
		}
		
		return 
			'<a class="navlink" href="'.hu.'?rah_backup_dropbox_oauth=authorize">'.gTxt('rah_backup_dropbox_authorize').'</a>'.n.
			'<a class="navlink" href="?event=prefs'.a.'step=advanced_prefs'.a.'rah_backup_dropbox_unlink=1">'.gTxt('rah_backup_dropbox_reset').'</a>';
	}
	
	/**
	 * Options controller for app key
	 * @param string $name Field name
	 * @param string $value Current value
	 * @return string HTML
	 */

	function rah_backup_dropbox_key($name, $value) {
		
		if($value !== '') {
			$value = str_pad('', strlen($value), '*');
			return fInput('text', $name.'_null', $value, '', '', '', '', '', '', true);
		}
		
		return text_input($name, $value);
	}

?>