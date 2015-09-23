<?php
/*
Plugin Name: wp2affiliate
Plugin URI: http://www.wp2affiliate.com/
Description: Automatische Umwandlung von normalen Links zu Affiliate-Deeplinks, ohne den Content dabei zu ver&auml;ndern. Die Links werden au&szlig;erdem per Link-Cloaking / URL-Masking versteckt, so dass sie nicht als Affiliate-Links erkennbar sind. Unterst&uuml;tz werden Affiliate-Programme von Zanox und Affili.net, sowie Tradedoubler und Amazon. Kein Zwischenh&auml;ndler: Die Provisionierung erfolgt &uuml;ber deine eigene Partner-ID des jeweiligen Netzwerks. Das Plugin ist kostenlos und erfordert keine Anmeldung, daf&uuml;r werden maximal 5% der Klicks automatisch an wp2affiliate abgegeben (nur bei bestimmten Affiliate-Programmen, siehe Plugin-Homepage).  
Version: 0.01.04
Author: wp2affiliate
Author URI: http://www.wp2affiliate.com
Text Domain: wp2affiliate
*/


// Wenn wir auf PHP 4 oder niedriger sind, müssen wir uns die Funktion file_put_contents erstellen (arbeitet nur mit Zahlen und Strings)	
if (!function_exists('file_put_contents')) {
    function file_put_contents($filename, $data) {
        $f = @fopen($filename, 'w');
        if (!$f) {
            return false;
        } else {
            $bytes = fwrite($f, $data);
            fclose($f);
            return $bytes;
        }
    }
}

