<?php
/**
 * @author  PressBooks <code@pressbooks.org>
 * @license GPLv2 (or any later version)
 */
namespace PressBooks\Export\InDesign;


use PressBooks\Export\Export;

require_once( PB_PLUGIN_DIR . 'symbionts/htmLawed/htmLawed.php' );

class Icml extends Export {


	/**
	 * @param array $args
	 */
	function __construct( array $args ) {

		// Some defaults

		if ( ! defined( 'PB_XMLLINT_COMMAND' ) )
			define( 'PB_XMLLINT_COMMAND', '/usr/bin/xmllint' );
	}


	/**
	 * Create $this->outputPath
	 *
	 * @return bool
	 */
	function convert() {

		// Create ICML

		$vars = array(
			'meta' => \PressBooks\Book::getBookInformation(),
			'book_contents' => $this->preProcessBookContents( \PressBooks\Book::getBookContents() ),
		);
		$book_html = $this->loadTemplate( __DIR__ . '/templates/xhtml.php', $vars );
		$content = $this->transformXML( $book_html, PB_PLUGIN_DIR . 'symbionts/icml/tkbr2icml-v044.xsl' );

		// Save ICML as file in exports folder

		$filename = $this->timestampedFileName( '.icml' );
		file_put_contents( $filename, $content );
		$this->outputPath = $filename;

		if ( ! filesize( $this->outputPath ) ) {
			$this->logError( $this->bookHtmlError( $book_html ) );
			unlink( $this->outputPath );

			return false;
		}

		return true;

	}


	/**
	 * Check the sanity of $this->outputPath
	 *
	 * @return bool
	 */
	function validate() {

		if ( ! simplexml_load_file( $this->outputPath ) ) {

			$this->logError( 'ICML document is not well formed XML.' );

			return false;
		}

		return true;
	}


	/**
	 * Add $this->outputPath as additional log info, fallback to parent.
	 *
	 * @param string $message
	 */
	function logError( $message ) {

		$more_info = array(
			'path' => $this->outputPath,
		);

		parent::logError( $message, $more_info );
	}


	/**
	 * Log problems with $book_html that probably caused transformXML() to fail.
	 *
	 * @param $book_html
	 *
	 * @return string
	 */
	protected function bookHtmlError( $book_html ) {

		$message = "ICML conversion returned a file of zero bytes. \n";
		$message .= 'This usually happens when bad XHTML (I.e. $book_html) is passed to the XSL transform routine. ' . "\n";
		$message .= 'Analysis of $book_html follows: ' . "\n\n";

		$book_html_path = $this->createTmpFile();
		file_put_contents( $book_html_path, $book_html );

		// Xmllint params
		$command = PB_XMLLINT_COMMAND . ' --html --valid --noout ' . escapeshellcmd( $book_html_path ) . ' 2>&1';

		// Execute command
		$output = array();
		$return_var = 0;
		exec( $command, $output, $return_var );

		return $message . implode( "\n", $output );
	}


	// ----------------------------------------------------------------------------------------------------------------
	// Sanitize book
	// ----------------------------------------------------------------------------------------------------------------

	/**
	 * @param $book_contents
	 *
	 * @return mixed
	 */
	protected function preProcessBookContents( $book_contents ) {

		// We need to change global $id for shortcodes, the_content, ...
		global $id;
		$old_id = $id;

		// Do root level structures first.
		foreach ( $book_contents as $type => $struct ) {

			if ( preg_match( '/^__/', $type ) )
				continue; // Skip __magic keys

			foreach ( $struct as $i => $val ) {

				if ( isset( $val['post_content'] ) ) {
					$id = $val['ID'];
					$book_contents[$type][$i]['post_content'] = $this->preProcessPostContent( $val['post_content'] );
				}
				if ( isset( $val['post_title'] ) ) {
					$book_contents[$type][$i]['post_title'] = \PressBooks\Sanitize\sanitize_xml_attribute( $val['post_title'] );
				}
				if ( isset( $val['post_name'] ) ) {
					$book_contents[$type][$i]['post_name'] = $this->preProcessPostName( $val['post_name'] );
				}

				if ( 'part' == $type ) {

					// Do chapters, which are embedded in part structure
					foreach ( $book_contents[$type][$i]['chapters'] as $j => $val2 ) {

						if ( isset( $val2['post_content'] ) ) {
							$id = $val2['ID'];
							$book_contents[$type][$i]['chapters'][$j]['post_content'] = $this->preProcessPostContent( $val2['post_content'] );
						}
						if ( isset( $val2['post_title'] ) ) {
							$book_contents[$type][$i]['chapters'][$j]['post_title'] = \PressBooks\Sanitize\sanitize_xml_attribute( $val2['post_title'] );
						}
						if ( isset( $val2['post_name'] ) ) {
							$book_contents[$type][$i]['chapters'][$j]['post_name'] = $this->preProcessPostName( $val2['post_name'] );
						}

					}
				}
			}
		}

		$id = $old_id;
		return $book_contents;
	}


	/**
	 * @param string $content
	 *
	 * @return string
	 */
	protected function preProcessPostContent( $content ) {

		$content = apply_filters( 'the_content', $content );
		$content = $this->tidy( $content );

		return $content;
	}


	/**
	 * Tidy HTML
	 *
	 * @param string $html
	 *
	 * @return string
	 */
	protected function tidy( $html ) {

		// Make XHTML 1.1 strict using htmlLawed

		$config = array(
			'valid_xhtml' => 1,
			'hook' => '\PressBooks\Sanitize\html5_to_xhtml11',
			'tidy' => -1,
		);

		return htmLawed( $html, $config );
	}


}
