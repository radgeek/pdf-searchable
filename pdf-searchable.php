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

function get_pdf_pages_text ($file) {
	// Parse pdf file and build necessary objects.
	$parser = new \Smalot\PdfParser\Parser();

	$pdf = $parser->parseFile($file);
	
	// Retrieve all pages from the pdf file.
	$aoPages  = $pdf->getPages();

	$pp = array(
	"TOC" => null,
	"text" => array(),
	);
	$candidates = array();
	
	foreach ($aoPages as $i => $oPage) :
		$sText = $oPage->getText();
		
		// add in the page
		$pp['text'][] = $sText;
		
		// no TOC page identified yet
		if (is_null($pp['TOC'])) :
			// check for the phrase "Contents"
			if (preg_match('/Contents/i', $sText)) :
				$pp['TOC'] = ($i+1);
				
			// if we never find "Contents", just as such then
			// try doing something clever: count up the number
			// of numbers appearing on the page at the end of lines
			else :
				$candidates[$i] = preg_match_all('/[0-9]+\s*(\n|\r)/s', $sText);
			endif;
			
			if ($i > 7) :
				$max = 0;
				$max_idx = 5; // default to p. 6 if nothing else clicks
				foreach ($candidates as $idx => $length) :
					if ($length > $max) :
						$max = $length;
						$max_idx = $idx;
					endif;
				endforeach;
				
				$pp['TOC'] = ($max_idx+1);
			endif;
		endif;
	endforeach;
	
	return $pp;
} /* get_pdf_pages_text() */

add_filter('wp_insert_post_data', function ($data, $postarr) {

	$content = wp_unslash($data['post_content']);

	// is this an auto-save or similar?
	if ($postarr['post_type'] == 'revision') :
		// abort, abort, abort
		return $data;
	endif;
	
	// search text is auto-generated each time we save
	$parts = preg_split('/<!--searchtext(:[^-]+)?-->/', $content, 2);
	$mainContent = $parts[0];
	
	if (isset($parts[1]) and !is_null($parts[1])) :
		$searchContent = trim($parts[1]);
		
		if (strlen($searchContent) > 1) :
			return $data;
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
	
	if (mb_strlen($content) == 0) :
		return $data;
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
			// This link was produced by our shortcode. Grab the needed information
			// directly. id is in @data-attachment-id, href to the PDF is in @href, recopy classes
					
			// Already produced by our shortcode. Grab the needed information directly.
			$attachedPdfs[] = [
				"id" => $el->getAttribute('data-attachment-id'),
				"href" => $href,
				"class" => $aClass
			];
			
		elseif (in_array('attachment', $aRel)) :
			
			$attachment_id = url_to_postid($href);
			$pdfHref = wp_get_attachment_url($attachment_id);
			
			// When we have a raw HTML link to an attachment page, use
			// the URL of the attachment page to create our shortcode.
			$attachedPdfs[] = [
				"id" => $attachment_id,
				"href" => $pdfHref,
				"class" => ["attached-pdf", "attachment-id-".$attachment_id],
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
					"id" => $attachment_id,
					"href" => $attachment_url,
					"class" => ["attached-pdf", "attachment-id-".$attachment_id],
				];
			endif;
			
		endif;
		
	endforeach;

	foreach ($attachedPdfs as $pdf) :
		$attachment_id = $pdf['id'];
		
		// get the text of the PDF attachment's description, and see if it has already
		// been OCR-text indexed.
		$oAttachment = get_post($attachment_id);
		$sAttachmentText = $oAttachment->post_content;
		
		$bits = preg_split('/<!--searchtext(:[^-]+)?-->/', $sAttachmentText, 2);
		
		$sSearchText = (isset($bits[1]) ? $bits[1] : '');
		
		if (mb_strlen($sSearchText) < 1) :
			if ($attachment_id != 0) :
				$result = PDFSearchable::index_attachment($attachment_id, $bits[0], $oAttachment);
				$suffixHTML = $result['suffix'];
			else :
				$suffixHTML = '';
			endif;
		else :
			$suffixHTML = $sSearchText;
		endif;

	endforeach;
	
	$data['post_content'] = $mainContent . $suffixHTML;
	
	return $data;
	
}, 100, 2);

add_filter('wp_insert_attachment_data', function ($data, $postarr) {
	if (preg_match('|^application/pdf$|i', $postarr['post_mime_type'])) :
		$sAttachmentText = $postarr['post_content'];
		
		$bits = preg_split('/<!--searchtext(:[^-]+)?-->/', $sAttachmentText, 2);

		$sSearchText = (isset($bits[1]) ? $bits[1] : '');
		
		if (mb_strlen($sSearchText) < 1) :
			$result = PDFSearchable::index_attachment($postarr["ID"], $bits[0], (object) $postarr, /*do_update=*/ false);
			$data['post_content'] = $data['post_content'] . $result['infix'] . $result['suffix'];
		endif;
	endif;
	return $data;
}, 1000, 2);

class PDFSearchable {
	static function index_attachment ($id, $prefix, $obj, $do_update = true) {
		$file = get_attached_file($id);
		
		$infix = "\n<!--searchtext:0-->\n";
		$suffix = "";
		try {
			$pp = get_pdf_pages_text($file);
			foreach ($pp['text'] as $idx => $text) :
				$suffix .= "\n<!--searchtext:";
				if ($pp['TOC'] == ($idx+1)) :
					$suffix .= "TOC:";
				endif;
				$suffix .= $id . ":" . ($idx+1) . "-->\n";
				$suffix .= $text;
				$suffix .= "\n";
			endforeach;
		} catch (Exception $e) {
			// NOOP
		}

		$obj->post_content = $prefix . $infix . $suffix;
		
		$ret = array();
		$ret['infix'] = $infix;
		$ret['suffix'] = $suffix;
		
		if ($do_update) :
			// now let's write this back to the attachment
			$ret['result'] = wp_update_post($obj, /*wp_error=*/ true);
		endif;
		
		return $ret;
	} /* PDFSearchable::index_attachment () */
}
add_filter('the_content', function ($content) {
	// search text is auto-generated each time we save
	$parts = preg_split('/<!--searchtext/', $content);
	
	$content = array_shift($parts);

	foreach ($parts as $part) :
		if (preg_match('/^:TOC:[^-]+-->(.+)$/s', $part, $ref)) :
			$content .= "<!--TOC-->\n<div><h3>Contents</h3>\n" . $ref[1] . "</div>";
		endif;
	endforeach;
	
	return $content;
	
}, 10002, 1);