if (!class_exists('Wp2Affiliate')) {

class Wp2Affiliate {
	var $version = '0.01.04';
	
	var $plugin_slug = 'wp-2-affiliate';
			
	var $options_name='wp2affiliate_options';
	var $options;
  
	var $link_name_param = 'wp2a-name'; //Query-Variable für Link-Namen
	
	var	$link_pattern =  '#<a\s+(?P<attributes>[^>]+)>(?P<text>.*?)</a>#si';
	var $attribute_pattern = '#(?P<name>\w+)\s*=\s*([\"\'])(?P<value>.*?)\2#s'; 

// Holger: Die Netzwerkliste muss hier und zusätzlich in aff_links.js gepflegt werden (für Dropdown-Box) 
	var	$netzwerkliste = array(
  'zanox', 
  'affilinet',
  'tradedoubler',
  'amazon',
  'digistore24'
  ); 

// Hiermit wird geprüft, ob ein Link bereits ein bekannter Affiliate-Link ist. Diese Prüfung muss beim Redirect und bei der Anzeige des Affstatus erfolgen. Amazon und Digistore24 müssen zusätzlich extra geprüft werden (an 2 Stellen).
// Falls Netzwerke mehrere URLs haben, dürfen sie mehrfach vorkommen. Pflege nur hier nötig. Das Netzwerk muss kein unterstütztes sein.
  var	$bekannteaffs = array( // Todo: Das müssen noch mehr werden!
  'http://ad.zanox.com/ppc/',
  'http://www.zanox-affiliate.de/ppc/',
  'http://partners.webmasterplan.com/click',
  'http://clkde.tradedoubler.com/click'
  );


	function Wp2Affiliate(){
		
		// Der eigene Host sollte standardmäßig eine Ausnahme sein
		$parts = @parse_url(get_option('siteurl'));
		$default_blacklist = array();
		if( $parts && isset($parts['host']) ){
			$default_blacklist[] = $parts['host'];
		}
		
		// Defaults festlegen
		$this->defaults = array(
			'blacklist' => $default_blacklist,// Im link_mode everything werden die Links aus der blacklist nicht gecloaked (z. B. eigene Domain)
			'whitelist' => array(),				// Im selective mode werden nur Links gecloaked, die in dieser whitelist stehen 

			'filter_mode' => 'everything',		// Wo soll gekloaked werden: ('content') oder 'everything'?
			'filter_feed' => true,  			// Sollen Links im RSS feed gekloaked werden? (kompatibel mit beiden filter_mode)
			
			'link_mode' => 'everything',		// Was soll gecloake werden: 'selective' = nur ausgewählte Links, 
												              // 'everything' = alle Links
                                      // 'filter_affmatch' = nur Affiliate-Links aus der Aff-DB

			'testing' => false,		// Wenn true, werden Redirects über die Testumgebung geschickt

			'prefix' => 'go',			// Zum Beispiel http://www.mydomain.com/go/zum_angebot/
												 
			'redirect_type' => '302redirect',	
                        // Mögliche defaults:
                        //'301redirect' - 301 redirect (Permanent)
												//'302redirect' - 302 redirect (Found)
												//'307redirect' - 307 redirect (Temporary)
												//'meta'		- doppelter META refresh
			
			'tweak_nofollow' => false,			// rel="nofollow" hinzufügen
			'tweak_new_window' => true,		// target="_blank" hinzufügen
      'tweak_google_analytics' => false,	// Wenn true, tracken wir die Klicks mit Google Analytics
      'tweak_class' => false,	// Wenn true, fügen wir jedem Link eine css-class hinzu
      
      'custom_class' => '',
												
			'links_per_page' => 40,             // Wie viele Links oder Afflinks pro Seite sollen angezeigt werden
				     			
			'max_db_batch'	=> 100,				// Wie viele Links sollen auf einmal geladen werden, wenn wir nach dem Namen suchen
						
			'bots' => array('bot','ia_archive','slurp','crawl','spider'), // Für diese UserAgents werden keine Klicks gezählt

      // Defaults für Netzwerke in den Options. Muss nicht sein, ist aber sauberer.      
      'affilinet' => '',
      'zanox' => '',
      'tradedoubler' => '',
      'amazon' => '',
      'digistore24' => '',
 
		);
		
		$this->load_options();
		
		//Erste Installation oder Update (ggf. mit Update der Aff-Links)
		add_action('init', array(&$this, 'maybe_install'));
		register_activation_hook(__FILE__, array(&$this, 'install'));
				
		add_action('admin_menu', array(&$this,'admin_menu'));
		//Init. i18n stuff. Runs after the redirect handler (hook_init) for slightly better performance.
		add_action('init', array(&$this,'load_language'), 11); 
		
        // Filter um die Links zu finden und zu cloaken (nicht auf Admin-Pages)
        if ( !is_admin() ){
			if ( $this->options['filter_mode'] == 'content' ){
				add_filter('the_content', array(&$this,'process_content'), 50);
			} else if ( $this->options['filter_mode'] == 'everything' ){
				add_action( 'template_redirect', array(&$this,'init_buffer'), -2000 );
			}
		}
		
    //Holger: Hier wird eine Theme-Filter bereitgestellt, um den cloaker direkt aus dem Theme auf einen HTMl-Code anzuwenden
     add_filter('wp2a_cloak_link_filter', array(&$this,'process_content'), 50);    
  
    
		// handler für den redirect
		add_action( 'init', array(&$this, 'hook_init') );
		
		// AJAX handler registrieren
		add_action( 'wp_ajax_wp2ap_update_link', array(&$this,'ajax_update_link') );
		add_action( 'wp_ajax_wp2ap_update_afflink', array(&$this,'ajax_update_afflink') );
		
		
		// gibt den JavaScript code fürs GA-Tracking aus
		if ( $this->options['tweak_google_analytics'] ){
			add_action( 'wp_head', array(&$this, 'print_ga_helper') );
		}
		
		// Fügt das WP2A widget zum post/page editor hinzu
		add_action('admin_init', array(&$this, 'register_metabox'));
		
		// fügt den filter hinzu, der <!--nowp2a-page--> ausgibt, wenn das cloaking für einen post/page deaktiviert wurde
        if ( !is_admin() ){
			add_filter('the_content', array(&$this, 'maybe_nocloak_page'), 0);
		}
	}
 
	function load_options(){
	    $this->options = get_option($this->options_name);
	    if( !is_array( $this->options ) ){ // Wenn es noch keine options gibt, nehmen wir die defaults
	        $this->options = $this->defaults;
	 }
  }
	
	function save_options(){
		update_option($this->options_name,$this->options);
	}
	
	function process_content( $html ){
		global $wpdb;
		
		//Sanity check
		if ( !isset($wpdb) ){
			return $html;
		}		
		
		// Ein bisschen spezielle Logik, wenn wir im RSS feed sind 
		if ( is_feed() && !$this->options['filter_feed'] ) return $html;
        
        // Zur Diagnose kann es sinnvoll sein, wenn wir wissen ob die Seite durch WP2A verarbeitet wurde
        $looks_like_html = stripos($html, '<html') !== false;
        if ( $looks_like_html ){
        	$html .= sprintf('<!-- WP2A %s [S%d] [H%d] -->', date('Y-m-d H:i:s'), strlen($html), stripos($html, '<html'));
        }
        
        //Debug: Prüfen ob irgendwelche Links auf der Seite vorhanden sind
        if ( stripos($html, '<a ') !== false ) {
        	if (!headers_sent()) header('X-WP2A-Checkpoint4: has_links');
        }
        
		// Das cloaking kann via <!--nowp2a-page--> für eine Seite deaktiviert werden
		if ( preg_match('@<!--\s*nowp2a[-_]page\s*-->@i', $html, $matches) ) {
			$html = str_replace($matches[0], '', $html);
			return $html.'<!--ncp-->';
		}
		
		// finde alle Links
		if ( !preg_match_all($this->link_pattern, $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ){ 
			return $html;
		}
		$html .= sprintf('<!--A%d-->', count($matches));
		
		// rausfinden, welche Links gecloakt werden sollen und deren Infos für später speichern 
		$links = array();
		foreach ($matches as $match){
			
			// Link-Attribute extrahieren
			if ( !preg_match_all( $this->attribute_pattern, $match['attributes'][0], $attribute_data, PREG_SET_ORDER ) ){
				continue;
			}
				
			// Link-Attribute in ein Array speichern: name->value 
			$attributes = array();
			foreach($attribute_data as $attr){
				$attributes[ strtolower($attr['name']) ] = $this->unhtmlentities( $attr['value'] );
			}
			
			// ungültige Links ohne href überspringen wir
			if ( empty( $attributes['href'] ) ){
				continue;
			} else {
				$attributes['href'] = trim($attributes['href']); // leerzeichen entfernen wir vorher, falls vorhanden
				$attributes['href'] = str_replace(array("\r", "\n", "\t"), '', $attributes['href']); // Zeilenumbrüche sind ungültig in URLs, die entfernen wir
			}
			
			$link = array(
				'original' => $match[0][0], 
				'offset' => $match[0][1], 
				'text' => $match['text'][0],
				'attributes' => $attributes, 
			);
			
			if ( $this->should_cloak( $link ) ){
				$links[] = $link;
			}
		}
		
		// daten für diese Links aus der DB holen
		$loaded_links = $this->get_link_data( $links );

		$html .= sprintf('<!--L%d-->', count($loaded_links));
		
		$offset = 0;
		foreach ($links as $key => $link){
			$info = array();
			
			// cloaked-URL für jeden Link herausfinden
			$cloaked_url = $link['attributes']['href'];
				
				if ( isset( $loaded_links[ $link['attributes']['href'] ] ) ){
					$info = $loaded_links[ $link['attributes']['href'] ];
					// Hat der Link einen Namen?
					if ( $info['name'] ){
						// Ja, hat er
						$cloaked_url = $this->make_cloaked_url( $info['name'] );
					} else {
						// Nein, hat er nicht. Dann nutzen wir den Anker-Text
						$link_text = $this->escapize($link['text']);
            
						$cloaked_url = $this->make_cloaked_url( $link_text, $info['id'] );
					}
				}
					
			// ## Tweaks anwenden ##
      
			// target="_blank" hinzufügen
			if ($this->options['tweak_new_window']){
				$link['attributes']['target'] = '_blank';
			}
			
			// nofolow hinzufügen, falls option gesetzt und noch nicht vorhanden
			if ($this->options['tweak_nofollow']){
				
				if ( isset( $link['attributes']['rel'] ) ){
					if ( stripos( $link['attributes']['rel'], 'nofollow' ) === false ){
						$link['attributes']['rel'] .= ' nofollow';
					}
				} else {
					$link['attributes']['rel'] = 'nofollow';
				}
			}
			
			
			// Links mit Google Analytics tracken
			if ($this->options['tweak_google_analytics']){
				
				// Protokol (zB http oder https) von der URL entfernen
				$tracker = preg_replace('#^([a-z]+)://#i', '', $link['attributes']['href']);

				//Obscure the tracker
				$tracker = str_rot13($tracker);

				//Escape it for use in JS strings
				$tracker = esc_js($tracker); //Note: This also escapes & as &amp;

				// onclick handler hinzufügen oder ergänzen
				if ( isset( $link['attributes']['onclick'] ) ){
					$link['attributes']['onclick'] .= "; wp2aTrackPageview('$tracker');";
				} else {
					$link['attributes']['onclick'] = "javascript:wp2aTrackPageview('$tracker');";
				}
			}


			// Custom CSS-Class hinzufügen, falls aktiv
      if ( $this->options['tweak_class'] ){
  			if ( isset( $link['attributes']['class'] ) ){
  				$link['attributes']['class'] .= " ".$this->options['custom_class'];
  			} else {
  				$link['attributes']['class'] = $this->options['custom_class'];
  			}
      }

			
			// Kommentare <!--wp2a--> und <!--nowp2a--> vom Link-Text entfernen, falls vorhanden
			$text = preg_replace( '/<!--\s*(no)?wp2a\s*-->/i', '', $link['text'] );
			
			// original URL mit den cloaked URL ersetzen
			$link['attributes']['href'] = $cloaked_url;
			
			// neuen link tag zusammenbauen
			$new_html = '<a';
			foreach ( $link['attributes'] as $name => $value ){
				$new_html .= sprintf(' %s="%s"', $name, esc_attr($value));
			}
			$new_html .= '>' . $text . '</a>';
			
			// original Link mit den cloaked Link ersetzen
			$html = substr_replace($html, $new_html, $link['offset'] + $offset, strlen($link['original']));
			//Update the replacement offset
			$offset += ( strlen($new_html) - strlen($link['original']) );
			
			$links[$key]['cloaked_url'] = $cloaked_url;			
		}
        
		return $html;
	}
  
	
	/**
	 * prüft, ob ein Link gecloaked werden soll oder nicht
	 * 
	 *      
	 * @param array $link
	 * @return bool
	 */
	function should_cloak( $link ){
		// prüfen, ob es eine gültige URL ist (mit Protokoll und TLD) 
		$parts = @parse_url( $link['attributes']['href'] );
		if ( !$parts || !isset($parts['scheme']) || !isset($parts['host']) ) return false;
		
		// Nur http and https Links werden gecloaked (z. B. kein ftp)
		if ( ($parts['scheme'] != 'http') && ($parts['scheme'] != 'https') ) return false; 		

    // Holger: Wenn nur Links gecloaked werden sollen, die auf der Partnerprogramm-Liste stehen, müssen wir das zuerst prüfen. Ist der Link nicht auf der Liste, geben wir direkt false zurück und sparen uns die anderen Prüfungen
    if ( $this->options['link_mode'] == 'filter_affmatch' ){
     		global $wpdb; /** @var wpdb $wpdb */
        $checkmatch = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wp2ap_aff_links WHERE '".$link['attributes']['href']."' LIKE CONCAT('%', url_match, '%')" );
        if (!$checkmatch){
        return false;
        }
     }
		
		if ( $this->options['link_mode'] == 'selective' ){
			// ## im selective Mode ##
			
			// zuerst prüfen wir, ob der Linktext "<!--wp2a-->" enthält
			if ( preg_match('/<!--\s*wp2a\s*-->/i', $link['text']) ){
				return true;
			} 
			// dann prüfen wir, ob die URL in der whitelist steht
			if ( $this->is_whitelisted($link['attributes']['href']) ){
				return true;
			}
			
			// Wenn beides nicht gepasst hat, wird der Link nicht gecloaked
			return false;
		} else {
			// ## im "Cloak everything" Mode ##
			
			// prüfen wir, ob der Linktext "<!--nowp2a-->" enthält 
			if ( preg_match('/<!--\s*nowp2a\s*-->/i', $link['text']) ){
				return false;
			} 
			// prüfen, ob die URL in der blacklist steht
			if ( $this->is_blacklisted($link['attributes']['href']) ) {
				return false;
			}
			
			return true;
		}
	}
	
	/**
	 * Prüft ob die URL zu einem Eintrag in der blacklist passt.
	 *
	 *    
	 * @param string $url
	 * @return bool
	 */
	function is_blacklisted($url){
		if (isset($this->options['blacklist'])) {
			foreach($this->options['blacklist'] as $keyword){
				if (stripos($url, $keyword) !== false){
					return true;
				}
			}
		}
		return false;
	}
	
	/**
	 * prüft ob die URL zu einem Eintrag in der  whitelist passt
	 * 
	 * @param string $url
	 * @return bool
	 */
	function is_whitelisted($url){
		if( isset($this->options['whitelist']) ){
			foreach($this->options['whitelist'] as $keyword){
				if (stripos($url, $keyword) !== false){
					return true;
				}
			}
		}
		return false;
	}
	
	function make_cloaked_url ( $name, $id = null){
		global $wp_rewrite; /* @var WP_Rewrite $wp_rewrite */
		$is_named_link = !empty($name) && empty($id);
		
		$url = untrailingslashit( get_option('home') );
		
		if ( $wp_rewrite->using_permalinks() ) {
			
			$token1 = $token2 = null;
			
			if ( !empty($name) ){
				// Wenn ein Name vorhanden ist, ist der first token der name und der zweite ist die ID oder code
				$token1 = $name;
				$token2 = $id;
			} else {
				// Wenn kein Name vorhanden ist, ist die ID der erste und einzige token
				if ( !empty( $id ) ){
					$token1 = $id;
				} else {
					// eine eigentlich unmögliche Situation ($name oder $id müssen vorhanden sein)
					return false;
				}
			}
			
			// cloaked URL passend zu den permalink settings zusammenbauen
			$url .= '/';
			if ( $wp_rewrite->using_index_permalinks() ){
				$url .= 'index.php/';
			}
			
			// prefix hinzufügen
				$url .= $this->options['prefix'] . '/';
			
			$url .= urlencode($token1) . '/';
			if ( !is_null($token2) ){
				$url .= urlencode($token2) . '/';
			}
			
		} else {
			// wenn wir keine permalinks benutzen, müssen wir die Parameter im query string übermitteln
			if ( !empty($name) ){
				
				$url .= '/?' . $this->options['prefix'] . '=' . urlencode($name);
				if ( !is_null($id) ){
					$url .= '&id=' . urlencode($id);
				}
				
			} else {
				
				if ( !is_null($id) ){
					$url .= '/?' . $this->options['prefix'] . '=' . urlencode($id);
				} else {
					return false;
				}
			}
			
			
		}
		
		return $url;
	}
	
	function init_buffer(){
		if ( is_admin() ) return;
		// Start output buffering. Note: We won't need to stop it explicitly - 
		// WordPress does that automatically in the "shutdown" action
		ob_start( array(&$this, 'buffer_callback') );
	}
	
	function buffer_callback( $buffer ){
		// Cloak links in the buffered HTML. 
		return $this->process_content( $buffer );
	}
	
  /**
   * Wp2Affiliate::get_link_data()
   *
   *   
   * Gibt den Datenbank-Eintrag für mehrere Links zurück, basierend auf einer Liste von Ziel-URLs.
   * URLs, die nicht in der DB vorhanden sind, werden zunächst als neue Links hinzugefügt und anschließend zurückgegeben.
   *
   * @param array $links An array containing one or more URLs
   * @return array Asociative array in the form link_url -> link_data 
   */
	function get_link_data ( $links ){
		global $wpdb;
		if (!is_array($links) || ( count($links) < 1 )) return array();
		
		// URL info aus der DB laden
		$loaded_links = array();
		$q = "
			SELECT * 
			FROM 
				{$wpdb->prefix}wp2ap_cloaked_links AS links
			WHERE 
				url_hash IN (%s)
			ORDER BY name DESC";
			
		$i = 0;
		while ( $i < count($links) ){
			
			//Load records in batches (otherwise we might exceed the query size limit ) 
			$hashes = array_slice( $links, $i, $this->options['max_db_batch'] );
			$i += count($hashes); 
			
			// MD5 hash für jede URL berechnen
			$hashes = array_map( 
				create_function(
					'$a',
					'return md5( $a[\'attributes\'][\'href\'] );'
				),
				$hashes 
			);
			$s = "'" . implode("','", $hashes) . "'";
			
			// hole die Links
			$batch = $wpdb->get_results( sprintf($q, $s), ARRAY_A);
			
			if (is_array($batch)){
				// speichere sie in der loaded_links hashtable, indexed by URL
				foreach($batch as $record){
					$record['from_db'] = true;
					if ( !isset($loaded_links[ $record['url'] ]) ){
						$loaded_links[ $record['url'] ] = $record;
					}
				}
			}				
		}
		
		// DB-records für Links erstellen, die noch nicht vorhanden sind (neue Links) 
		foreach( $links as $link ){
			if ( isset( $loaded_links[ $link['attributes']['href'] ] ) ) continue;
			
            $info = $this->save_link( $link['attributes']['href'], null );
			if ( $info ){
				$loaded_links[ $info['url'] ] = $info;
			}
		}
		
		return $loaded_links;
	}
	
  /**
   * Wp2Affiliate::save_link()
   * Fügt neue cloaked links zur DB hinzu
   *
   *   
   * @param string $url A fully qualified URL
   * @param string $name The user-defined link name (can be null if auto-saving)
   *
   * @return mixed An associative array containing the ID and other properties of the new link, or FALSE on failure 
   */
	function save_link ( $url, $name ){
		global $wpdb; /* @var wpdb $wpdb */
		
		if ( strlen($name) > 100 ) $name = substr($name, 0, 100);
		$name = $this->escapize($name, '');
		
		if ( empty($name) ){
			$q = "INSERT INTO {$wpdb->prefix}wp2ap_cloaked_links(url, url_hash)
			  VALUES(%s, %s)";
			  
			$q = $wpdb->prepare($q,
				$url, 
				md5($url)
			);
		} else {
			$q = "INSERT INTO {$wpdb->prefix}wp2ap_cloaked_links(name, url, url_hash)
			  VALUES(%s, %s, %s)";
			
			$q = $wpdb->prepare($q,
			 	$name, 
				$url, 
				md5($url)
			);
		}
		
		if ( $wpdb->query($q) ) {
			$link_id = $wpdb->insert_id;
			
			return array(
				'id' => $wpdb->insert_id,
				'name' => $name, 
				'url' => $url,
				'url_hash' => md5($url),
				'hits' => 0,
			);
		} else {
			return false;
		}
	}
	
  /**
   * Einen oder mehrere cloaked links löschen   
   *
   * @param int|array $id Link IDs
   * @return int Number of links actually deleted
   *    
   */
	function delete_link( $id ){
		global $wpdb; /** @var wpdb $wpdb */
		if ( !is_array($id) ){
			$id = array( intval($id) );
		}
		$q = "DELETE FROM {$wpdb->prefix}wp2ap_cloaked_links WHERE id IN (". implode(', ', $id) .")";
		$res = $wpdb->query($q);
				
		return $res;
	}
		
  /**
   * Wp2Affiliate::escapize()
   * Anker-Text für die Verwendung in der cloaked URL vorbereiten. Bei Bildern versuchen wir den ALT/TITLE Tag auszulesen. 
   * Tags entfernen und Sonder- oder Leerzeichen durch Unterstriche ersetzen .
   *
   * @param string $text Link text (AKA anchor text).
   * @param string $def The default link text. This will be used if $text is empty or unusable. Defaults to the text set in options.   
   * @return string Sanitized link text. Contains only alphanumerics and underscores.
   *    
   */
	function escapize( $text, $def = null ){
		$new_text = trim(strip_tags($text));
		$new_text = remove_accents($new_text);  
		$new_text = str_replace(chr(194).chr(173), '', $new_text); //Soft hyphen, UTF-8 encoding = 0xC2 0xAD
		
		$new_text = preg_replace('/[^a-z0-9_.\-]+/i', '_', $new_text);
		
		if( (strlen($new_text) < 1) || ($new_text == '_') ) {
			if ( !is_null($def) ){
				$new_text = $def;
			} else {
				// Wenn der Link ein Bild enthält, versuchen wir den ALT/TITLE Tag auszulesen
				if ( preg_match('@<img[^>]+(?:alt|title)\s*=\s*(\"|\')(?P<alt_text>[^\"\'>]+?)\1@i', $text, $matches) ){
					$new_text = $this->escapize( $this->unhtmlentities($matches['alt_text']) );
				} else {
					$new_text = "link";
				}
			}
		};
		
		return $new_text;
	}

	
  /**
   * wp2a_aff_redir
   * URL vorm Redirect bearbeiten und ggf. umwandeln
   *
   * @param string $urlroh
   * @return string
   */


function wp2a_aff_redir( $urlroh ){

//print_r($this->bekannteaffs);

foreach($this->bekannteaffs AS $a){
  if (stripos($urlroh, $a)!==false){ 
  $bekannterlink = '1';
  break; // Sobald wir einen Treffer haben, brechen wir das foreach ab. Dann handelt es sich um einen vorhandenen Affiliate-Link (nicht durch wp2a verwaltet)
  }
}
// Auf Amazon-Link mit Aff-Code prüfen, da wollen wir ebenfalls abbrechen
//if (stripos($urlroh, '.amazon.')!==false AND stripos($urlroh, 'tag=')!==false){ 
if (stripos($urlroh, '.amazon.')!==false AND (stripos($urlroh, 'tag=')!==false OR stripos($urlroh, 'tag%3D')!==false)){ // Wenn der Parameter "tag=" enthalten ist, ist es schon ein Affiliate-Link. Parameter könnte auch encodiert sein (war bei genialeangebote der Fall)
  $bekannterlink = '1';
  }
// Auf Digistore24-Link mit Aff-Code prüfen, da wollen wir ebenfalls abbrechen
if (stripos($urlroh, '.digistore24.')!==false AND (stripos($urlroh, 'promo.')!==false OR stripos($urlroh, 'aff=')!==false)){ // Wenn es ein Digistore-Link ist, ist er nur dann ein bekannter Aff-Link, wenn "promo." oder "aff=" darin vorkommt 
  $bekannterlink = '1';
  }

if ($bekannterlink) { // Wenn es ein bekannter Aff-Link ist, sind wir direkt fertig und sparen uns den Rest
//echo $urlroh;
return $urlroh; 
}

// Zuerst prüfen, ob der Link in den Aff-Programmen vorhanden ist
  global $wpdb; /** @var wpdb $wpdb */
  $q = "SELECT nw_aktiv, aff_code FROM {$wpdb->prefix}wp2ap_aff_links WHERE '".$urlroh."' LIKE CONCAT('%', url_match, '%')";
  $linkdata = $wpdb->get_results($q, ARRAY_A);
    if ($linkdata){
    // Hier müssen wir den Link für den wp2a-Server zusammenbauen. Falls vom User hinterlegt, übergeben wir Netzwerk und Aff Code, ansonsten nur die URL und ggf. Plugin-Version
    $url = rawurlencode($urlroh);
    $nw = rawurlencode($linkdata[0]['nw_aktiv']);
    $id = rawurlencode($linkdata[0]['aff_code']);
    $v = rawurlencode($this->version);
    $q = rawurlencode(get_option('home'));  // Die Quelle brauchen wir momentan nicht
    

    if ($this->options['testing'] == true){
    $link = "http://www.wp2affiliate.com/redir-testing/redir.php?url=".$url;
    } else {
    $link = "http://www.wp2affiliate.com/redir/redir.php?url=".$url;
    }
    
    if ($nw AND $id){ $link .= "&nw=".$nw."&id=".$id;} // Falls vorhanden, ergänzen wir Netzwerk und ID
    
    $link .= "&v=".$v; // Am Ende ergänzen wir noch Plugin-Version
    $link .= "&q=".$q; // und die Quelle, falls wir das mal brauchen
    
    return $link; // fertiger Link zum wp2a Server wird zum redirect übergeben
    
    } else { // Kein Treffer in der Aff-Link DB, wir geben die URL unverändert weiter
    return $urlroh;    
    }
  
    // wenn nichts davon gepasst hat, geben wir die URL unverändert zurück
   return $urlroh;     
	}  // end function wp2a_aff_redir
	
  /**
   * Wp2Affiliate::hook_init()
   * Verarbeitet die aktuelle Anfrage und regelt die cloaked redirects.
   *
   * @return void
   */
	function hook_init(){
		// Wir interessieren uns nur für GET requests
		if ( $_SERVER['REQUEST_METHOD'] != 'GET' ) return;
		
		// Früh abbrechen, wenn due URL nicht das Prefix enthält 
		if ( !isset($_GET[$this->options['prefix']]) && (strpos( $_SERVER['REQUEST_URI'], '/' . $this->options['prefix'] . '/' ) === false)	) {
			return;
		}
		
		// Den Teil hinter ? entsorgen, wenn vorhanden
		$req_uri = $_SERVER['REQUEST_URI'];
		$req_uri_array = explode('?', $req_uri);
		$req_uri = $req_uri_array[0];
		
		// home path von der URI entfernen
		$home_path = parse_url(get_option('home'));
		if ( isset($home_path['path']) )
			$home_path = $home_path['path'];
		else
			$home_path = '';
		$home_path = trim($home_path, '/');

		$req_uri = trim($req_uri, '/');
		$req_uri = preg_replace("|^$home_path|", '', $req_uri);
		$req_uri = trim($req_uri, '/');
		
		$name = '';
		$id = 0;
		
		/*
			## Gültige URI Formate ##: 
      index.php/prefix/link_name/
			index.php/prefix/link_text/123
			/prefix/link_name/
			/prefix/123
			index.php?prefix=link_name
		*/
		
		$pattern = "
		 |^(?:index\.php/)?					# trifft den Start der URI, oder index.php   
			{$this->options['prefix']}/		# trifft den festgelegten prefix gefolgt von einem slash
			(?P<token1>(?>[^/?]+))			# gefolgt von bis zu zwei tokens, getrennt durch slash    
			(?:/(?P<token2>(?>[^/]+)))?		#   					    
		 |x";   


		if ( preg_match( $pattern, $req_uri, $matches ) ){
			if ( isset($matches['token2'] ) ){
				// Beide token sind vorhanden.
				$name = urldecode($matches['token1']);
				// Wir brauchen nur den zweiten Teil um die URL zu finden.
				$token2 = urldecode($matches['token2']);
				 
				if ( is_numeric( $token2 ) ){
					// Wenn der zweite Token eine Zahl ist, ist es die Link-ID
					$id = intval($token2);
				} 
				
			} else {
				// Nur der erste token ist vorhanden. 
				$token1 = urldecode( $matches['token1'] );
				
				if ( is_numeric( $token1 ) ){
					// Wenn der erste Token eine Zahl ist, ist es die link ID
					$id = intval($token1);
				} else {
          // Ansonsten ist es der link name.
					$name = $token1;
				}
				
			}
			
		} else if ( isset( $_GET[$this->options['prefix']] ) && !empty( $_GET[$this->options['prefix']] )  ){
			// Wenn das Muster nicht passt, sollten die Link-Daten in den query params sein
			$name = $_GET[$this->options['prefix']];
			if ( isset( $_GET['id'] ) ) $id = intval($_GET['id']);
			
			// Nochmal: Wenn der Name eine Zahl ist, sollte es die Link-ID sein
			if ( is_numeric($name) ){
				$id = intval($name);
				$name = '';
			}
		} else {
			//Falscher Alarm, die URL scheint doch kein gecloakter link zu sein.
			return;
		}

		//echo "name = $name, id = $id\n";
		$this->handle_cloaked_link($name, $id);		
	}
	
	function handle_cloaked_link($name, $id = null){
		// holt die ZIel-URL basierend auf dem Link-Namen
		$link = $this->get_target_link( array('name' => $name, 'id' => $id) );
		if ( $link ){
			$title = str_replace(array('_', '-'), ' ', !empty($link['name'])?$link['name']:$name);
			
			//Pass along any GET parameters except the prefix and 'id'. Sometimes we also 
			//need to exclude the 'step' params that's automatically tacked on when using the 
			//META refresh trick (see handle_redirect).
			$strip_params = array($this->options['prefix'] => '', 'id' => '');
			if ( $this->options['redirect_type'] == 'meta' ){ $strip_params['step'] = ''; }
			$params = array_merge( $_GET, $strip_params );
			$params = array_filter( $params );
			$params = http_build_query( $params, null, '&' );
			if ( $params ) {
				$params = '?' . $params;
			}
			
			$url = $link['url'] . $params;
       
        // Holger: Hier wird die URL an die Funktion für die Aff-Links übergeben, bevor der redirect erfolgt    
      $url = $this->wp2a_aff_redir($url);
			
			// Klick für die Statistik aufzeichnen
			$this->record_hit( $link['id'] ); 
      // und die Weiterleitung ausführen
			$this->handle_redirect( $url, $title );
			
			die();
		} else {
			//No such link
			_e("Error: Invalid link! Please check the URL to make sure it is correct.", 'wp-2-affiliate');
			die();
		}
	}
	
  /**
   * Wp2Affiliate::handle_redirect()
   * Führt die Weiterleitung aus, wie in $options['cloaking_method'] festgelegt  
   *
   * @param string $url Die URL an die weitergeleitet werden soll
   * @param string $title (Optional) Der Seiten-Titel. Nur für die Weiterleitung per meta refresh.  
   * @return void
   */
	function handle_redirect( $url, $title = '' ){
		
		switch ( $this->options['redirect_type'] ){
			
			case '301redirect' : // Ganz normale Weiterleitung per 301
				header("HTTP/1.1 301 Moved Permanently");
				header('P3P: CP="DSP NON ADM PUBi BUS NAV COM CNT INT ONL PHY DEM"');
				$this->send_nocache_headers();
				header("Location: $url", true, 301);
				header("X-Redirect-Src: Wp2Affiliate", true);
				break;
				
			case '302redirect' : // Weiterleitung per 302
				header("HTTP/1.1 302 Found");
				header('P3P: CP="DSP NON ADM PUBi BUS NAV COM CNT INT ONL PHY DEM"');
				$this->send_nocache_headers();
				header("Location: $url", true, 302);
				header("X-Redirect-Src: Wp2Affiliate", true);
				break;
				
			case '307redirect' : // Weiterleituing per 307 redirect (Temporary)
				header("HTTP/1.1 302 Temporary Redirect");
				header('P3P: CP="DSP NON ADM PUBi BUS NAV COM CNT INT ONL PHY DEM"');
				$this->send_nocache_headers();
				header("Location: $url", true, 307);
				header("X-Redirect-Src: Wp2Affiliate", true);
				break;
				
			case 'meta' :		 // Doppelter META refresh um den referer zu verstecken
				//Die Anzahl der Refreshs muss festgelegt sein!
				$max_steps = 2; 
			    $referer = $_SERVER['HTTP_REFERER'];
			    $step = isset($_GET['step'])?intval($_GET['step']):1;
			    
			    //Wenn der referer immernoch da ist, erneuter redirect an die aktuelle URL again (Zählen der Versuche)
			    if( ($referer != '') && ( $step < $max_steps ) ) {
			    	$step++;
			    	$url = $_SERVER['REQUEST_URI'];
					if ( strpos($_SERVER['REQUEST_URI'], '?') !== false ){
						$url .= '&';
					} else {
						$url .= '?';
					}
					$url .= "step=$step";
			    }
			    
			    header('P3P: CP="DSP NON ADM PUBi BUS NAV COM CNT INT ONL PHY DEM"');
			    $this->send_nocache_headers();
			    
				echo "<html><head><title>" . htmlspecialchars($title) . "</title>",
				     "<meta http-equiv='refresh' content='0;url=".htmlspecialchars($url)."'></head>",
					 "<body></body></html>";
				break;
				
			default :
				printf(__('Configuration error : unrecognized or unsupported redirect type "%s"', 'wp-2-affiliate'),$this->options['redirect_type']); 
				
		}
	}
	
  /**
   * Wp2Affiliate::get_target_link()
   * Infos über den cloaked link bekommen. Der Parameter muss ein assoziatives Array mit mindestens
   * einem von diesem Keys sein 
   *	id - Die Datenbank-ID
   *	name - ein einmaliger Name, der dem Link zugewiesen wurde
   *
   * @param array $query ein assoziatives Array.  
   * @return array Den link record als ein assoziatives Array, oder Null bei Fehler
   */
	function get_target_link( $query ){
		global $wpdb; /* @var wpdb $wpdb */
		
		if ( !empty( $query['id'] ) ) {
			// Lade den link record mit dieser ID aus der DB.
			$q = "SELECT * FROM {$wpdb->prefix}wp2ap_cloaked_links WHERE id=%d LIMIT 1";
			$link = $wpdb->get_row( $wpdb->prepare($q, intval($query['id'])), ARRAY_A);
			return $link;
		} else if ( !empty( $query['name'] ) ){
			// Lade den link record mit diesem Namen aus der DB.
			$q = "SELECT * FROM {$wpdb->prefix}wp2ap_cloaked_links WHERE name=%s LIMIT 1";
			$link = $wpdb->get_row( $wpdb->prepare($q, $query['name']), ARRAY_A);
			return $link;			
		} else {
			return null;
		}
	}
	
  /**
   * Wp2Affiliate::record_hit()
   * Klick für den aktuelle cloaked link aufzeichnen, definiert durch $id.  
   *
   * @param int $id Link ID. 
   * @return bool True wenn der KLick erfolgreich aufgezeichnet wurde.
   */
	function record_hit( $id ){
		global $wpdb; /* @var wpdb $wpdb */
		
		// Prüfen ob der übergebene Wert eine gültige ID ist
		if ( !is_numeric($id) ) return false;  
		$id = intval($id);
		if ($id <= 0) return false;
		
		// bots und crawlers werden nicht aufgezeichnet
		if ( array_key_exists('HTTP_USER_AGENT', $_SERVER) ){
			if( array_search(strtolower($_SERVER['HTTP_USER_AGENT']), $this->options['bots'])!==false ) {
				return false;
			}
		}
		
		// Erst aktualisieren wir die Gesamtsumme
		$q = "UPDATE {$wpdb->prefix}wp2ap_cloaked_links SET hits = hits + 1 WHERE id = ".$id;
		return $wpdb->query( $q ) !== false;
	}

	/**
	 * Wp2Affiliate::install()
	 * Datenbank-Tabellen anlegen oder updaten
	 * 
	 * @return void
	 */
	function install(){
		if ( WPMultiLock::acquire('wp2a_install_lock', 5) ){
			
			$this->upgrade_database();

			$this->options['version'] = $this->version;
			$this->save_options();
			
			WPMultiLock::release('wp2a_install_lock');
		}
	}
	
	/**
	 * Installation oder Update, wenn die Version sich geändert hat (oder wenn es die erste Installation ist)
	 * 
	 * @return void
	 */
	function maybe_install(){
    if ( version_compare( $this->options['version'], $this->version, '<>' ) ) {
			$this->install();
      // Run specific upgrade routines falls nötig
      //  if ( version_compare( $active_version, '1.1', '<' ) )
      //  			zum-beispiel_upgrade_to_version_1_1();
		}
	}
  
	function admin_menu(){
  
		//Holger: Hier wird der Hauptmenüpunkt geladen
			$options_page_hook_first = add_menu_page(
      			'WP2Affiliate Settings', 'wp2affiliate', 'manage_options',
      			'wp2a_settings',array(&$this,'page_options'), plugin_dir_url( dirname(__FILE__) . '/wp2affiliate.php' ).'images/menu-icon.png'
      		);
	        
	        if ( is_callable('add_screen_options_panel') ){
	        	add_screen_options_panel(
					'wp2a-screen-options', 
					'',
					array(&$this, 'screen_options'),
					$options_page_hook_first 
				);
	        }  
// Hier wird die gleiche Seite erneut als Untermenü geladen (damit wir einen anderen Namen übergeben können) 
		$options_page_hook = add_submenu_page( 'wp2a_settings',
			'WP2Affiliate Settings', 'Settings und IDs', 'manage_options',
			'wp2a_settings',array(&$this,'page_options')
		);
		if ($options_page_hook) {
			add_action( 'admin_print_scripts-' . $options_page_hook, array(&$this, 'load_admin_options_scripts'));
			add_action( 'admin_print_styles-' . $options_page_hook, array(&$this, 'load_admin_css') );
		}
			
		//Show the "Cloaked Links" menu

			$links_page_hook = add_submenu_page( 'wp2a_settings',
				__('wp2affiliate Cloaked Links', 'wp-2-affiliate'), 
				__('Cloaked Links', 'wp-2-affiliate'), 
				'edit_others_posts',
				'wp2affiliate_cloaked_links', 
				array(&$this, 'page_cloaked_links')
			);
			add_action( 'admin_print_styles-' . $links_page_hook, array(&$this, 'load_admin_css') );
	        add_action( 'admin_print_scripts-' . $links_page_hook, array(&$this, 'load_admin_scripts') );
	        
	        if ( is_callable('add_screen_options_panel') ){
	        	add_screen_options_panel(
					'wp2a-screen-options', 
					'',
					array(&$this, 'screen_options'),
					$links_page_hook 
				);
	        }
    
		//Holger: Hier wird die Seite für die Affiliate-Programme geladen
			$links_page_hook = add_submenu_page( 'wp2a_settings',
				__('wp2affiliate Programs', 'wp-2-affiliate'), 
				__('Affiliate Programs', 'wp-2-affiliate'), 
				'edit_others_posts',
				'wp2affiliate_programs', 
				array(&$this, 'page_aff_links')
			);
			add_action( 'admin_print_styles-' . $links_page_hook, array(&$this, 'load_admin_css') );
	        add_action( 'admin_print_scripts-' . $links_page_hook, array(&$this, 'load_admin_scripts') );
	        
	        if ( is_callable('add_screen_options_panel') ){
	        	add_screen_options_panel(
					'wp2a-screen-options', 
					'',
					array(&$this, 'screen_options'),
					$links_page_hook 
				);
	        }
	}
 
	function mytruncate($str, $max_length = 50){
		if(strlen($str)<=$max_length) return $str;
		return (substr($str, 0, $max_length-3).'...');
	}

 	function page_options(){
		if(isset($_POST['submit']) && current_user_can('manage_options')) {
			check_admin_referer('link-cloaker-settings');
			
				// Einstellungen speichern
				
				//Cloaking settings
				$this->options['filter_mode'] = $_POST['filter_mode'];
				$this->options['filter_feed'] = isset($_POST['filter_feed']) && $_POST['filter_feed'];
				$this->options['link_mode'] = $_POST['link_mode'];
				$this->options['redirect_type'] = $_POST['redirect_type'];
				
				if ( !empty($_POST['prefix']) ){
					$this->options['prefix'] = $this->escapize($_POST['prefix'], $this->options['prefix']);
				} else {
					$this->options['prefix'] = 'goto';
				}

		    global $wpdb; /* @var wpdb $wpdb */  // Holger: Ohne diese Variable, können wir keine querrys machen
        
        // ### Holger: Neue Optionen mit Affiliate-Netzwerken // Für jedes Netzwerk wiederholen
				if ( $_POST['affilinet'] != $this->options['affilinet'] ){ // Wenn eine Netzwerk-ID eingetragen wurde, die vom vorhandenen Wert in den Options abweicht, speichern wir diese in die Options und in der DB bei allen Aff-Porgrammen, die das Netzwerk aktiv haben
					$this->options['affilinet'] = trim($_POST['affilinet']);
      		$q = "UPDATE {$wpdb->prefix}wp2ap_aff_links SET aff_code = '".trim($wpdb->escape($_POST['affilinet']))."' WHERE nw_aktiv = 'affilinet'";
      		$wpdb->query($q);
          } 
				if ( $_POST['tradedoubler'] != $this->options['tradedoubler'] ){ // Wenn eine Netzwerk-ID eingetragen wurde, die vom vorhandenen Wert in den Options abweicht, speichern wir diese in die Options und in der DB bei allen Aff-Porgrammen, die das Netzwerk aktiv haben
					$this->options['tradedoubler'] = trim($_POST['tradedoubler']);
      		$q = "UPDATE {$wpdb->prefix}wp2ap_aff_links SET aff_code = '".trim($wpdb->escape($_POST['tradedoubler']))."' WHERE nw_aktiv = 'tradedoubler'";
      		$wpdb->query($q);
          } 
				if ( $_POST['amazon'] != $this->options['amazon'] ){ // Wenn eine Netzwerk-ID eingetragen wurde, die vom vorhandenen Wert in den Options abweicht, speichern wir diese in die Options und in der DB bei allen Aff-Porgrammen, die das Netzwerk aktiv haben
					$this->options['amazon'] = trim($_POST['amazon']);
      		$q = "UPDATE {$wpdb->prefix}wp2ap_aff_links SET aff_code = '".trim($wpdb->escape($_POST['amazon']))."' WHERE nw_aktiv = 'amazon'";
      		$wpdb->query($q);
          } 
				if ( $_POST['digistore24'] != $this->options['digistore24'] ){ // Wenn eine Netzwerk-ID eingetragen wurde, die vom vorhandenen Wert in den Options abweicht, speichern wir diese in die Options und in der DB bei allen Aff-Porgrammen, die das Netzwerk aktiv haben
					$this->options['digistore24'] = trim($_POST['digistore24']);
      		$q = "UPDATE {$wpdb->prefix}wp2ap_aff_links SET aff_code = '".trim($wpdb->escape($_POST['digistore24']))."' WHERE nw_aktiv = 'digistore24'";
      		$wpdb->query($q);
          } 
        // ### Holger: Ende neue Optionen mit Affiliate-Netzwerken
				
				$this->options['blacklist'] = array_filter( preg_split( '/[\s,\r\n]+/', $_POST['blacklist'] ) );
				$this->options['whitelist'] = array_filter( preg_split( '/[\s,\r\n]+/', $_POST['whitelist'] ) );
				
				$this->options['tweak_new_window'] = isset($_POST['tweak_new_window']) && $_POST['tweak_new_window'];
				$this->options['tweak_nofollow'] = isset($_POST['tweak_nofollow']) && $_POST['tweak_nofollow'];
        $this->options['tweak_google_analytics'] = isset($_POST['tweak_google_analytics']) && $_POST['tweak_google_analytics'];
				$this->options['tweak_class'] = isset($_POST['tweak_class']) && $_POST['tweak_class'];				
				$this->options['custom_class'] = $this->escapize($_POST['custom_class'], '');
				
				$this->save_options();
				
				echo '<div id="message" class="updated fade"><p><strong>',__('Settings saved.', 'wp-2-affiliate'), '</strong></p></div>';
		}
		
?>
<div id='wp2ap-box'>
<div class="wrap"><h2><?php _e('WP2Affiliate Settings', 'wp-2-affiliate'); ?></h2>


<div id='wp2ap-tabs'>

<form name="cloaking_options" method="post" action="<?php echo esc_attr(admin_url('admin.php?page=wp2a_settings')); ?>"> 

<?php
	wp_nonce_field('link-cloaker-settings');
?>

<ul>
	<li><a href="#wp2ap-tab-1"><?php _e('Link Cloaking', 'wp-2-affiliate'); ?></a></li>
	<li><a href="#wp2ap-tab-2"><?php _e('Affiliate IDs', 'wp-2-affiliate'); ?></a></li>
</ul>

<div id="wp2ap-tab-1">
<h3><?php _e('Link Cloaking', 'wp-2-affiliate'); ?></h3>

<div class="metabox-holder">
<div class="postbox ">
<h3 class="hndle">
<span><?php _e('General Link Cloaking Settings', 'wp-2-affiliate'); ?></span>
</h3>

<div class="inside">


<p><?php 
	_e('Here you need to specify where and how you want to cloak your links. The default settings are good, you can keep it. <br/>Please note: <strong>Only cloaked links can be converted into affiliate links!</strong> Cloaking is done while outputting, your data in the DB remains unchanged.', 'wp-2-affiliate'); 
?></p>

<table class="form-table"> 

<tr valign="top"> 
<th scope="row"><?php _e('Where to cloak?', 'wp-2-affiliate'); ?></th> 
<td>

<p>
<label for="filter_mode_everything"><input type="radio" name="filter_mode" id="filter_mode_everything" value="everything"
<?php if($this->options['filter_mode'] == 'everything') echo ' checked' ?>/> <?php _e('<strong>Everywhere</strong>, in any part of the site (posts, pages, sidebar, comments...)', 'wp-2-affiliate'); ?></label>
</p>
<p>
<label for="filter_mode_content"><input type="radio" name="filter_mode" id="filter_mode_content" value="content"
<?php checked($this->options['filter_mode'], 'content'); ?>/> <?php _e('<strong>Only in post/page content</strong> (including custom post types)', 'wp-2-affiliate'); ?></label>
</p>
<p>
<label for="filter_feed"><input type="checkbox" name="filter_feed" id="filter_feed"
<?php if($this->options['filter_feed']) echo ' checked' ?>/> <?php _e('Also in RSS-feeds', 'wp-2-affiliate'); ?></label>
</p>

</td> 
</tr> 

<tr valign="top"> 
<th scope="row"><?php _e('What to cloak?', 'wp-2-affiliate'); ?></th> 
<td>

<p>
<label><input type="radio" name="link_mode" id="mode-everything" value="everything"
<?php if($this->options['link_mode']=='everything') echo ' checked' ?>/> <strong><?php _e('All Links', 'wp-2-affiliate'); ?></strong></label><br>
<?php _e('All links will be cloaked, except links that are tagged with <code>&lt;!--nowp2a--&gt;</code> (example: <code>&lt;a href="http://www.example.com/"&gt;&lt;!--nowp2a--&gt;click here&lt;/a&gt;</code>) or match to the blacklist. Cloaking can still be disabled individually for each post/page.', 'wp-2-affiliate'); ?>
</p>

<?php $settings_link = '<a href="' . admin_url( 'admin.php?page=wp2affiliate_programs' ) . '">' . __( 'affiliate programs', 'wp-2-affiliate' ) . '</a>';?>

<p>
<label><input type="radio" name="link_mode" id="mode-affmatch" value="filter_affmatch" 
<?php if($this->options['link_mode']=='filter_affmatch') echo ' checked' ?>/> <strong><?php _e('Only Managed Affiliate Links', 'wp-2-affiliate'); ?></strong></label><br/>
 <?php echo sprintf(__("Only links that match to the list of %s will be cloaked. Best compatibility with any other link-plugins.", 'wp-2-affiliate'), $settings_link); ?>
</p>

<p>
<label><input type="radio" name="link_mode" id="mode-selective" value="selective" 
<?php if($this->options['link_mode']=='selective') echo ' checked' ?>/> <strong><?php _e('Selective Cloaking', 'wp-2-affiliate'); ?></strong></label><br/>
<?php _e('Only links tagged with <code>&lt;!--wp2a--&gt;</code> (example: <code>&lt;a href="http://www.example.com/"&gt;&lt;!--wp2a--&gt;click here&lt;/a&gt;</code>) and links that match the whitelist will be cloaked.', 'wp-2-affiliate'); ?>
</p>

</td> 
</tr>

<tr valign="top" id="row-blacklist"<?php 
	if($this->options['link_mode'] != 'everything') echo ' style="display:none;"'; 
?>> 
<th scope="row"><?php _e('Blacklist', 'wp-2-affiliate'); ?></th> 
<td>

<textarea name='blacklist' id='blacklist' cols='60' rows='4'>
<?php echo implode("\n", $this->options['blacklist']); ?>
</textarea>

<br/>

<p><?php _e('Blacklist: One domain or URL per row. Links that match to any of these values <strong>will not</strong> be cloaked when cloaking is set to "All Links". At least your own domain should be here, because internal links should not be cloaked!', 'wp-2-affiliate'); ?></p>

</td> 
</tr> 

<tr valign="top" id="row-whitelist"<?php 
	if($this->options['link_mode'] != 'selective') echo ' style="display:none;"'; 
?>> 
<th scope="row"><?php _e('Whitelist', 'wp-2-affiliate'); ?></th> 
<td>

<textarea name='whitelist' id='whitelist' cols='60' rows='4'>
<?php echo implode("\n", $this->options['whitelist']); ?>
</textarea>

<br/>

<p><?php _e('Whitelist: One domain or URL per row. Links that match to any of these values will be cloaked when cloaking is set to "Selective Cloaking".', 'wp-2-affiliate'); ?></p>

</td> 
</tr>
</table>

<p class="submit"><input type="submit" name="submit" class='button-primary' value="<?php _e('Save All Changes', 'wp-2-affiliate'); ?>" /></p>

</div>
</div>
<div class="postbox ">
<h3 class="hndle">
<span><?php _e('Advanced Cloaking settings', 'wp-2-affiliate'); ?></span>
</h3>

<div class="inside">

<table class="form-table">

<tr valign="top"> 
<th scope="row"><?php _e('Link prefix', 'wp-2-affiliate'); ?></th> 
<td><input type='text' name='prefix' id='prefix' value='<?php echo $this->options['prefix']; ?>' size='15' />
<?php 
	printf( __('Cloaked links will look like this: <code>%s</code>', 'wp-2-affiliate'), $this->make_cloaked_url('LinkName')
	);
?>
</td></tr>

<tr valign="top"> 
<th scope="row"><?php _e('Additional Tweaks', 'wp-2-affiliate'); ?></th> 
<td>

<p>
<label for="tweak_new_window"><input type="checkbox" name="tweak_new_window" id="tweak_new_window"
<?php if($this->options['tweak_new_window'] ) echo ' checked' ?>/> <?php
	_e('Add <code>target="_blank"</code> for every cloaked link (open in new window)', 'wp-2-affiliate'); 
?></label>
</p>

<p>
<label for="tweak_nofollow"><input type="checkbox" name="tweak_nofollow" id="tweak_nofollow"
<?php if($this->options['tweak_nofollow'] ) echo ' checked' ?>/> <?php 
	_e('Add <code>rel="nofollow"</code> for every cloaked link (stop giving link juice)', 'wp-2-affiliate'); 
?></label>
</p>

<p>
<label for="tweak_google_analytics"><input type="checkbox" name="tweak_google_analytics" id="tweak_google_analytics"
<?php if($this->options['tweak_google_analytics']) echo ' checked' ?>/> <?php 
	_e('Track clicks as event with Google Analytics', 'wp-2-affiliate'); 
?>
</label>
</p>

<p style="margin-left: 23px;">
<?php 
	_e('You need to have already set up the GA code on your site (analytics.js). The event-category will be "Cloaked Links WP2A", the action will be the uncloaked original URL. <br/> Please note: The GA-tracking will only work properly with the target="_blank" attribute (see option above).', 'wp-2-affiliate'); 
?>
</p>

<p>
<label for="tweak_class"><input type="checkbox" name="tweak_class" id="tweak_class"
<?php if($this->options['tweak_class']) echo ' checked' ?>/> <?php 
	_e('Add custom css-class for every cloaked link', 'wp-2-affiliate'); 
?>
</label>
</p>

<p style="margin-left: 23px;">
<?php _e('Custom css-class:', 'wp-2-affiliate'); ?>  
<input type="text" name="custom_class" id="custom_class" size="15" value="<?php 
	echo htmlspecialchars($this->options['custom_class']); 
?>" <?php if ( !$this->options['tweak_class'] ) echo 'disabled="disabled"'; ?>>
</p>

</td> 
</tr> 

<tr valign="top"> 
<th scope="row"><?php _e('Redirect type', 'wp-2-affiliate'); ?></th> 
<td>

<p>
<?php
	$redirect_types = array(
		'301redirect'=> __('301 Permanent Redirect', 'wp-2-affiliate'),
		'302redirect' => __('302 Found (recommended)', 'wp-2-affiliate'),
		'307redirect' => __('307 Temporary Redirect', 'wp-2-affiliate'),	
		'meta' => __('Double META refresh (will hide the referer in most cases)', 'wp-2-affiliate'),
	);
	foreach ($redirect_types as $type => $description){
		echo '<p><label for="redirect_'.$type.'">';
		echo '<input type="radio" name="redirect_type" id="redirect_'.$type.'" value="'.$type.'"';
		if ($this->options['redirect_type'] == $type){
			echo ' checked="checked"';
		}
		echo ' /> '.$description.'</label></p>'; 		
	} 
?>
</p>

</td> 
</tr>  

</table>

<p class="submit"><input type="submit" name="submit" class='button-primary' value="<?php _e('Save All Changes', 'wp-2-affiliate'); ?>" /></p>

</div>
</div>
</div> 

</div>


<?php // ############ Holger: Hier kommt die Regsiterkarte für die Affiliate IDs bei den einzelnen Netzwerken; ?>

<div id="wp2ap-tab-2">
<h3><?php _e('Affiliat IDs', 'wp-2-affiliate'); ?></h3>

<div class="metabox-holder">
<div class="postbox ">
<h3 class="hndle">
<span><?php _e('Affiliate Settings and IDs', 'wp-2-affiliate'); ?></span>
</h3>

<div class="inside">

<p><?php echo sprintf(__("Here you have to store your affiliate IDs. <strong>Attention:</strong> You need to activate the partnerships at %s for the various affiliate programs! <br/>For Zanox the code is different for each affiliate program. The other networks can be centrally maintained here.", 'wp-2-affiliate'), $settings_link); ?> 
</p>

<table class="form-table">

<tr valign="top"> 
<th scope="row" style="width: 100px;"><a title="Affili.net" target="_blank" href="http://www.affili.net"><?php _e('Affili.net', 'wp-2-affiliate'); ?></a></th> 
<td width="15%" style="vertical-align: top;"><input type='text' name='affilinet' id='affilinet' value='<?php echo $this->options['affilinet']; ?>' size='15' />
</td>
<td style="vertical-align: top;">
  <p><?php _e('Your Affili.net Publisher-ID', 'wp-2-affiliate'); ?></p>
  <p><?php _e('Example:', 'wp-2-affiliate'); ?> <code>612345</code></p>
  <p><?php _e('Source:', 'wp-2-affiliate'); ?> http://partners.webmasterplan.com/click.asp?ref=<span class="wp2ap-redunderline">612345</span>&site=7412&type=text&tnb=4</p>
</td>

</tr>

<tr valign="top"> 
<th scope="row" style="width: 100px;"><a title="Zanox" target="_blank" href="http://www.zanox-affiliate.de/ppc/?28216347C1754762036T"><?php _e('Zanox', 'wp-2-affiliate'); ?></a></th> 
<td style="vertical-align: top;"><input type='text' name='unnoetig' id='unnoetig' value='' size='15' readonly/>  
</td>
<td style="vertical-align: top;">
  <p><?php _e('For Zanox you need to store the deeplink code individually for each program!', 'wp-2-affiliate'); ?></p>
  <p><?php _e('Example:', 'wp-2-affiliate'); ?> <code>27222481C93191234</code></p>
  <p><?php _e('Source:', 'wp-2-affiliate'); ?> http://ad.zanox.com/ppc/?<span class="wp2ap-redunderline">27222481C93191234</span>&ULP=[[http://www.ab-in-den-urlaub.de/]]</p>
</td>

</tr>

<?php // Todo: Partner-Link für Tradedoubler einfügen! ;?>

<tr valign="top"> 
<th scope="row" style="width: 100px;"><a title="Tradedoubler" target="_blank" href="http://www.tradedoubler.com/de-de/"><?php _e('Tradedoubler', 'wp-2-affiliate'); ?></a></th> 
<td style="vertical-align: top;"><input type='text' name='tradedoubler' id='tradedoubler' value='<?php echo $this->options['tradedoubler']; ?>' size='15' />
</td>
<td style="vertical-align: top;">
  <p><?php _e('Your Tradedoubler Website-ID', 'wp-2-affiliate'); ?></p>
  <p><?php _e('Example:', 'wp-2-affiliate'); ?> <code>2212345</code></p>
  <p><?php _e('Source:', 'wp-2-affiliate'); ?> http://clkde.tradedoubler.com/click?p=67379&a=<span class="wp2ap-redunderline">2212345</span>&g=17225858</p>
</td>

</tr>  

<tr valign="top"> 
<th scope="row" style="width: 100px;"><a title="Amazon" target="_blank" href="https://partnernet.amazon.de/"><?php _e('Amazon', 'wp-2-affiliate'); ?></a></th> 
<td style="vertical-align: top;"><input type='text' name='amazon' id='amazon' value='<?php echo $this->options['amazon']; ?>' size='15' />
</td>
<td style="vertical-align: top;">
  <p><?php _e('Your Amazon Tracking ID', 'wp-2-affiliate'); ?></p>
  <p><?php _e('Example:', 'wp-2-affiliate'); ?> <code>myidcode0b-21</code></p>
  <p><?php _e('Source:', 'wp-2-affiliate'); ?> http://www.amazon.de/?tag=<span class="wp2ap-redunderline">myidcode0b-21</span></p>
</td>

</tr>  

<tr valign="top"> 
<th scope="row" style="width: 100px;"><a title="Digistore24" target="_blank" href="https://www.digistore24.com/join/28869"><?php _e('Digistore24', 'wp-2-affiliate'); ?></a></th> 
<td style="vertical-align: top;"><input type='text' name='digistore24' id='digistore24' value='<?php echo $this->options['digistore24']; ?>' size='15' />
</td>
<td style="vertical-align: top;">
  <p><?php _e('Your Digistore24-ID', 'wp-2-affiliate'); ?></p>
  <p><?php _e('Example:', 'wp-2-affiliate'); ?> <code>username</code></p>
  <p><?php _e('Source:', 'wp-2-affiliate'); ?> http://promo.<span class="wp2ap-redunderline">username</span>.12345.digistore24.com/ <?php _e('or', 'wp-2-affiliate'); ?> https://www.digistore24.com/product/12345?aff=<span class="wp2ap-redunderline">username</span></p>
  <p><?php _e('Only works for product-links like https://www.digistore24.com/product/12345, not for digistore24-homepage or signup!', 'wp-2-affiliate'); ?></p>
</td>

</tr>  

</table>

<p class="submit"><input type="submit" name="submit" class='button-primary' value="<?php _e('Save All Changes', 'wp-2-affiliate'); ?>" /></p>

</div>
</div>
</div> 

</div> 

</form>
</div> <!--/wp2ap-tabs-->

<div class="postbox-container" style="width:15%; float: left; margin-top: 32px; margin-left: 10px;">


<div class="metabox-holder">
	<div class="ui-sortable meta-box-sortables">
		<div class="postbox" style="min-width: 0px;">
						<h3 class="hndle"><strong>WP2Affiliate Resources</strong></h3>
						<div class="inside resources">
			<p>

			</p>
            <ul>
						<li><a href="http://www.wp2affiliate.com/" target="_blank"><?php _e('Plugin Homepage (german)', 'wp-2-affiliate'); ?></a></li>
						<li><a href="http://www.wp2affiliate.com/price/" target="_blank"><?php _e('Click Sharing (german)', 'wp-2-affiliate'); ?></a></li>
            </ul>
					</div>
		</div>
	</div>
</div>
</div>

</div><!--/wp2ap-wrap-->
</div><!--/wp2ap-box-->

<?php // ############ Holger: Ende Einstellungen; ?>

<?php 

	}
 
	function output_link_row ( $link, $rowclass = '', $base_url = '' ){
	 	if ( empty($base_url) ){
			if ( !empty($_SERVER['HTTP_REFERER']) ){
				$base_url = $_SERVER['HTTP_REFERER'];
			} else {
				$base_url = 'admin.php?page=wp2affiliate_cloaked_links';
			}
		}
	 	?>
		<tr id='<?php echo "wp2ap-link-", $link['id']; ?>' class='wp2ap-row <?php echo $rowclass; ?>'>
            	<th scope="row" class="check-column">
            		<input type="checkbox" name="links[]" value="<?php echo $link['id']; ?>" />
				</th>
            	
				<td class='post-title column-title'>
                	<span class='wp2ap-link-id' style='display:none;'><?php echo $link['id']; ?></span>
                <?php 
					if ( !empty( $link['name'] ) ) {
						echo htmlspecialchars($link['name']);
					} else {
						echo '<span class="wp2ap-unnamed">', __('(None)','wp-2-affiliate'),'</span>';
					} 
					
					//inline action Links einfügen (kopiert von edit-post-rows.php)                  	
                  	$actions = array();
					$actions['edit'] = '<span><a href="#" class="wp2ap-editinline" title="' . esc_attr(__('Edit this link', 'wp-2-affiliate')) . '">' . __('Edit', 'wp-2-affiliate') . '</a>';

					
					$delete_url = wp_nonce_url(
						add_query_arg(array(
							'action' => 'delete-cloaked-link',
							'id' => $link['id'],
							'noheader' => 1,
						), $base_url),
						'delete-link_' . $link['id']
					);
					
					if (!empty($link['name']))
						$delete_confirmation = sprintf(__("You are about to delete the cloaked link '%s'\n  'Cancel' to stop, 'OK' to delete.", 'wp-2-affiliate'), $link['name']);
					else {
						$delete_confirmation = __("You are about to delete this cloaked link\n  'Cancel' to stop, 'OK' to delete.", 'wp-2-affiliate');
					} 
					
					$actions['delete'] = "<span class='delete'><a class='submitdelete' title='" . esc_attr(__('Delete this cloaked link','wp-2-affiliate')) . "' href='" . $delete_url . "' onclick=\"if ( confirm('" . esc_js( $delete_confirmation ). "') ) { return true;}return false;\">" . __('Delete', 'wp-2-affiliate') . "</a>";
					echo '<div class="row-actions">', implode(' | </span>', $actions), '</div>';
					
					echo '<div class="hidden" id="inline_', $link['id'] ,'">',
						 '<div class="link_name">', $link['name'] , '</div>',
						 '<div class="link_url">', $link['url'] , '</div>',
						 '</div>';
				?>
                </td>
                
				<td class='column-url'>
                	<a href='<?php print $link['url']; ?>' target='_blank' class='wp2ap-link-url'>
                    	<?php print $this->mytruncate($link['url']); ?></a>
				</td>

				<td class='column-cloaked-url'>
                    <?php if ( !empty( $link['name'] ) ){
             				$cloaked = $this->make_cloaked_url( $link['name'] );
                    	} else {
							$cloaked = $this->make_cloaked_url('', $link['id']);
						}
                
						echo '<input type="text" value="'. $cloaked .'" class="wp2ap-cloaked-url-box" readonly="readonly" />'; ?>
				</td>

				
				<td>
        
        <?php 
            foreach($this->bekannteaffs AS $a){
              if (stripos($link['url'], $a)!==false){ 
              $bekannterlink = '1';
              break; // Sobald wir einen Treffer haben, brechen wir das foreach ab. Dann handelt es sich um einen vorhandenen Affiliate-Link (nicht durch wp2a verwaltet)
              }
              }
            // if (stripos($link['url'], '.amazon.')!==false AND stripos($link['url'], 'tag=')!==false){
            if (stripos($link['url'], '.amazon.')!==false AND (stripos($link['url'], 'tag=')!==false OR stripos($link['url'], 'tag%3D')!==false)){ // Wenn der Parameter "tag=" enthalten ist, ist es schon ein Affiliate-Link. Parameter könnte auch encodiert sein (war bei genialeangebote der Fall) 
              $bekannterlink = '1';
              } 
            if (stripos($link['url'], '.digistore24.')!==false AND (stripos($link['url'], 'promo.')!==false AND stripos($link['url'], 'aff=')!==false)){ // Wenn es ein Digistore-Link ist, ist er nur dann ein bekannter Aff-Link, wenn "promo." oder "aff=" darin vorkommt
              $bekannterlink = '1';
              }

          if ($bekannterlink) { // Bekannter Affiliate Link (nicht von wp2a)
              printf(__('static affiliate link (not managed by wp2a)', 'wp-2-affiliate'));
          } else if ($link['aff_code'] <> '' AND $link['nw_aktiv'] <> '') { // Aff-Code von wp2a vorhanden
              print '<span class="wp2ap-ok">';
              printf(__('active partnership (%s, %s, %s)', 'wp-2-affiliate'),
              $link['ap_name'], $link['nw_aktiv'], $link['aff_code']);              
              print '</span>';
          } else if ($link['aff_code'] == '' AND $link['ap_name'] <> '') { // Aff-Porgramm bekannt, aber kein Aff-Code hinterlegt
              print '<span class="wp2ap-warning">';
              printf(__('known program, but no active partnership', 'wp-2-affiliate'));              
              print '</span>';
          } else { // Sonst ist es ein unbekannter Links
              print '<span class="wp2ap-unnamed">';
              printf(__('unknown link', 'wp-2-affiliate'));              
              print '</span>';
              }
              
            ?>
                    <?php
                    //	echo $link['ap_name'] . $this->link_pattern;
                    ?>
				</td>
                
				<td><a href="#" class="wp2ap-stats-button"><?php 
						echo $link['hits'];
					?></a>
				</td>
				
            </tr>
            
            <?php

 	} // ende output_link_row
 
	function page_cloaked_links(){
		global $wpdb; /* @var wpdb $wpdb */
		
		// Berechtigungen prüfen
		if ( !current_user_can('edit_others_posts')) return;
		
		$message = '';
		$msgclass = '';
		
		$messages = array(
			1 => __('Link deleted.','wp-2-affiliate'),
			2 => __('An unexpected database error occured while trying to delete the link.', 'wp-2-affiliate'),
		);
		
		if ( isset($_GET['message']) && isset($messages[$_GET['message']]) ){
			$message = $messages[$_GET['message']];
		}
		if ( isset($_GET['deleted']) && ($_GET['deleted'] > 0) ){
			$deleted = intval($_GET['deleted']);
			$message = sprintf(
				_n(
					'%d link deleted.',
					'%d links deleted.',
					$deleted,
					'wp-2-affiliate'
				),
				$deleted
			);
			$msgclass = 'updated';
		}
		
		if ( isset($_GET['updated']) ) $msgclass .= ' updated ';
		if ( isset($_GET['error']) ) $msgclass .= ' error ';
		$msgclass = trim($msgclass);
		
		// Aktuelle URL herausfinden und spezielle Parameter herausfiltern
		$base_url = remove_query_arg( array('id', 'ids', '_wpnonce', 'noheader', 'updated', 'error', 'deleted', 'action', 'action2', 'message') );

		// Müssen wir die "Link hinzufügen" Form anzeigen?
		$add_link_form = isset($_GET['add_link_form']);
		// Standardwerte für die Felder
		$link_name = $link_url = '';
		$case_sensitive = false; 
		
		// WP dummheit entfernen / rückgängig machen
		$_POST = stripslashes_deep($_POST);
		
		$action = isset($_GET['action'])?$_GET['action']:(isset($_POST['action'])?$_POST['action']:'');
		if ( (empty($action) || ($action == '-1')) && !empty($_POST['action2']) ){
			$action = $_POST['action2'];
		}
		
		if ( $action == 'add-cloaked-link' ){
			check_admin_referer('add-cloaked-link') or die("Your request failed the security check");
		
			// Wenn wir dabei sind, einen neuen Link hinzuzufügen, zeigen wir die Form immer an
			$add_link_form = true;
		
			// Feld-Inhalte in lokale Variablen speichern und erste Checks durchführen
			$link_name = isset($_POST['link_name'])?$_POST['link_name']:'';
			$link_url = isset($_POST['link_url'])?$_POST['link_url']:'';
			
			//Der Name muss gesetzt sein wenn der Link manuell hinzugefügt wird (macht ja sonst keinen Sinn)
			if ( empty($link_name) ){
				$message = __("You must enter a name for the new link", 'wp-2-affiliate');
				$msgclass = 'error';
			} 
			else if	( 
				( ( $check = $this->is_valid_name($link_name) ) === true ) &&
				( ( $check = $this->is_valid_url($link_url) ) === true ) ) 
			{
				// Alles ok, Link speichern
				if ( $this->save_link($link_url, $link_name ) != false ){
					$message = __("Link added", 'wp-2-affiliate');
					$msgclass = 'updated';
					$link_name = $link_url = '';
				} else {
					$message = sprintf(__("An unexpected error occured while adding the link to the database : %s", 'wp-2-affiliate'), $wpdb->last_error);
					$msgclass = 'error';
				}
			} else {
				// Bei der Überprüfung des Links ist was schief gelaufen (z. B. kein Name vorhanen) 
				$message = $check;
				$msgclass = 'error';
			}
			
		} else if ( $action == 'delete-cloaked-link' ){
			
			$link_id = $_GET['id'];
			check_admin_referer('delete-link_'.$link_id) or die("Your request failed the security check");
			
			if ( $this->delete_link( $link_id ) ){
				wp_redirect( add_query_arg( array('message' => 1, 'updated' => 1), $base_url ) );
			} else {
				wp_redirect( add_query_arg( array('message' => 2, 'error' => 1), $base_url ) );
			}
			die();
			
		} else if ( $action == 'bulk-delete' ){
			check_admin_referer('bulk-action') or die("Your request failed the security check");
			
			$selected_links = array();
			if ( !empty($_POST['links']) && is_array($_POST['links']) ){
				$selected_links = array_map('intval', $_POST['links']); // Was keine Zahl ist, wird geleert
				$selected_links = array_filter($selected_links);		// Leere einträge werden entfent
			}
			
			if ( count($selected_links) > 0 ){
				$deleted = $this->delete_link($selected_links);
				wp_redirect( add_query_arg( array('deleted' => $deleted, 'ids' => implode(',', $selected_links)), $base_url ) );
				die();
			}
		}
		
		// Wenn eine Anfrage mit "noheader" so weit gekommen ist, ohne verarbeitet zu werden, ist sie wahrscheinlich unbekannt oder ungültig. Also ignorieren wir sie und leiten zurück zur Linkliste 
		if ( !empty($_GET['noheader']) ){
			wp_redirect($base_url);
			die();
		}
		
		// Verfügbare Filter nach Link-Typ mit den entsprechenden WHERE Bedingungen
		$filters = array(
			'all' => array(
				'where_expr' => '1',
				'name' => __('All', 'wp-2-affiliate'),
				'heading' => __('Cloaked Links', 'wp-2-affiliate'),
				'heading_zero' => __('No links found', 'wp-2-affiliate'),
			 ), 
			 'named' => array(
				'where_expr' => 'name IS NOT NULL',
				'name' => __('Named', 'wp-2-affiliate'),
				'heading' => __('Cloaked Links', 'wp-2-affiliate'),
				'heading_zero' => __('Cloaked Links', 'wp-2-affiliate'),
			 ), 
			 
			'unnamed' => array(
				'where_expr' => 'name IS NULL',
				'name' => __('Unnamed', 'wp-2-affiliate'),
				'heading' => __('Cloaked Links', 'wp-2-affiliate'),
				'heading_zero' => __('Cloaked Links', 'wp-2-affiliate'),
			 ), 
			 
			 'aff_active' => array(
			 	'where_expr' => 'aff_code IS NOT NULL AND nw_aktiv IS NOT NULL',
				'name' => __('Affiliate Links', 'wp-2-affiliate'),
				'heading' => __('Cloaked Links', 'wp-2-affiliate'),
				'heading_zero' => __('Cloaked Links', 'wp-2-affiliate'),
			 ),
		);	
		
		$link_type = isset($_GET['link_type'])?$_GET['link_type']:'all';
		if ( !isset($filters[$link_type]) ){
			$link_type = 'all';
		}
		
		// WHERE Bedingungen für die Suche zusammenstellen (falls vorhanden)
		$search_expr = '';
		if ( !empty($_GET['s']) ){
			$search_query = like_escape($wpdb->escape($_GET['s']));
			$search_query = str_replace('*', '%', $search_query);
			$search_expr = 'AND 
				( 
					(links.url LIKE "%'.$search_query.'%") OR 
					(links.name LIKE "%'.$search_query.'%")
				)';
		}
		
		// Gewünschte Seitenzahl herausfinden (muss > 0 sein) 
		$page = isset($_GET['paged'])?intval($_GET['paged']):1;
		if ($page < 1) $page = 1;
		
		// Links pro Seite
		$per_page = $this->options['links_per_page'];
		
		// Definition der Tabellen spalten und der Sortiermöglichkeiten
		$columns = array( 
			'name' => array(
				'caption' => __('Name / Slug', 'wp-2-affiliate'),
				'sort_title' => __('Click to sort by Name', 'wp-2-affiliate'),
				'order_by' => 'name %s, url ASC',
				'url' => '',
				'icon' => '',
			  ), 
			'url' => array(
				'caption' => __('Destination URL', 'wp-2-affiliate'),
				'sort_title' => __('Click to sort by Destination URL', 'wp-2-affiliate'),
				'order_by' => 'url %s',
				'url' => '',
				'icon' => '',
			  ),
			'cloaked-url' => array(
				'caption' => __('Cloaked URL', 'wp-2-affiliate'),
				'sort_title' => __('Click to sort by Cloaked URL', 'wp-2-affiliate'),
				'order_by' => 'aff_code %s, url ASC',
				'url' => '',
				'icon' => '',
			  ), 
			'affstatus' => array(
				'caption' => __('Affiliate Status', 'wp-2-affiliate'),
				'sort_title' => __('Click to sort by Affiliate Status', 'wp-2-affiliate'),
				'order_by' => 'aff_code %s, url ASC',
				'url' => '',
				'icon' => '',
			  ), 
			'hits' => array(
				'caption' => __('Hits', 'wp-2-affiliate'),
				'sort_title' => __('Click to sort by Hits', 'wp-2-affiliate'),
				'order_by' => 'hits %s, name ASC, url ASC',
				'url' => '',
				'icon' => '',
			  ),
		);
		
		// Gültige Sortierrichtungen definieren
		$sort_directions = array( 'asc', 'desc' );
		
		if ( isset($_GET['sort_direction']) && in_array( $_GET['sort_direction'], $sort_directions ) ){
			$sort_direction = $_GET['sort_direction'];
		} else {
			$sort_direction = 'asc';
		}
		
		// Gewünschte Sortier-Spalte bekommen (Standard ist nach hits (descending) und dann nach Name (ascending))
		if ( isset($_GET['sort_column']) && isset( $columns[ $_GET['sort_column'] ] ) ){
			$sort_column = $_GET['sort_column'];
		} else {
			$sort_column = 'hits';
			$sort_direction = 'desc';
		}
		
		// Die ODER BY Bedingung entsprechend konstruieren
		$order_by = sprintf("ORDER BY {$columns[$sort_column]['order_by']}", $sort_direction);
		
		// Die Links für die Sortiermöglichkeiten konstruieren
		$sort_links = array();
		$icon_path = WP_PLUGIN_URL . '/' . basename(dirname(__FILE__)) . '/images/';
		foreach ( $columns as $column_name => $column_info ){
			
			$p = array(
				'sort_column' => $column_name, 
				'sort_direction' => 'asc',
			);
			$icon = '';
			
			// Besonderer Fall: Wenn wir nach hits sortieren, ist die Richtung standardmäßig descending
			if ( 'hits' == $column_name ){
				$p['sort_direction'] = 'desc';
			}
			// Wenn bereits nach dieser Spalte sortiert ist, ändern wir die Richtung
			if ( $sort_column == $column_name ) {
				$p['sort_direction'] = ( 'asc' == $sort_direction ?'desc':'asc' );
				$icon = ( 'asc' == $sort_direction ?'sort-down.png':'sort-up.png' );
			}
			if ( !empty($icon) ){
				$icon = '<img src="' . $icon_path . $icon . '" class="wp2ap-sort-icon" />';
			}
			
			$url = add_query_arg( $p, $base_url );
			
			$columns[$column_name]['url'] = $url;
			$columns[$column_name]['icon'] = $icon;
		}
		
		// Anzahl der Links berechnen (Hinweis: Die search query wird dabei nicht benutzt)
		foreach ($filters as $filter => $data){
			$filters[$filter]['count'] = $wpdb->get_var( 
				"SELECT COUNT(*)
			  FROM 
			  	{$wpdb->prefix}wp2ap_cloaked_links links LEFT JOIN {$wpdb->prefix}wp2ap_aff_links afflinks 
						ON links.url COLLATE latin1_general_ci LIKE CONCAT('%', afflinks.url_match ,'%') COLLATE latin1_general_ci

				WHERE ".$data['where_expr'] );	
		}
		$current_filter = $filters[$link_type];
		
		// Links holen, die wir anzeigen wollen
    // Wir müssen die Sortierung COLLATE angeben, damit der Join klappt
		$q = "SELECT SQL_CALC_FOUND_ROWS
				links.*, afflinks.ap_name, afflinks.aff_code, afflinks.nw_aktiv
		 
			  FROM 
			  	{$wpdb->prefix}wp2ap_cloaked_links links LEFT JOIN {$wpdb->prefix}wp2ap_aff_links afflinks 
						ON links.url COLLATE latin1_general_ci LIKE CONCAT('%', afflinks.url_match ,'%') COLLATE latin1_general_ci
			  	
			  WHERE 
			  	{$current_filter['where_expr']}
			  	$search_expr
			  	
			  $order_by
			  
			  LIMIT ".( ($page-1) * $per_page ).", $per_page";
			  
		$links = $wpdb->get_results($q, ARRAY_A);
		
		// Anzahl der gefundenen Links
		$total_results = intval( $wpdb->get_var("SELECT FOUND_ROWS()") );
		$max_pages = ceil( $total_results / $per_page );
		
		if ( $wpdb->last_error ){
			$message .= '<p>'. sprintf(__('Unable to list cloaked links. Database error : %s', 'wp-2-affiliate'), $wpdb->last_error) . '</p>';
			$msgclass = 'error';
		}
		?>
<script type='text/javascript'>
	var wp2ap_current_filter = '<?php echo esc_js($link_type); ?>';
	var wp2ap_update_link_nonce = '<?php echo esc_js(wp_create_nonce('wp2ap_update_link')); ?>';
	var wp2ap_optional_field_text = '<?php _e('(optional)', 'wp-2-affiliate'); ?>';
	var wp2ap_bulk_delete_warning = '<?php echo esc_js(__("You are about to delete the selected links.\n'Cancel' to stop, 'OK' to delete.", 'wp-2-affiliate')); ?>';
</script>
<div class="wrap">
<h2><?php
	// Überschrift ausgeben, passend zum aktuellen Filter
	if ( $current_filter['count'] > 0 ){
		echo $current_filter['heading'];
	} else {
		echo $current_filter['heading_zero'];
	}
	
?> <a href="#" class="button add-new-h2" id="wp2ap-add-new"><?php _e('Add New Link', 'wp-2-affiliate'); ?></a>
<?php
if ( !empty($_GET['s']) )
	printf( '<span class="subtitle">' . __('Search results for &#8220;%s&#8221;', 'wp-2-affiliate') . '</span>', htmlentities( $_GET['s'] ) ); ?>
</h2>

<?php

	// Fehlermeldung anzeigen, falls vorhanden
	if ( !empty($message) ){
		echo '<div id="message" class="', $msgclass ,' fade"><p>',$message,'</p></div>';
	}

	// Form zum hinzufügen eines Links anzeigen
	if (current_user_can('edit_others_posts')) {
	
	/*
	Die Form ist Standardmäßig ausgeblendet. Sie wird nur angezeigt wenn: 
		* Der "Add New Link" button wurde geklickt;
		* Es wurde gerade ein Link hinzugefügt (es soll vielleicht ein weiterer folgen)
		* Es ist ein Fehler beim hinzufügen des Links aufgetreten. In diesem Fall werden die Werte vorbelegt.
	*/ 
?>
<form method="POST" name="form-add-cloaked-link" id="form-add-cloaked-link"
	class="stuffbox wp2ap-form <?php if ( !$add_link_form ) echo ' hidden'; ?>">
<?php
	wp_nonce_field('add-cloaked-link');
?>
<input type="hidden" name="action" id="action" value="add-cloaked-link" />
<table width="100%"><tbody>

	<tr class="inline-edit-row"><td colspan="2">
	<div>
	<fieldset>
	
	<h4><?php _e('Add Cloaked Link', 'wp-2-affiliate'); ?></h4>
	
	<label>
		<span class="title wp2ap-title"><?php _e('URL', 'wp-2-affiliate'); ?></span>
		<span class="input-text-wrap"><input type="text" name="link_url" value="<?php
			echo esc_attr($link_url);
		?>" /></span>
	</label>
  
	<label>
		<span class="title wp2ap-title"><?php _e('Name', 'wp-2-affiliate'); ?></span>
		<span class="input-text-wrap"><input type="text" name="link_name" class="ptitle" value="<?php
			echo esc_attr($link_name);
		?>" /></span>
	</label>
	
	</fieldset>
	
	<p class="submit">
		<a accesskey="c" href="#" title="<?php echo esc_attr(__('Cancel', 'wp-2-affiliate')); ?>" class="button-secondary cancel alignleft"><?php _e('Cancel', 'wp-2-affiliate'); ?></a>
		<a accesskey="s" href="#" title="<?php echo esc_attr(__('Save', 'wp-2-affiliate')); ?>" class="button-primary save alignright"><?php _e('Add Link', 'wp-2-affiliate'); ?></a>
		<br class="clear" />
	</p>
	</div>
	</td></tr>
</tbody></table></form>

<?php
	} // Ende der Form zum hinzufügen von Links
?>
	<ul class="subsubsub">
    	<?php
    		// Submenu für Filter-Typen konstruieren
    		$items = array();
			foreach ($filters as $filter => $data){
				$class = $number_class = '';
				
				if ( $link_type == $filter ) $class = 'class="current"';
				
				$items[] = "<li><a href='admin.php?page=wp2affiliate_cloaked_links&link_type=$filter' $class>
					{$data['name']}</a> <span class='count'>({$data['count']})</span>";
			}
			echo implode(' |</li>', $items);
			unset($items);
		?>
	</ul>
	
	<form name="form-search-links" id="form-search-links" method="get" action="<?php 
		echo esc_attr( admin_url('admin.php?page=wp2affiliate_cloaked_links') ); 
	?>">
	<?php
		// Verstreckte Felder hinzufügen, die für die Query-Parameter gebraucht werden
		$important_params = array('page', 'link_type', 'per_page', 'sort_direction', 'sort_column');
		foreach($important_params as $param_name){
			if ( isset($_GET[$param_name]) ){
				printf('<input type="hidden" name="%s" value="%s" />', esc_attr($param_name), esc_attr($_GET[$param_name]));
			}
		}		
	?>
	<p class="search-box">
		<label class="screen-reader-text" for="wp2ap-link-search-input"><?php _e('Search Links:', 'wp-2-affiliate'); ?></label>
		<input type="text" id="wp2ap-link-search-input" name="s" value="<?php
			if ( !empty($_GET['s']) ) echo htmlentities($_GET['s']); 
		?>" />
		<input type="submit" value="<?php _e('Search Links', 'wp-2-affiliate'); ?>" class="button" />
	</p>
	</form>

<form action="<?php echo esc_url( add_query_arg('noheader', 1, $base_url) ); ?>" method="post" id="wp2a-bulk-action-form">
	<?php 
	wp_nonce_field('bulk-action');
	
	if ( count($links) > 0 ) { 
	?>

	<div class='tablenav'>
		<div class="alignleft actions">
			<select name="action" id="bulk-action">
				<option value="-1" selected="selected"><?php _e('Bulk Actions'); ?></option>
				<option value="bulk-delete"><?php _e('Delete', 'wp-2-affiliate'); ?></option>
			</select>
			<input type="submit" name="doaction" id="doaction" value="<?php echo esc_attr(__('Apply')); ?>" class="button-secondary action" />
		</div>
		<?php
			// Links zum Blättern anzeigen 
			$page_links = paginate_links( array(
				'base' => add_query_arg( 'paged', '%#%', $base_url ),
				'format' => '',
				'prev_text' => __('&laquo;', 'wp-2-affiliate'),
				'next_text' => __('&raquo;', 'wp-2-affiliate'),
				'total' => $max_pages,
				'current' => $page
			));
			
			if ( $page_links ) { 
				echo '<div class="tablenav-pages">';
				$page_links_text = sprintf( '<span class="displaying-num">' . __( 'Displaying %s&#8211;%s of %s', 'wp-2-affiliate' ) . '</span>%s',
					number_format_i18n( ( $page - 1 ) * $per_page + 1 ),
					number_format_i18n( min( $page * $per_page, $total_results ) ),
					number_format_i18n( $total_results ),
					$page_links
				); 
				echo $page_links_text; 
				echo '</div>';
			}
		?>
	
	</div>
		
<table class="widefat" id='wp2a_links'>
	<thead>
	<tr>
		<th scope="col" id="cb" class="column-cb check-column">
			<input type="checkbox" />
		</th>
	<?php
		$visible_columns = array('name', 'url', 'cloaked-url', 'affstatus', 'hits');
		
		foreach($visible_columns as $column_name){
    if ($column_name == 'cloaked-url' ){ // Nach der Cloaked-URL soll man nicht sortieren können
			printf(
				'<th scope="col" class="column-%s"> %s</th>',
				$column_name,
				$columns[$column_name]['caption'] 
        );   
    } else {
			printf(
				'<th scope="col" class="column-%s"><a href="%s" title="%s">%s</a>%s</th>',
				$column_name,
				$columns[$column_name]['url'],
				$columns[$column_name]['sort_title'],
				$columns[$column_name]['caption'],
				$columns[$column_name]['icon']
			);
      }
		}
	?>
	
	</tr>
	</thead>
	<tbody id="the-list">		
<?php
		$rowclass = ''; 
        foreach ($links as $link) {
            $rowclass = 'alternate' == $rowclass ? '' : 'alternate';
            $this->output_link_row( $link, $rowclass, $base_url );
        }
?>
	</tbody>
</table>

	<div class='tablenav'>
		<div class="alignleft actions">
			<select name="action2" id="bulk-action2">
				<option value="-1" selected="selected"><?php _e('Bulk Actions'); ?></option>
				<option value="bulk-delete"><?php _e('Delete', 'wp-2-affiliate'); ?></option>
			</select>
			<input type="submit" name="doaction2" id="doaction2" value="<?php echo esc_attr(__('Apply')); ?>" class="button-secondary action" />
		</div>
	
<?php 
		// Die Blätter-Navigatio soll auch unten angezeigt werden
        if ( $page_links ) { 
			echo '<div class="tablenav-pages">';
			$page_links_text = sprintf( '<span class="displaying-num">' . __( 'Displaying %s&#8211;%s of %s', 'wp-2-affiliate' ) . '</span>%s',
				number_format_i18n( ( $page - 1 ) * $per_page + 1 ),
				number_format_i18n( min( $page * $per_page, $total_results ) ),
				number_format_i18n( $total_results ),
				$page_links
			); 
			echo $page_links_text; 
			echo '</div>';
		}
	}
?>
	</div>

</form>

<form method="get" action=""><table style="display: none" width='50%'><tbody id="wp2ap-inlineedit">

	<tr id="wp2ap-inline-edit" class="inline-edit-row quick-edit-row" style="display: none">
	<td></td>
	<td class="editor-container-cell">
	<div style='max-width:540px;'>
	<fieldset>
		
	<h4><?php _e('Edit Link', 'wp-2-affiliate'); ?></h4>
	
	<label>
			<span class="title"><?php _e('URL', 'wp-2-affiliate'); ?></span>
			<span class="input-text-wrap"><input type="text" name="link_url" value="" /></span>
	</label>
  
	<label>
		<span class="title"><?php _e('Name', 'wp-2-affiliate'); ?></span>
		<span class="input-text-wrap"><input type="text" name="link_name" class="ptitle" value="" /></span>
	</label>
	
	</fieldset>
	
	<p class="submit inline-edit-save">
		<a accesskey="c" href="#wp2ap-inline-edit" title="<?php echo esc_attr(__('Cancel', 'wp-2-affiliate')); ?>" class="button-secondary cancel alignleft"><?php 
			_e('Cancel', 'wp-2-affiliate'); 
		?></a>
		<a accesskey="s" href="#wp2ap-inline-edit" title="<?php echo esc_attr(__('Update', 'wp-2-affiliate')); ?>" class="button-primary save alignright"><?php 
			_e('Update Link', 'wp-2-affiliate'); 
		?></a>
			<img class="waiting" style="display:none;" src="images/wpspin_light.gif" alt="" />
		<br class="clear" />
	</p>
	</div>
	</td></tr>
	
</table></form>

</div>
		<?php
	}


// ######## Holger: Start Funktion output_afflink_row
	function output_afflink_row ( $link, $rowclass = '', $base_url = '' ){
	 	if ( empty($base_url) ){
			if ( !empty($_SERVER['HTTP_REFERER']) ){
				$base_url = $_SERVER['HTTP_REFERER'];
			} else {
				$base_url = 'admin.php?page=wp2affiliate_programs'; 
			}
		}
	 	?>
		<tr id='<?php echo "wp2ap-link-", $link['id']; ?>' class='wp2ap-row <?php echo $rowclass; ?>'>
            	
				<td class='post-title column-title'>
                	<span class='wp2ap-link-id' style='display:none;'><?php echo $link['id']; ?></span>
                <?php 
					if ( !empty( $link['ap_name'] ) ) {
						echo htmlspecialchars($link['ap_name']);
					} else {
						echo '<span class="wp2ap-unnamed">', __('(None)','wp-2-affiliate'),'</span>';
					} 
					
					// inline action links ausgeben (geklaut von edit-post-rows.php)                  	
                  	$actions = array();
					$actions['edit'] = '<span><a href="#" class="wp2ap-editinlineaff" title="' . esc_attr(__('Edit this link', 'wp-2-affiliate')) . '">' . __('Edit Partnership', 'wp-2-affiliate') . '</a>';
					echo '<div class="row-actions">', implode(' | </span>', $actions), '</div>';
					
					echo '<div class="hidden" id="inline_', $link['id'] ,'">',
						 '<div class="ap_name">', $link['ap_name'] , '</div>',
						 '<div class="link_url">', $link['url'] , '</div>',
						 '<div class="nw_aktiv">', $link['nw_aktiv'] , '</div>',
						 '<div class="netzwerke">', $link['netzwerke'] , '</div>',
						 '<div class="aff_code">', $link['aff_code'] , '</div>',
						 '</div>';
				?>
                </td>
                
				<td class='column-url'>
                	<a href='<?php print $link['url']; ?>' target='_blank' class='wp2ap-link-url'>
                    	<?php print $this->mytruncate($link['url']); ?></a>

				</td>

				<td class='column-ap_cat'>
                    	<?php print $this->mytruncate($link['ap_cat']); ?>

				</td>
				
				<td>
                	<a href='<?php print $link['nw_link']; ?>' target='_blank' class='wp2ap-link-url'>
                    	<?php print __('Network Link','wp-2-affiliate'); ?></a>
				</td>

				<td>
            <?php if ($this->mytruncate($link['nw_aktiv']) == ''){
                  print '<span class="wp2ap-unnamed">'.__('(inactive)','wp-2-affiliate').'</span>';
                  } else {
                  print $this->mytruncate($link['nw_aktiv']);
                  }; 
					       echo '<div class="row-actions">', implode(' | </span>', $actions), '</div>';?>
				</td> 
        
        <td>
            <?php if ($this->mytruncate($link['aff_code']) == ''){
                  print '<span class="wp2ap-unnamed">'.__('(inactive)','wp-2-affiliate').'</span>';
                  } else {
                  print $this->mytruncate($link['aff_code']);
                  };?>
				</td>                 
				
            </tr>
            
            <?php

 	} // ######## Holger: Ende Funktion output_afflink_row

// ######### Holger: Start Funktion page_aff_links
	function page_aff_links(){ 
		global $wpdb; /* @var wpdb $wpdb */
		
		// Berechtigungen prüfen
		if ( !current_user_can('edit_others_posts')) return;
		
		$message = '';
		$msgclass = '';
		
		$messages = array(
			1 => __('Link deleted.','wp-2-affiliate'),
			2 => __('An unexpected database error occured while trying to delete the link.', 'wp-2-affiliate'),
		);
		
		if ( isset($_GET['message']) && isset($messages[$_GET['message']]) ){
			$message = $messages[$_GET['message']];
		}
		
		if ( isset($_GET['updated']) ) $msgclass .= ' updated ';
		if ( isset($_GET['error']) ) $msgclass .= ' error ';
		$msgclass = trim($msgclass);
		
		// aktuelle URL herausfinden und spezielle Paramater entfernen
		$base_url = remove_query_arg( array('id', 'ids', '_wpnonce', 'noheader', 'updated', 'error', 'deleted', 'action', 'action2', 'message') );
		
		// WP Dummheit entfernen / rückgängig machen
		$_POST = stripslashes_deep($_POST);
		
		$action = isset($_GET['action'])?$_GET['action']:(isset($_POST['action'])?$_POST['action']:'');
		if ( (empty($action) || ($action == '-1')) && !empty($_POST['action2']) ){
			$action = $_POST['action2'];
		}
		
		// Wenn eine Anfrage mit "noheader" so weit gekommen ist, ohne verarbeitet zu werden, ist sie wahrscheinlich unbekannt oder ungültig. Also ignorieren wir sie und leiten zurück zur Linkliste		if ( !empty($_GET['noheader']) ){
    if ( !empty($_GET['noheader']) ){
			wp_redirect($base_url);
			die();
		}
		
		// Verfügbare Filter nach Link-Typ mit den entsprechenden WHERE Bedingungen
		$filters = array(
			'all' => array(
				'where_expr' => '1',
				'name' => __('All', 'wp-2-affiliate'),
				'heading' => __('Affiliate Programs / Partnerships', 'wp-2-affiliate'),
				'heading_zero' => __('No Affiliate Partnerships found', 'wp-2-affiliate'),
			 ), 
			 'active' => array( // Todo: Array-Namen ändern
				'where_expr' => 'aff_code <> ""',
				'name' => __('Active', 'wp-2-affiliate'),
				'heading' => __('Affiliate Partnerships (active)', 'wp-2-affiliate'),
				'heading_zero' => __('No active Affiliate Partnerships found', 'wp-2-affiliate'),
			 ), 
			 
			'inactive' => array( // Todo: Array-Namen ändern
				'where_expr' => 'aff_code = ""',
				'name' => __('Inactive', 'wp-2-affiliate'),
				'heading' => __('Affiliate Partnerships (inactive)', 'wp-2-affiliate'),
				'heading_zero' => __('No inactive Affiliate Partnerships found', 'wp-2-affiliate'),
			 ), 
		);	
		
		$link_type = isset($_GET['link_type'])?$_GET['link_type']:'all';
		if ( !isset($filters[$link_type]) ){
			$link_type = 'all';
		}
		
		// WHERE Bedingungen für die Suche zusammenstellen (falls vorhanden)
		$search_expr = '';
		if ( !empty($_GET['s']) ){
			$search_query = like_escape($wpdb->escape($_GET['s']));
			$search_query = str_replace('*', '%', $search_query);
			$search_expr = 'AND 
				( 
					(ap_name LIKE "%'.$search_query.'%") OR 
					(url LIKE "%'.$search_query.'%") OR
					(aff_code LIKE "%'.$search_query.'%")
				)';
		}
		
		// Gewünschte Seitenzahl herausfinden (muss > 0 sein) 
		$page = isset($_GET['paged'])?intval($_GET['paged']):1;
		if ($page < 1) $page = 1;
		
		// Anzahl Links pro Seite
		$per_page = $this->options['links_per_page'];
		
		// Definition der Tabellen spalten und der Sortiermöglichkeiten
		$columns = array( 
			'ap_name' => array(
				'caption' => __('Affiliate Program', 'wp-2-affiliate'),
				'sort_title' => __('Click to sort by this column', 'wp-2-affiliate'),
				'order_by' => 'ap_name %s, url ASC',
				'url' => '',
				'icon' => '',
			  ), 
			'url' => array(
				'caption' => __('Main URL', 'wp-2-affiliate'),
				'sort_title' => __('Click to sort by this column', 'wp-2-affiliate'),
				'order_by' => 'url %s',
				'url' => '',
				'icon' => '',
			  ),
			'ap_cat' => array(
				'caption' => __('Category', 'wp-2-affiliate'),
				'sort_title' => __('Click to sort by this column', 'wp-2-affiliate'),
				'order_by' => 'ap_cat %s',
				'url' => '',
				'icon' => '',
			  ),
			'nw_link' => array(
				'caption' => __('Network Link', 'wp-2-affiliate'),
				'sort_title' => __('Click to sort by this column', 'wp-2-affiliate'),
				'order_by' => 'nw_link %s',
				'url' => '',
				'icon' => '',
			  ),
			'nw_aktiv' => array(
				'caption' => __('Active Network', 'wp-2-affiliate'),
				'sort_title' => __('Click to sort by this column', 'wp-2-affiliate'),
				'order_by' => 'nw_aktiv %s',
				'url' => '',
				'icon' => '',
			  ),
			'aff_code' => array(
				'caption' => __('Affiliate-Code', 'wp-2-affiliate'),
				'sort_title' => __('Click to sort by this column', 'wp-2-affiliate'),
				'order_by' => 'aff_code %s',
				'url' => '',
				'icon' => '',
			  ),
		);
		
		// Gültige Sortierrichtungen definieren
		$sort_directions = array( 'asc', 'desc' );
		
		if ( isset($_GET['sort_direction']) && in_array( $_GET['sort_direction'], $sort_directions ) ){
			$sort_direction = $_GET['sort_direction'];
		} else {
			$sort_direction = 'asc';
		}
		
		// Gewünschte Sortier-Spalte bekommen (Standard ist nach hits (descending) und dann nach Name (ascending))
		if ( isset($_GET['sort_column']) && isset( $columns[ $_GET['sort_column'] ] ) ){
			$sort_column = $_GET['sort_column'];
		} else {
			$sort_column = 'ap_name';
			$sort_direction = 'asc';
		}
		
		// Die Links für die Sortiermöglichkeiten konstruieren
		$order_by = sprintf("ORDER BY {$columns[$sort_column]['order_by']}", $sort_direction);
		
		// Die Links für die Sortiermöglichkeiten konstruieren
		$sort_links = array();
		$icon_path = WP_PLUGIN_URL . '/' . basename(dirname(__FILE__)) . '/images/';
		foreach ( $columns as $column_name => $column_info ){
			
			$p = array(
				'sort_column' => $column_name, 
				'sort_direction' => 'asc',
			);
			$icon = '';
			
			// Besonderer Fall: Wenn wir nach hits sortieren, ist die Richtung standardmäßig descending
			if ( 'hits' == $column_name ){
				$p['sort_direction'] = 'desc';
			}
			// Wenn bereits nach dieser Spalte sortiert ist, ändern wir die Richtung
			if ( $sort_column == $column_name ) {
				$p['sort_direction'] = ( 'asc' == $sort_direction ?'desc':'asc' );
				$icon = ( 'asc' == $sort_direction ?'sort-down.png':'sort-up.png' );
			}
			if ( !empty($icon) ){
				$icon = '<img src="' . $icon_path . $icon . '" class="wp2ap-sort-icon" />';
			}
			
			$url = add_query_arg( $p, $base_url );
			
			$columns[$column_name]['url'] = $url;
			$columns[$column_name]['icon'] = $icon;
		}
		
		// Anzahl der Links berechnen (Hinweis: Die search query wird dabei nicht benutzt)
		foreach ($filters as $filter => $data){
			$filters[$filter]['count'] = $wpdb->get_var( 
				"SELECT COUNT(*)
				FROM 
					{$wpdb->prefix}wp2ap_aff_links links 
				WHERE ".$data['where_expr'] );	
		}
		$current_filter = $filters[$link_type];
		
				
		// Hole die Aff-Links, die wir anzeigen wollen
		$q = "SELECT SQL_CALC_FOUND_ROWS
				links.*
		 
			  FROM 
			  	{$wpdb->prefix}wp2ap_aff_links links 

  		  WHERE 
			  	{$current_filter['where_expr']}
			  	$search_expr			  	
			  $order_by
			  
			  LIMIT ".( ($page-1) * $per_page ).", $per_page";
			  
		$links = $wpdb->get_results($q, ARRAY_A);
		
		// Hole die Anzahl gefundener Aff-Links
		$total_results = intval( $wpdb->get_var("SELECT FOUND_ROWS()") );
		$max_pages = ceil( $total_results / $per_page );
		
		if ( $wpdb->last_error ){
			$message .= '<p>'. sprintf(__('Unable to list cloaked links. Database error : %s', 'wp-2-affiliate'), $wpdb->last_error) . '</p>';
			$msgclass = 'error';
		}
		?>
<script type='text/javascript'>
	var wp2ap_current_filter = '<?php echo esc_js($link_type); ?>';
	var wp2ap_update_afflink_nonce = '<?php echo esc_js(wp_create_nonce('wp2ap_update_afflink')); ?>';
	var wp2ap_optional_field_text = '<?php _e('(optional)', 'wp-2-affiliate'); ?>';
	var wp2ap_bulk_delete_warning = '<?php echo esc_js(__("You are about to delete the selected links.\n'Cancel' to stop, 'OK' to delete.", 'wp-2-affiliate')); ?>';
</script>
<div class="wrap">
<h2><?php
	// Überschrift ausgeben, passend zum aktuellen Filter
	if ( $current_filter['count'] > 0 ){
		echo $current_filter['heading'];
	} else {
		echo $current_filter['heading_zero'];
	}
	
?>
<?php
if ( !empty($_GET['s']) )
	printf( '<span class="subtitle">' . __('Search results for &#8220;%s&#8221;', 'wp-2-affiliate') . '</span>', htmlentities( $_GET['s'] ) ); ?>
</h2>

<?php

	// Fehlermeldung anzeigen, falls vorhanden
	if ( !empty($message) ){
		echo '<div id="message" class="', $msgclass ,' fade"><p>',$message,'</p></div>';
	}

	?>
	<ul class="subsubsub">
    	<?php
    		// Submenu für Filter-Typen konstruieren
    		$items = array();
			foreach ($filters as $filter => $data){
				$class = $number_class = '';
				
				if ( $link_type == $filter ) $class = 'class="current"';
				
				$items[] = "<li><a href='admin.php?page=wp2affiliate_programs&link_type=$filter' $class>
					{$data['name']}</a> <span class='count'>({$data['count']})</span>";
			}
			echo implode(' |</li>', $items);
			unset($items);
		?>
	</ul>
	
	<form name="form-search-links" id="form-search-links" method="get" action="<?php 
		echo esc_attr( admin_url('admin.php?page=wp2affiliate_programs') ); 
	?>">
	<?php
		// Verstreckte Felder hinzufügen, die für die Query-Parameter gebraucht werden
		$important_params = array('page', 'link_type', 'per_page', 'sort_direction', 'sort_column');
		foreach($important_params as $param_name){
			if ( isset($_GET[$param_name]) ){
				printf('<input type="hidden" name="%s" value="%s" />', esc_attr($param_name), esc_attr($_GET[$param_name]));
			}
		}		
	?>
	<p class="search-box">
		<label class="screen-reader-text" for="wp2ap-link-search-input"><?php _e('Search Programs:', 'wp-2-affiliate'); ?></label>
		<input type="text" id="wp2ap-link-search-input" name="s" value="<?php
			if ( !empty($_GET['s']) ) echo htmlentities($_GET['s']); 
		?>" />
		<input type="submit" value="<?php _e('Search Programs', 'wp-2-affiliate'); ?>" class="button" />
	</p>
	</form>

<form action="<?php echo esc_url( add_query_arg('noheader', 1, $base_url) ); ?>" method="post" id="wp2a-bulk-action-form">
	<?php 
	wp_nonce_field('bulk-action');
  
	if ( count($links) > 0 ) { 
	?>

	<div class='tablenav'>

		<?php
			// Blätter-Navigation anzeigen 
			$page_links = paginate_links( array(
				'base' => add_query_arg( 'paged', '%#%', $base_url ),
				'format' => '',
				'prev_text' => __('&laquo;', 'wp-2-affiliate'),
				'next_text' => __('&raquo;', 'wp-2-affiliate'),
				'total' => $max_pages,
				'current' => $page
			));
			
			if ( $page_links ) { 
				echo '<div class="tablenav-pages">';
				$page_links_text = sprintf( '<span class="displaying-num">' . __( 'Displaying %s&#8211;%s of %s', 'wp-2-affiliate' ) . '</span>%s',
					number_format_i18n( ( $page - 1 ) * $per_page + 1 ),
					number_format_i18n( min( $page * $per_page, $total_results ) ),
					number_format_i18n( $total_results ),
					$page_links
				); 
				echo $page_links_text; 
				echo '</div>';
			}
		?>
	
	</div>
		
<table class="widefat" id='wp2a_links'>
	<thead>
	<tr>

	<?php
		$visible_columns = array('ap_name', 'url', 'ap_cat', 'nw_link', 'nw_aktiv', 'aff_code');
		
		foreach($visible_columns as $column_name){
			printf(
				'<th scope="col" class="column-%s"><a href="%s" title="%s">%s</a>%s</th>',
				$column_name,
				$columns[$column_name]['url'],
				$columns[$column_name]['sort_title'],
				$columns[$column_name]['caption'],
				$columns[$column_name]['icon']
			);
		}
	?>
	
	</tr>
	</thead>
	<tbody id="the-list">		
<?php
		$rowclass = ''; 
        foreach ($links as $link) {
            $rowclass = 'alternate' == $rowclass ? '' : 'alternate';
            $this->output_afflink_row( $link, $rowclass, $base_url );
        }
?>
	</tbody>
</table>

	<div class='tablenav'>
		<div class="alignleft actions">
			<select name="action2" id="bulk-action2">
				<option value="-1" selected="selected"><?php _e('Bulk Actions'); ?></option>
				<option value="bulk-delete"><?php _e('Delete', 'wp-2-affiliate'); ?></option>
			</select>
			<input type="submit" name="doaction2" id="doaction2" value="<?php echo esc_attr(__('Apply')); ?>" class="button-secondary action" />
		</div>
	
<?php 
		// Die Blätter-Navigation soll auch unten angezeigt werden
        if ( $page_links ) { 
			echo '<div class="tablenav-pages">';
			$page_links_text = sprintf( '<span class="displaying-num">' . __( 'Displaying %s&#8211;%s of %s', 'wp-2-affiliate' ) . '</span>%s',
				number_format_i18n( ( $page - 1 ) * $per_page + 1 ),
				number_format_i18n( min( $page * $per_page, $total_results ) ),
				number_format_i18n( $total_results ),
				$page_links
			); 
			echo $page_links_text; 
			echo '</div>';
		}
	}
?>
	</div>

</form>

<form method="get" action=""><table style="display: none" width='50%'><tbody id="wp2ap-inlineeditaff">

	<tr id="wp2ap-inline-edit-aff" class="inline-edit-row quick-edit-row" style="display: none">

	<td class="editor-container-cell">
	<div style='max-width:270px;'>
	<fieldset>
		
	<h4><?php _e('Edit Partnership', 'wp-2-affiliate'); ?></h4>
  
	<label>
		<span class="title"><?php _e('Name', 'wp-2-affiliate'); ?></span>
		<span class="input-text-wrap"><input type="text" name="ap_name" class="ptitle" value="" readonly/></span>
	</label>

	<label>
		<span class="title"><?php _e('URL', 'wp-2-affiliate'); ?></span>
		<span class="input-text-wrap"><input type="text" name="link_url" value="" readonly/></span>
	</label>

	<label>
		<span class="title"><?php _e('Network', 'wp-2-affiliate'); ?></span>
		<span class="input-text-wrap">
    <select name="nw_aktiv" id="nw_aktiv" onchange="enableTextbox()"">
        <?php // $arr = preg_split('/\s*\,\s*/', $this->netzwerkliste); // Nichtmehr nötig, jetzt haben wir ein fertiges Array in der variable
              array_unshift($this->netzwerkliste, ""); 
              foreach($this->netzwerkliste AS $a){
              echo "<option value='".$a."'>".$a."</option>";
              };?>
    </select>
    </span>
	</label>

	<label>
		<span class="title"><?php _e('Affiliate-Code', 'wp-2-affiliate'); ?></span>
		<span class="input-text-wrap"><input type="text" name="aff_code" id="aff_code" value="" readonly="true"/></span>
	</label>

<?php // Holger: Nur bei Zanox darf ein individueller Wert eingegeben werden
// Wird zanox ausgewählt, wird das Textfed aff_code freigegeben
// Beim wechsel der Dropdown-Box leeren wir außerdem das Textfeld, damit nicht der Falsche code angezeigt wird
; ?>
<script type = "text/javascript">
function enableTextbox() {
var val = document.getElementById("nw_aktiv").selectedIndex;
var op = document.getElementById("nw_aktiv").options;
if (op[val].text == 'zanox') { document.getElementById("aff_code").readOnly = false;
document.getElementById("aff_code").value = "";}
else { document.getElementById("aff_code").readOnly = true;
document.getElementById("aff_code").value = "";}
}
</script>  

	
	</fieldset>
	
	<p class="submit inline-edit-save">
		<a accesskey="c" href="#wp2ap-inline-edit-aff" title="<?php echo esc_attr(__('Cancel', 'wp-2-affiliate')); ?>" class="button-secondary cancel alignleft"><?php 
			_e('Cancel', 'wp-2-affiliate'); 
		?></a>
		<a accesskey="s" href="#wp2ap-inline-edit-aff" title="<?php echo esc_attr(__('Update Partnership', 'wp-2-affiliate')); ?>" class="button-primary save alignright"><?php 
			_e('Update Partnership', 'wp-2-affiliate'); 
		?></a>
			<img class="waiting" style="display:none;" src="images/wpspin_light.gif" alt="" />
		<br class="clear" />
	</p>
	</div>
	</td></tr>
	
</table></form>

</div>
		<?php
	} // ######### Holger: Ende Funktion page_aff_links

// TODO: Kann das folgende "screen_options" weg?	
	/**
	 * Generate the contents of the "Screen Options" panel on the "Cloaked Links" page.
	 * 
	 * @return string
	 */
	function screen_options(){
		// Anzahl Links pro Seite [1 - 500]
		$new_links_per_page = isset($_GET['per_page'])?$_GET['per_page']:(isset($_POST['wp2a_per_page'])?$_POST['wp2a_per_page']:0); 
		if ( $new_links_per_page ){
			$this->options['links_per_page'] = min(max(intval($new_links_per_page), 1), 500);
			$this->save_options();
		}
		
		$panel = '<h5>%s</h5>
			<div class="screen-options">
				<input type="text" class="screen-per-page" name="wp2a_per_page" id="wp2a_per_page" value="%d" />
				<label for="wp2a_per_page">%s</label>
				<input type="submit" class="button" value="%s">
			</div>';
		$panel = sprintf(
			$panel,
			__('Show on screen'),
			$this->options['links_per_page'],
			__('links', 'wp-2-affiliate'),
			__('Apply')
		);			
		
		return $panel;
	}
	
	function ajax_update_link(){
		global $wpdb; // Ohne diese Variable können wir keine querys machen
		
		if ( !current_user_can('edit_others_posts')) {
			_e("You don't have sufficient permissions to edit links!", 'wp-2-affiliate');
			die();
		}
		
		check_ajax_referer('wp2ap_update_link') or die("Your request failed the security check!");
		
		// WP-Dummheit entfernen / rückgängig machen
		$_POST = stripslashes_deep($_POST);
		
		if ( empty($_POST['link_id']) || ( intval($_POST['link_id']) <= 0 ) ){
			_e("Link ID not specified or invalid.", 'wp-2-affiliate');
			die();
		}
		$id = intval($_POST['link_id']);
		
		$name = isset($_POST['link_name'])?trim($_POST['link_name']):'';
		$url = isset($_POST['link_url'])?$_POST['link_url']:'';
		
		// URL überprüfen
		if ( ( $msg = $this->is_valid_url($url) ) !== true ){
			die($msg);
		}
		
		// Namen überprüfen
		if ( ( $msg = $this->is_valid_name($name, $id) ) !== true ){
			die($msg);
		}
		
		// Alles ok, Link speichern
		$q = "UPDATE {$wpdb->prefix}wp2ap_cloaked_links SET url = '".$wpdb->escape($url)."', ";
		// Wenn der Name leer ist, setzen wir ihn auf NULL
		if (empty($name)) { 
			$q .= "name = NULL ";			
		} else {
			$q .= "name = '" . $wpdb->escape($name) . "' ";
		}
		$q .= "WHERE id = $id";
		
		if ( $wpdb->query( $q ) !== false ){
			
			if ( $wpdb->query( $q ) !== false ){
				
				// Aktualisierten link row ausgeben
				$q = 
					"SELECT links.*, afflinks.ap_name, afflinks.aff_code, afflinks.nw_aktiv 
					 FROM 
           	{$wpdb->prefix}wp2ap_cloaked_links links LEFT JOIN {$wpdb->prefix}wp2ap_aff_links afflinks 
						ON links.url COLLATE latin1_general_ci LIKE CONCAT('%', afflinks.url_match ,'%') COLLATE latin1_general_ci
					 WHERE links.id = '".$id."'"
				;
				$this->output_link_row( $wpdb->get_row( $q, ARRAY_A ) );
				
			} else {
				
				printf( __("Database error : %s", 'wp-2-affiliate'), $wpdb->last_error);
				
			}					
			
		} else {
			printf( __("Database error : %s", 'wp-2-affiliate'), $wpdb->last_error);
		};
				
		die();
	}

// ########## Holger: Start Funktion ajax_update_afflink
	function ajax_update_afflink(){
		global $wpdb; // Ohne diese Variable können wir keine querys machen
		
		if ( !current_user_can('edit_others_posts')) {
			_e("You don't have sufficient permissions to edit partnerships!", 'wp-2-affiliate');
			die();
		}
		
		check_ajax_referer('wp2ap_update_afflink') or die("Your request failed the security check!");
		
		// WP-Dummheit entfernen / rückgängig machen
		$_POST = stripslashes_deep($_POST);
		
		if ( empty($_POST['link_id']) || ( intval($_POST['link_id']) <= 0 ) ){
			_e("Link ID not specified or invalid.", 'wp-2-affiliate');
			die();
		}
		$id = intval($_POST['link_id']);

		$nw_aktiv = isset($_POST['nw_aktiv'])?trim($_POST['nw_aktiv']):'';

		$aff_code = isset($_POST['aff_code'])?trim($_POST['aff_code']):'';
		
		$name = isset($_POST['link_name'])?trim($_POST['link_name']):'';
		$url = isset($_POST['link_url'])?$_POST['link_url']:'';
			
		// Alles ok, Aff-Link speichern
		$q = "UPDATE {$wpdb->prefix}wp2ap_aff_links SET nw_aktiv = '".$wpdb->escape($nw_aktiv)."', " .
    		  '';
    
    if ($nw_aktiv == "zanox" AND $aff_code == '') { // Falls es Zanox ist, darf Aff-Code nicht leer sein
      printf( __("Error! For zanox you have to enter a individual affiliate code!", 'wp-2-affiliate'));
      die;
    } else if ($nw_aktiv == "zanox") { // Nur bei Zanox speichern wir einen individuellen Code
			$q .= "aff_code = '" . $wpdb->escape($aff_code) . "' ";			
	//	} else if ($nw_aktiv == ""){ // Wenn das Netzwerk leer ist, leeren wir auch den Aff-Code (sonst bleibt womöglich ein unpassender stehen)
	//		$q .= "aff_code = '' ";
		} else { // Wenn es irgendein anderes Netzwerk als Zanox ist, müssen wir den Code aus den Options laden
      $q .= "aff_code = '" . $this->options[$nw_aktiv] . "' ";
    }
    
    
    $q .= "WHERE id = $id";
    
	//	$q .= "UPDATE {$wpdb->prefix}wp2ap_aff_links SET aff_code = '".$wpdb->escape($aff_code)."' ";
	//	$q .= "WHERE id = $id";
		
		if ( $wpdb->query( $q ) !== false ){
			
			if ( $wpdb->query( $q ) !== false ){
				
				// Aktualisierten aff link row ausgeben
				$q = $wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}wp2ap_aff_links links 
					 WHERE links.id = %d ", 
					$id
				);
				$this->output_afflink_row( $wpdb->get_row( $q, ARRAY_A ) );
				
			} else {
				
				printf( __("Database error : %s", 'wp-2-affiliate'), $wpdb->last_error);
				
			}					
			
		} else {
			printf( __("Database error : %s", 'wp-2-affiliate'), $wpdb->last_error);
		};
    
   // echo "test";
				
		die();
	} // ######### Holger: Ende Funktion ajax_update_afflink
	
	
  /**
   * Wp2Affiliate::is_valid_url()
   * Einfache URL-Überprüfung. Prüft ob die URL nicht leer ist, das Protokoll enthält (http:// oder https://) und einen Host enthält   
   *
   * @param string $url
   * @return bool TRUE bei Erfolg, oder eine Fehlermeldung die das Problem mit der URL beschreibt.
   */
	function is_valid_url( $url ){
		//"Validate" the URL
		if ( empty($url) || ( !is_string($url) ) ){
			return __("Please enter an URL", 'wp-2-affiliate');
		}
		
		$parts = parse_url($url);
		if ( !$parts || empty($parts['host']) || empty($parts['scheme']) ){
			return __('Please provide a valid URL, e.g "http://beispiel.de/" (sans quotes)', 'wp-2-affiliate');
		}
		
		return true;
	}
	
  /**
   * Wp2Affiliate::is_valid_name()
   * Überprüft den Link-Namen. Der Name drf Buchstaben, Zahlen, Unterstriche und Striche enthalten. Er darf nicht ausschließlich aus Zahlen bestehen. Der Name kann auch leer sein (leerer String).   
   *    
   * @param string $name
   * @param int $id (optional) Die Datenbank ID des Links, der geprüft werden soll
   * @return bool TRUE wenn der Name gültig ist, oder andernfalls eine Fehlermeldung.
   */
	function is_valid_name( $name, $id = 0 ){
		// Der Name darf nicht ausschließlich aus Zahlen bestehen
		if ( is_numeric( $name ) ){
			return __("The name must contain at least one letter or underscore!", 'wp-2-affiliate');
		}
				
		// Erlaubte zeichen: Zahlen, Buchstaben, Unterstriche, Striche und Punkte
		if ( !preg_match( '/^[a-zA-Z0-9_.\-]*$/', $name ) ){
			return __("The name can only contain letters, digits, dots, dashes and underscores.", 'wp-2-affiliate');
		}
		
		// Es dürfen nicht 2 Links den gleichen Namen haben
		if ( $this->is_name_conflict( $name, $id ) ){
			return sprintf(
				__("There is already another link with the name '%s'! Please enter a unique name.", 'wp-2-affiliate'),
				$name
			);
		}
		
		return true;
	}
	
  /**
   * Wp2Affiliate::is_name_conflict()
   * Prüft, ob ein anderer Link bereits den gleichen Namen hat. Leere Namen werden natürlich nicht als Konflikt gemeldet.
   *
   * @param string $new_name - Der Name zur Prüfung
   * @param integer $current_id (Optional) - Ignoriere den Link mit dieser ID
   * @return boolean - True wenn es einen Konflikt gibt, andernfalls false
   */
	function is_name_conflict( $new_name, $current_id = 0){
		global $wpdb;
		
		if ( empty($new_name) ) return false;
		
		$q = $wpdb->prepare("SELECT id FROM {$wpdb->prefix}wp2ap_cloaked_links WHERE name=%s AND id<>%d LIMIT 1", $new_name, $current_id);
		if ( $row = $wpdb->get_row( $q, ARRAY_A )  ){
			return true;
		} else {
			return false;
		}
	}
	


  /**
   * Wp2Affiliate::upgrade_database()
   * Datenbank-Tabellen erstellen oder updaten
   *
   * @return void
   */
	function upgrade_database(){
		global $wpdb; // ohne diese Variable können wir keine querys machen
		
		require_once (ABSPATH . 'wp-admin/includes/upgrade.php');
		
		//The main table
		$q = "
			CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wp2ap_cloaked_links (
			  id int(10) unsigned NOT NULL auto_increment,
			  `name` varchar(100) default NULL,
			  url text character set latin1 collate latin1_general_cs NOT NULL,
			  url_hash char(32) NOT NULL,
			  hits int(10) unsigned NOT NULL default '0',
			  PRIMARY KEY  (id),
			  KEY url_hash (url_hash),
			  KEY `name` (`name`)
			) DEFAULT CHARSET=latin1 COLLATE=latin1_general_ci;
		";
		// dbDelta können wir hier nicht nutzen, weil COLLATE dort nicht funktioniert 
		if ( $wpdb->query( $q ) === false ){
			error_log(sprintf('WP2A database error: %s', $wpdb->last_error));
			return;
		};  
    
		//Holger: Tabelle mit Aff-Links anlegen
		$q = "
			CREATE TABLE {$wpdb->prefix}wp2ap_aff_links (
			  url varchar(255) character set latin1 NOT NULL,
        url_match varchar(100) NOT NULL,
			  ap_name varchar(100) NOT NULL COLLATE latin1_general_ci,
			  aff_code varchar(100) NOT NULL,
			  ap_cat varchar(100) NOT NULL,
			  netzwerke varchar(255) NOT NULL,
		    nw_aktiv varchar(255) NOT NULL,
        nw_link	varchar(255) NOT NULL,
			  id int(10) unsigned NOT NULL auto_increment,
			  PRIMARY KEY  (id),
        UNIQUE KEY (url),
        UNIQUE KEY (url_match)
			) DEFAULT CHARSET=latin1 COLLATE=latin1_general_ci;
		";
		dbDelta( $q );

    // Hier werden die Aff-Programme intsalliert / aktualisiert    
    require_once( 'install-aff-links.php');
     
	}
	
  /**
   * Wp2Affiliate::unhtmlentities()
   * Konvertiert HTML Zeichen. Funktioniert ähnlich wie html_entity_decode(), klappt aber besser auf PHP 4 systemen
   *
   * @param string $string
   * @return string
   */
	function unhtmlentities($string) {
	    // ersetzt Zahlen
	    $string = preg_replace('~&#x([0-9a-f]+);~ei', 'chr(hexdec("\\1"))', $string);
	    $string = preg_replace('~&#([0-9]+);~e', 'chr("\\1")', $string);
	    // ersetzt Buchstaben
	    $trans_tbl = get_html_translation_table(HTML_ENTITIES, ENT_QUOTES);
	    $trans_tbl = array_flip($trans_tbl);
	    return strtr($string, $trans_tbl);
	}
	
  /**
   * Wp2Affiliate::load_admin_css()
   * Lädt die CSS-Datei für die Admin-Seiten von WP2A
   *
   * @return void
   */
	function load_admin_css(){
		echo '<link type="text/css" rel="stylesheet" href="', plugin_dir_url(__FILE__) , 'styles/admin.css" />',"\n";
	}
	
  /**
   * Wp2Affiliate::load_admin_scripts()
   * Lädt die JS-Scripts für die Link-Seiten von WP2A (cloaked Links und Aff-Links)
   *
   * @return void
   */
	function load_admin_scripts(){
		//jQuery Example plugin
		wp_enqueue_script('jquery-example', plugin_dir_url(__FILE__) . 'js/jquery.example.js', array('jquery'));
		//Various event handlers for the forms on the "Cloaked Links" page
		wp_enqueue_script('wp2ap-cloaked-links-js', plugin_dir_url(__FILE__) . 'js/cloaked_links.js', array('jquery'), '1.2');
		wp_enqueue_script('wp2ap-aff-links-js', plugin_dir_url(__FILE__) . 'js/aff_links.js', array('jquery'), '1.2');
	}
	
  /**
   * Wp2Affiliate::load_admin_options_scripts()
   * Lädt die JS-Scripts für die Einstellungs-Seite von WP2A
   *
   * @return void
   */
	function load_admin_options_scripts(){
		//jQuery Tabs plugin
		wp_enqueue_script('jquery-ui-tabs');
		//Various UI scripts for the forms on the "wp2affiliate" page
		wp_enqueue_script('wp2ap-cloaked-links-js', plugin_dir_url(__FILE__) . 'js/settings.js', array('jquery'));
	}
	
  /**
   * Wp2Affiliate::print_ga_helper()
   * Gibt den JS-Code fürs GA-Tracking aus
   *
   * @return void
   */
	function print_ga_helper(){
		?>
		<script type="text/javascript">
			function wp2aRot13(text){
				return text.replace(/[a-zA-Z]/g, function(c){
					return String.fromCharCode((c <= "Z" ? 90 : 122) >= (c = c.charCodeAt(0) + 13) ? c : c - 26);
				});
			}
			function wp2aTrackPageview(url){
				url = wp2aRot13(url);
        ga('send','event','Cloaked Links WP2A', url);
			}
		</script>
		<?php
	}
	
  /**
   * Wp2Affiliate::send_nocache_headers()
   * Sendet HTTP headers die das Cachen der Weiterleitung im Browser verhindern sollen
   *
   * @return void
   */
	function send_nocache_headers(){
		header("Pragma: no-cache"); 
		header("Expires: Mon, 26 Jul 2001 05:00:00 GMT"); //Just a random date in the past.
		header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT"); 
		header("Cache-control: no-store, no-cache, must-revalidate");
	}
	
  /**
   * Wp2Affiliate::load_language()
   * Lädt die Sprachdatei der aktuellen Sprachversion
   *
   * @return void
   */
	function load_language(){
		load_plugin_textdomain( 'wp-2-affiliate', false, basename(dirname(__FILE__)) . '/lang' );
	}
	
	/**
	 * Erstellt die WP2A-Metabox auf Editor-Seiten hinzu (posts und pages)
	 * 
	 * @return void
	 */
	function register_metabox(){
  
  	if ( ! current_user_can('manage_options') ) { //Holger: Die Box wird nicht angezeigt, wenn der User keine Admin-Rechte hat
		return;
	}
  
		add_meta_box(
			'wp2a_metabox',  //HTML id
			__('WP2Affiliate', 'wp-2-affiliate'), //Title
			array(&$this, 'print_metabox'), //Callback
			'post', //Page type
			'side', //Preferred location
			'default', //Priority
			array('post_type' => 'post') //Additional arguments for the callback
		);
		
		add_meta_box(
			'wp2a_metabox', 
			__('WP2Affiliate', 'wp-2-affiliate'),
			array(&$this, 'print_metabox'),
			'page',
			'side',
			'default',
			array('post_type' => 'page')
		);
		
		// Registriert den Callback der die Werte der Metabox speichert. 
		add_action('save_post', array(&$this, 'save_metabox_data'));
	}
	
	/**
	 * Zeigt die WP2A-Metabox für post/page im Editor an.
	 * 
	 * @param object $post - Post/page data
	 * @param array $metabox - Metabox metadata
	 * @return void
	 */
	function print_metabox($post, $metabox){
		//Use nonce for verification
  		wp_nonce_field( plugin_basename(__FILE__), 'wp2a_metabox_nonce' );
  		
  		//Allow the user to disable cloaking for just this post/page
  		$disable_cloaking = get_post_meta($post->ID, '_wp2a_nocloak_page', true);
		echo '<label for="wp2a_nocloak_page">',
			 	'<input type="checkbox" id= "wp2a_nocloak_page" name="wp2a_nocloak_page"', ($disable_cloaking ? ' checked="checked"' : '') ,' /> ',
				__('Disable link cloaking for this post/page', 'wp-2-affiliate'),
			 '</label>';
	}
	
	/**
	 * Speichert die WP2A-Metabox Werte in custom fields. Das ist der callback für die 'save_post' action.
	 * 
	 * @param int $post_id
	 * @return void
	 */
	function save_metabox_data($post_id){
		// nonce feld prüfen (security). 
		if ( !isset($_POST['wp2a_metabox_nonce']) || !wp_verify_nonce( $_POST['wp2a_metabox_nonce'], plugin_basename(__FILE__) )) {
			return;
		}
		
		// Prüfen ob es nur die auto save routine ist. Falls unsere Form noch nicht submitted wurde, tun wir nichts 
		if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ){ 
			return;
		}
		
		// Berechtigungen prüfen (funktioniert evtl nicht für custom post types).
		if ( 'page' == $_POST['post_type'] ) {
			if ( !current_user_can( 'edit_page', $post_id ) )
			  return;
		} else {
			if ( !current_user_can( 'edit_post', $post_id ) )
			  return;
		}
		
		// Einstellungen speichern.
		$disable_cloaking = !empty($_POST['wp2a_nocloak_page']);
		update_post_meta($post_id, '_wp2a_nocloak_page', $disable_cloaking ? 1 : 0);
	}
	
	/**
	 * Fügt das <!--nowp2a-page--> hinzu, wenn das cloaking per Metabox deaktiviert wurde.
	 * 
	 * @param string $content - Aktueller post content.
	 * @return string - Geänderten post content.
	 */
	function maybe_nocloak_page($content){
		global $post;
		if ( !empty($post) && isset($post->ID) ){
			$disable_cloaking = get_post_meta($post->ID, '_wp2a_nocloak_page', true);
			if ( $disable_cloaking ){
				$content .= '<!--nowp2a-page-->';
			}
		}
		return $content;
	}

} // end class

} // end if class_exists()

