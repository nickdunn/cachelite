<?php
	
	Class extension_cachelite extends Extension
	{
		protected $_frontend;
		protected $_cacheLite = null;
		protected $_lifetime = null;
		protected $_url = null;
		private $_sections = array();
		private $_entries = array();
		
		function __construct($args) {
			require_once('lib/class.cachelite.php');
			require_once(CORE . '/class.frontend.php');
		
			$this->_Parent =& $args['parent'];
			$this->_frontend = Frontend::instance();
			
			$this->_lifetime = $this->_get_lifetime();
			
			$this->_cacheLite = new Cache_Lite(array(
				'cacheDir' => CACHE . '/',
				'lifeTime' => $this->_lifetime
			));
			
			$this->_url = $_SERVER['REQUEST_URI'];
		}
		
		/*-------------------------------------------------------------------------
		Extension definition
		-------------------------------------------------------------------------*/
		public function about()
		{
			return array('name' => 'CacheLite',
						 'version' => '1.0.2',
						 'release-date' => '2009-11-24',
						 'author' => array('name' => 'Max Wheeler',
											 'website' => 'http://makenosound.com/',
											 'email' => 'max@makenosound.com'),
 						 'description' => 'Allows for simple frontend caching using the CacheLite library.'
				 		);
		}
		
		public function uninstall()
		{
			# Remove preferences
			$this->_Parent->Configuration->remove('cachelite');
			$this->_Parent->saveConfig();
			
			# Remove file
			if(file_exists(MANIFEST . '/cachelite-excluded-pages')) unlink(MANIFEST . '/cachelite-excluded-pages');
			
			// remove extension table
			Administration::instance()->Database->query("DROP TABLE `tbl_cachelite_references`");
		}
		
		public function install() {
			// create extension table
			Administration::instance()->Database->query("
				CREATE TABLE `tbl_cachelite_references` (
				  `page` varchar(255) NOT NULL default '',
				  `sections` varchar(255) default NULL,
				  `entries` varchar(255) default NULL,
				  PRIMARY KEY  (`page`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8
			");
			return true;
		}

		public function getSubscribedDelegates() {
			return array(
				array(
					'page'		=> '/frontend/',
					'delegate'	=> 'FrontendPreRenderHeaders',
					'callback'	=> 'intercept_page'
				),
				array(
					'page'		=> '/frontend/',
					'delegate'	=> 'FrontendOutputPreGenerate',
					'callback'	=> 'parse_page_data'
				),
				array(
					'page'		=> '/frontend/',
					'delegate'	=> 'FrontendOutputPostGenerate',
					'callback'	=> 'write_page_cache'
				),
				array(
					'page' => '/system/preferences/',
					'delegate' => 'AddCustomPreferenceFieldsets',
					'callback' => 'append_preferences'
				),
				array(
					'page' => '/system/preferences/',
					'delegate' => 'Save',
					'callback' => 'save_preferences'
				),
				array(
					'page'		=> '/publish/new/',
					'delegate'	=> 'EntryPostCreate',
					'callback'	=> 'entry_create'
				),				
				array(
					'page'		=> '/publish/edit/',
					'delegate'	=> 'EntryPreEdit',
					'callback'	=> 'entry_edit'
				),
				array(
					'page'		=> '/publish/',
					'delegate'	=> 'Delete',
					'callback'	=> 'entry_delete'
				),
				array(
					'page' => '/blueprints/events/new/',
					'delegate' => 'AppendEventFilter',
					'callback' => 'addFilterToEventEditor'
				),						
				array(
					'page' => '/blueprints/events/edit/',
					'delegate' => 'AppendEventFilter',
					'callback' => 'addFilterToEventEditor'
				),						
				array(
					'page' => '/frontend/',
					'delegate' => 'EventPreSaveFilter',
					'callback' => 'processEventData'
				),
			);
		}

		/*-------------------------------------------------------------------------
			Preferences
		-------------------------------------------------------------------------*/

		public function append_preferences($context) {
			
			# Add new fieldset
			$group = new XMLElement('fieldset');
			$group->setAttribute('class', 'settings');
			$group->appendChild(new XMLElement('legend', 'CacheLite'));

			# Add Site Reference field
			$label = Widget::Label('Cache Period');
			$label->appendChild(Widget::Input('settings[cachelite][lifetime]', General::Sanitize($this->_get_lifetime())));
			$group->appendChild($label);
			$group->appendChild(new XMLElement('p', 'Length of cache period in seconds.', array('class' => 'help')));
			
			$label = Widget::Label('Excluded URLs');
			$label->appendChild(Widget::Textarea('cachelite[excluded-pages]', 10, 50, $this->_get_excluded_pages()));
			$group->appendChild($label);
			$group->appendChild(new XMLElement('p', 'Add a line for each URL you want to be excluded from the cache. Add a <code>*</code> to the end of the URL for wildcard matches.', array('class' => 'help')));
			
			$label = Widget::Label();
			$input = Widget::Input('settings[cachelite][show-comments]', 'yes', 'checkbox');
			if($this->_Parent->Configuration->get('show-comments', 'cachelite') == 'yes') $input->setAttribute('checked', 'checked');
			$label->setValue($input->generate() . ' Show comments in page source?');
			$group->appendChild($label);
			
			$label = Widget::Label();
			$input = Widget::Input('settings[cachelite][backend-delegates]', 'yes', 'checkbox');
			if($this->_Parent->Configuration->get('backend-delegates', 'cachelite') == 'yes') $input->setAttribute('checked', 'checked');
			$label->setValue($input->generate() . ' Expire cache when entries are created/updated through the backend?');
			$group->appendChild($label);
			$context['wrapper']->appendChild($group);
		}
		
		public function save_preferences($context) {
			// set checkbox defaults to 'no'
			if(!isset($context['settings']['cachelite']['show-comments'])) $context['settings']['cachelite']['show-comments'] = 'no';
			if(!isset($context['settings']['cachelite']['backend-delegates'])) $context['settings']['cachelite']['backend-delegates'] = 'no';
			
			$this->_save_excluded_pages(stripslashes($_POST['cachelite']['excluded-pages']));
		}
		
		
		/*-------------------------------------------------------------------------
			Events
		-------------------------------------------------------------------------*/
		
		public function addFilterToEventEditor($context) {
			// adds filters to Filters select box on Event editor page
			$context['options'][] = array('cachelite-entry', @in_array('cachelite-entry', $context['selected']) ,'CacheLite: expire cache for pages showing this entry');
			$context['options'][] = array('cachelite-section', @in_array('cachelite-section', $context['selected']) ,'CacheLite: expire cache for pages showing content from this section');
		}
		
		public function processEventData($context) {
			// flush the cache based on entry IDs
			if(in_array('cachelite-entry', $context['event']->eParamFILTERS) && isset($_POST['cachelite']['flush-entry'])) {
				if (is_array($_POST['id'])) {
					foreach($_POST['id'] as $id) {
						$this->clear_pages_by_reference($id, 'entry');
					}
				} elseif (isset($_POST['id'])) {
					$this->clear_pages_by_reference($_POST['id'], 'entry');
				}
			}
			
			// flush cache based on the Section ID of the section this Event accesses
			if(in_array('cachelite-section', $context['event']->eParamFILTERS) && isset($_POST['cachelite']['flush-section'])) {
				$this->clear_pages_by_reference($context['event']->getSource(), 'section');
			}
			
		}

		/*-------------------------------------------------------------------------
			Caching
		-------------------------------------------------------------------------*/
		
		public function intercept_page() {
			require_once(CORE . '/class.frontend.php');
		
			if($this->_in_excluded_pages()) return;
			$logged_in = $this->_frontend->isLoggedIn();
			
			# Check for headers() accessor method added in 2.0.6
			$page = $this->_frontend->Page();
			$headers = $page->headers();
			
			if ($logged_in && $page->_param['url-flush'] == 'site')
			{
				$this->_cacheLite->clean();
			}
			else if ($logged_in && array_key_exists('url-flush', $page->_param))
			{
				$this->_cacheLite->remove($this->_url);
			}
			//else if (!$logged_in && $output = $this->_cacheLite->get($this->_url))
			else if ($output = $this->_cacheLite->get($this->_url))
			{
				# Add comment
				if ($this->_get_comment_pref() == 'yes') $output .= "<!-- Cache served: ". $this->_cacheLite->_fileName	." -->";
				
				# Add some cache specific headers
				$modified = $this->_cacheLite->lastModified();
				$maxage = $modified - time() + $this->_lifetime;

				header("Expires: " . gmdate("D, d M Y H:i:s", $modified + $this->_lifetime) . " GMT");
				header("Cache-Control: max-age=" . $maxage . ", must-revalidate");
				header("Last-Modified: " . gmdate('D, d M Y H:i:s', $modified) . ' GMT');
				header(sprintf('Content-Length: %d', strlen($output)));
			
				# Ensure the original headers are served out
				foreach ($headers as $header) {
					header($header);
				}
				print $output;
				exit();
			}
		}
		
		public function write_page_cache(&$output) {
			if($this->_in_excluded_pages()) return;
			$logged_in = $this->_frontend->isLoggedIn();
			
			if ( ! $logged_in)
			//if (!isset($_GET['debug']) && !isset($_GET['profile']))
			{
				$render = $output['output'];
				
				// rebuild entry/section reference list for this page
				$this->_delete_page_references($this->_url);
				$this->_save_page_references($this->_url, $this->_sections, $this->_entries);
				
				if (!$this->_cacheLite->get($this->_url)) {
					$this->_cacheLite->save($render);
				}
				
				# Add comment
				if ($this->_get_comment_pref() == 'yes') $render .= "<!-- Cache generated: ". $this->_cacheLite->_fileName	." -->";
				
				header("Expires: " . gmdate("D, d M Y H:i:s", $this->_lifetime) . " GMT");
				header("Cache-Control: max-age=" . $this->_lifetime . ", must-revalidate");
				header("Last-Modified: " . gmdate('D, d M Y H:i:s', time()) . ' GMT');
				header(sprintf('Content-Length: %d', strlen($render)));
				
				print $render;
				exit();
			}
		}
		
		# Parse any Event or Section elements from the page XML
		public function parse_page_data($context) {
			$xml = DomDocument::loadXML($context['xml']);
			$xpath = new DOMXPath($xml);
			
			$sections_xpath = $xpath->query('//section[@id and @handle]');
			$sections = array();
			foreach($sections_xpath as $section) {
				$sections[] = $section->getAttribute('id');
			}
			
			$entries_xpath = $xpath->query('//entry[@id]');
			$entries = array();
			foreach($entries_xpath as $entry) {
				$entries[] = $entry->getAttribute('id');
			}
			
			$this->_sections = array_unique($sections);
			$this->_entries = array_unique($entries);
			
		}
		
		public function entry_create($context) {
			if ($this->_Parent->Configuration->get('backend-delegates', 'cachelite') == 'no') return;
			// flush by Section ID
			$this->clear_pages_by_reference($context['section']->get('id'), 'section');
		}
		
		public function entry_edit($context) {
			if ($this->_Parent->Configuration->get('backend-delegates', 'cachelite') == 'no') return;
			// flush by Entry ID
			$this->clear_pages_by_reference($context['entry']->get('id'), 'entry');
		}
		
		public function entry_delete($context) {
			if ($this->_Parent->Configuration->get('backend-delegates', 'cachelite') == 'no') return;
			// flush by Entry ID
			$this->clear_pages_by_reference($context['entry_id'], 'entry');
		}
		
		public function clear_pages_by_reference($id, $type) {
			// get a list of pages matching this entry/section ID
			$pages = $this->_get_pages_by_content($id, $type);
			// flush the cache for each
			foreach($pages as $page) {
				$url = $page['page'];
				$this->_cacheLite->remove($url);
				$this->_delete_page_references($url);
			}

		}
		
		/*-------------------------------------------------------------------------
			Helpers
		-------------------------------------------------------------------------*/

		private function _get_lifetime() {
			$default_lifetime = 86400;
			$val = $this->_Parent->Configuration->get('lifetime', 'cachelite');
			return (isset($val)) ? $val : $default_lifetime;
		}
		
		private function _get_comment_pref() {
			return $this->_Parent->Configuration->get('show-comments', 'cachelite');
		}
		
		private function _get_excluded_pages() {
			return @file_get_contents(MANIFEST . '/cachelite-excluded-pages');
		}
		
		private function _save_excluded_pages($string){
			return @file_put_contents(MANIFEST . '/cachelite-excluded-pages', $string);
		}
		
		private function _in_excluded_pages() {
			$segments = explode('/', $this->_url);
			$domain = explode('/', DOMAIN);
			foreach($segments as $key => $segment) {
				if(in_array($segment, $domain) || empty($segment)) unset($segments[$key]);
			}
			$path = "/" . implode("/", $segments);
			
			$rules = file(MANIFEST . '/cachelite-excluded-pages', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
			$rules = array_map('trim', $rules);
			if(count($rules) > 0) {
				foreach($rules as $r) {
					$r = str_replace('http://', NULL, $r);
					$r = str_replace(DOMAIN . '/', NULL, $r);
					$r = "/" . trim($r, "/"); # Make sure we're matching `/url/blah` not `url/blah					
					if($r == '*') {
						return true;
					}
					elseif(substr($r, -1) == '*' && strncasecmp($path, $r, strlen($r) - 1) == 0) {
						return true;
					}
					elseif(strcasecmp($r, $path) == 0) {
						return true;
					}
				}
			}
			return false;
		}
		
		/*-------------------------------------------------------------------------
			Database Helpers
		-------------------------------------------------------------------------*/
		
		private function _get_pages_by_content($id, $type) {
			return $this->_frontend->Database->fetch(
				sprintf(
					"SELECT page FROM tbl_cachelite_references WHERE %s LIKE '%%|%s|%%'",
					(($type=='entry') ? 'entries' : 'sections'),
					$id
				)
			);
		}
		
		private function _delete_page_references($url) {
			$this->_frontend->Database->query(
				sprintf(
					"DELETE FROM tbl_cachelite_references WHERE page='%s'",
					$url
				)
			);
		}
		
		protected function _save_page_references($url, $sections, $entries) {
			$this->_frontend->Database->query(
				sprintf(
					"INSERT INTO tbl_cachelite_references (page, sections, entries) VALUES ('%s','%s','%s')",
					$url,
					'|' . implode('|', $sections) . '|',
					'|' . implode('|', $entries) . '|'
				)
			);
		}
		
	}