<?php
/**
 * @package PDF_Searchable
 * @version 2017.0223
 */
/*
Plugin Name: PDF Searchable
Plugin URI: http://wordpress.org/plugins/pdf-searchable/
Description: This plugin makes attached searchable PDFs accessible to the WordPress search box.
Author: Charles Johnson
Version: 2017.0223
Author URI: http://projects.radgeek.com/
*/

$dir = dirname(__FILE__);
require($dir . '/pdfparser/vendor/autoload.php');

function get_pdf_text ($file) {
	// Parse pdf file and build necessary objects.
	$parser = new \Smalot\PdfParser\Parser();
	$pdf = $parser->parseFile($file);
		
	// Retrieve all pages from the pdf file.
	$text  = $pdf->getText();

	return $text;
}

add_filter('content_save_pre', function ($content) {
	
	// search text is auto-generated each time we save
	$parts = explode('<!--searchtext-->', $content, 2);
	$mainContent = $parts[0];
	
	if (isset($parts[1]) and !is_null($parts[1])) :
		$searchContent = trim($parts[1]);
		
		if (strlen($searchContent) > 1) :
			return $content;
		endif;
		
	endif;
	
	$content = do_shortcode(stripslashes($mainContent));
	
	global $wpdb;
	
	$footer = '';
	
	$mm = get_attached_media('application/pdf');

	$links = array();
	if (count($mm) > 0) :
		$found = preg_match_all('!https?://gchr\.dev\.radgeek\.net!', $content, $links);
		
		if ($found) :
			$content = preg_replace('!https?://gchr\.dev\.radgeek\.net!', get_option('siteurl'), $content);
		endif;
	endif;
	
	$oDoc = new DOMDocument;
	$oDoc->loadHTML($content);
	
	$attachedPdfs = array();

	$els = $oDoc->getElementsByTagName('a');
	$suffixHTML = '';
	foreach ($els as $el) :
		$href = $el->getAttribute('href');
		$aClass = explode(' ', $el->getAttribute('class'));
		$aRel = explode(' ', $el->getAttribute('rel'));
		if (in_array('attached-pdf', $aClass)) :
			// Already produced by our shortcode. Grab the needed information directly.
			$attachedPdfs[] = [
				"href" => $el->getAttribute('href'),
				"class" => $aClass
			];
			
		elseif (in_array('attachment', $aRel)) :
			// When we have a raw HTML link to an attachment page, use
			// the URL of the attachment page to create our shortcode.
			$attachedPdfs[] = [
				"href" => $href,
				"class" => ["attached-pdf", "attachment-id-".url_to_postid($href)],
			];
						
		elseif (preg_match('|/wp-content/uploads/(.*)$|', $href, $mm)) :
			// If it's a direct link to an uploaded file, try to use
			//the relative file path and slug to try to get the
			// corresponding attachment ID.
			$attachment = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_wp_attached_file' AND meta_value='%s';", $mm[1] )); 

			$attachment_id = null;
			if (count($attachment) > 0) :
				$attachment_id = $attachment[0];
			endif;
			
			if (!is_null($attachment_id)) :
				$attachment_url = 
				get_permalink($attachment_id);
				
				$attachedPdfs[] = [
					"href" => $attachment_url,
					"class" => ["attached-pdf", "attachment-id-".$attachment_id],
				];
			endif;
			
		endif;
		
	endforeach;

	foreach ($attachedPdfs as $pdf) :
		// Get the attachment ID from the classes on the a element
		$attachment_id = array_reduce($pdf['class'], function ($carry, $item) {
			return (preg_match('/^attachment-id-([0-9]+)$/', $item, $m) ? intval($m[1]) : $carry);
		});
		$file = get_attached_file($attachment_id);
		
		try {
			$suffixHTML .= "\n". $attachment_id . "\n" . get_pdf_text($file) . "\n";
		} catch (Exception $e) {
			// NOOP
		}
	endforeach;
	
	return $mainContent . "<!--searchtext-->\n" . esc_sql($suffixHTML);
	
}, -100, 1);

add_filter('the_content', function ($content) {
	// search text is auto-generated each time we save
	$parts = explode('<!--searchtext-->', $content, 2);
	$content = $parts[0];
	
if (isset($_REQUEST['dbg']) and $_REQUEST['dbg']=='pdf') :
	if (is_attachment()) :
		/*DBG*/ echo "HELLO WORLD! There's a song that we're singing.\n";
		/*DBG*/ $file = get_attached_file(get_the_ID());
		/*DBG*/ echo "<br>"; var_dump($file);
		/*DBG*/ echo "<plaintext>";
		/*DBG*/ echo "Come on, get happy!\n";
		
		$text = get_pdf_text($file);
 
		var_dump($text);
		
		/*DBG*/ exit;
	elseif (is_singular()) :
		$oDoc = new DOMDocument;
		$oDoc->loadHTML($content);
	
		$header = '';
	
		$els = $oDoc->getElementsByTagName('a');
		
		$attachedPdfs = array();
		foreach ($els as $el) :
			$href = $el->getAttribute('href');
			$aClass = explode(' ', $el->getAttribute('class'));
			$aRel = explode(' ', $el->getAttribute('rel'));
			
			if (in_array('attached-pdf', $aClass)) :
				$attachedPdfs[] = [
					"href" => $el->getAttribute('href'),
					"class" => $aClass
				];
			endif;
		endforeach;

		/*DBG*/ echo "WELL. <plaintext>";
		foreach ($attachedPdfs as $pdf) :
			// Get the attachment ID from the classes on the a element
			$attachment_id = array_reduce($pdf['class'], function ($carry, $item) {
				return (preg_match('/^attachment-id-([0-9]+)$/', $item, $m) ? intval($m[1]) : $carry);
			});
			$file = get_attached_file($attachment_id);
			var_dump($pdf); var_dump($attachment_id); var_dump($file); var_dump(get_attachment_link($attachment_id));
			
			$text = null;
			try {
				$text = get_pdf_text($file);
			} catch (Exception $e) {
				echo "nah.";
			}
			var_dump($text);
		endforeach;
		/*DBG*/ exit;
	else :
		/*DBG*/ echo "HUH.\n"; exit;
	endif;
endif;
	return $content;
	
}, 10002, 1);