$link_cloaker_pro = new Wp2Affiliate();

// Exclusive locks for WP
if ( !class_exists('WPMultiLock') ){
	
class WPMultiLock {
	/**
	 * Get an exclusive named lock.
	 * 
	 * @param string $name 
	 * @param integer $timeout 
	 * @param bool $network_wide 
	 * @return bool 
	 */
	static function acquire($name, $timeout = 0, $network_wide = false){
		global $wpdb; /* @var wpdb $wpdb */
		if ( !$network_wide ){
			$name = WPMultiLock::_get_private_name($name);
		}
		$state = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, $timeout));
		return $state == 1;
	}	
	
	/**
	 * Release a named lock.
	 * 
	 * @param string $name 
	 * @param bool $network_wide 
	 * @return bool
	 */
	static function release($name, $network_wide = false){
		global $wpdb; /* @var wpdb $wpdb */
		if ( !$network_wide ){
			$name = WPMultiLock::_get_private_name($name);
		}		
		$released = $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
		return $released == 1;
	}
	
	/**
	 * Given a generic lock name, create a new one that's unique to the current blog.
	 * 
	 * @access private
	 * 
	 * @param string $name
	 * @return string
	 */
	static function _get_private_name($name){
		global $current_blog;
		if ( function_exists('is_multisite') && is_multisite() && isset($current_blog->blog_id) ){
			$name .= '-blog-' . $current_blog->blog_id;
		}
		return $name;
	}
}
} // end WPMultiLock

?>