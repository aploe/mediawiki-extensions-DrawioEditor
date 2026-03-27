<?php

namespace MediaWiki\Extension\DrawioEditor;

use Html;
use MediaWiki\MediaWikiServices;
use Title;

class Hooks {
	/**
	 *
	 * @param mixed $oPDFServlet
	 * @param mixed $oImageElement
	 * @param string &$sAbsoluteFileSystemPath
	 * @param string &$sFileName
	 * @param string $sDirectory
	 * @return void
	 */
	public static function onBSUEModulePDFFindFiles(
		$oPDFServlet,
		$oImageElement,
		&$sAbsoluteFileSystemPath,
		&$sFileName,
		$sDirectory
	) {
		if ( $sDirectory !== 'images' ) {
			return true;
		}
		if ( strpos( $oImageElement->getAttribute( 'id' ), "drawio-img-" ) !== false ) {
			$style = $oImageElement->getAttribute( 'style' );
			$matches = [];
			preg_match( '#max-width:\s*(\d+)px#', $style, $matches );
			$maxWidth = isset( $matches[1] ) ? (int)$matches[1] : 0;
			if ( $maxWidth > 690 ) {
				$oImageElement->setAttribute( 'style', 'width: 99%; height: auto;' );
			} elseif ( $maxWidth > 0 ) {
				$oImageElement->setAttribute( 'style', 'width: ' . $maxWidth . 'px; height: auto;' );
			} else {
				$oImageElement->setAttribute( 'style', 'width: 100%; height: auto;' );
			}
		}
		return true;
	}

	/**
	 * Embeds CSS into pdf export
	 *
	 * @param array &$aTemplate
	 * @param array &$aStyleBlocks
	 * @return bool Always true to keep hook running
	 */
	public static function onBSUEModulePDFBeforeAddingStyleBlocks( &$aTemplate, &$aStyleBlocks ) {
		$css = [
			".bs-page-content .mw-editdrawio { display: none; } ",
			'[id^="drawio-img-"] { padding-top: 10px; }',
			'img[id^="drawio-img-"] { height: auto; }'
		];

		$aStyleBlocks['Drawio'] = implode( ' ', $css );

		return true;
	}

	// /**
	//  *
	//  * @param mixed $oImagePage
	//  * @param string &$sHtml
	//  * @return void
	//  */
	// public static function onImagePageAfterImageLinks( $oImagePage, &$sHtml ) {
	// 	$oTitle = $oImagePage->getTitle();
	// 	$sFileName = $oTitle->getText();
	// 	if ( strpos( $sFileName, '.drawio.' ) === false ) {
	// 		return true;
	// 	}
	// 	// $sFileName = str_replace( '.drawio.' . $wgDrawioEditorImageType, '', $sFileName );
	// 	$sFileName = str_replace( ' ', '_', $sFileName );
	// 	$aConds = [
	// 		"old_text LIKE '%{{#drawio:" . $sFileName . "}}%'",
	// 		"old_text LIKE '%{{#drawio: " . $sFileName . "}}%'",
	// 		"old_text LIKE '%{{#drawio:" . $sFileName . "|%'",
	// 		"old_text LIKE '%{{#drawio: " . $sFileName . "|%'",
	// 	];


	/**
	 * Converts DrawioEditor SVG <object> elements to <img> elements
	 * in the PDF DOM so the PDF exporter can pick them up.
	 *
	 * @param mixed $oTitle
	 * @param \DOMDocument $oPageDOM
	 * @param array &$aParams
	 * @param \DOMXPath $oDOMXPath
	 * @param array &$aClassesToRemove
	 * @return bool
	 */
	/**
	 * Converts DrawioEditor SVG <object> elements to <img> elements after
	 * findFiles() has run, ensuring they are uploaded and rendered in the PDF.
	 * Runs on the actual template DOMDocument used for PDF generation.
	 * Uses reflection to add resolved files to the PDFServlet's file list.
	 *
	 * @param mixed $oPDFServlet
	 * @param \DOMDocument $oHtml Template DOM (the one findFiles() operated on)
	 * @param array $aFiles (passed by value — use reflection on $oPDFServlet)
	 * @param array $aParams
	 * @param \DOMXPath $oDOMXPath
	 * @return bool
	 */
	public static function onBSUEModulePDFAfterFindFiles( $oSender, $oHtml, &$aFiles, $aParams, $oDOMXPath ) {
		$objectTags = $oHtml->getElementsByTagName( 'object' );
		$toReplace = [];
		foreach ( $objectTags as $objectTag ) {
			if ( strpos( $objectTag->getAttribute( 'id' ), 'drawio-img-' ) !== false
				&& $objectTag->hasAttribute( 'data' )
			) {
				$toReplace[] = $objectTag;
			}
		}

		if ( empty( $toReplace ) ) {
			return true;
		}

		$repoGroup = MediaWikiServices::getInstance()->getRepoGroup();

		foreach ( $toReplace as $objectTag ) {
			// Extract URL, strip query string
			$dataUrl = $objectTag->getAttribute( 'data' );
			$qPos = strpos( $dataUrl, '?' );
			if ( $qPos !== false ) {
				$dataUrl = substr( $dataUrl, 0, $qPos );
			}

			// Extract filename from URL path
			$fileName = wfBaseName( urldecode( $dataUrl ) );
			if ( !$fileName ) {
				continue;
			}

			// Find file in MediaWiki repo
			$file = $repoGroup->findFile( $fileName );
			if ( !$file || !$file->exists() ) {
				continue;
			}

			// Try SVG → PNG rasterisation
			$finalName = null;
			$finalPath = null;

			if ( $file->isVectorized() ) {
				$width = $file->getWidth();
				if ( !$width ) {
					// SVG has no explicit pixel width (e.g. width="100%") — use a safe default
					$width = 800;
				}
				try {
					$transform = $file->transform( [ 'width' => $width ], \File::RENDER_NOW );
					if ( $transform && !( $transform instanceof \MediaTransformError ) ) {
						$storagePath = $transform->getStoragePath();
						if ( $storagePath ) {
							$backend = $file->getRepo()->getBackend();
							$fsFile = $backend->getLocalReference( [ 'src' => $storagePath ] );
							if ( $fsFile && file_exists( $fsFile->getPath() ) ) {
								$finalPath = $fsFile->getPath();
								$finalName = wfBaseName( $finalPath );
							}
						}
					}
				} catch ( \Exception $e ) {
					// Fall through to SVG fallback below
				}
			}

			// Fallback: use original SVG file directly
			if ( !$finalPath ) {
				try {
					$localRef = $file->getRepo()->getLocalReference( $file->getPath() );
					if ( $localRef && file_exists( $localRef->getPath() ) ) {
						$finalPath = $localRef->getPath();
						$finalName = $file->getName();
					}
				} catch ( \Exception $e ) {
					continue;
				}
			}

			if ( !$finalPath || !$finalName ) {
				continue;
			}

			// Build <img> element with correct src pointing to the local file
			$img = $oHtml->createElement( 'img' );
			$img->setAttribute( 'src', 'images/' . urlencode( $finalName ) );
			foreach ( [ 'id', 'title', 'class' ] as $attr ) {
				if ( $objectTag->hasAttribute( $attr ) ) {
					$img->setAttribute( $attr, $objectTag->getAttribute( $attr ) );
				}
			}

			// Set PDF-appropriate style (handle zero/missing max-width)
			$style = $objectTag->getAttribute( 'style' );
			preg_match( '#max-width:\s*(\d+)px#', $style, $m );
			$maxWidth = isset( $m[1] ) ? (int)$m[1] : 0;
			if ( $maxWidth > 690 ) {
				$img->setAttribute( 'style', 'width: 99%; height: auto;' );
			} elseif ( $maxWidth > 0 ) {
				$img->setAttribute( 'style', 'width: ' . $maxWidth . 'px; height: auto;' );
			} else {
				$img->setAttribute( 'style', 'width: 100%; height: auto;' );
			}

			$objectTag->parentNode->replaceChild( $img, $objectTag );

			// $aFiles is passed by reference through the hookRunner chain —
			// modifying it here updates PDFServlet::$aFiles directly.
			if ( !isset( $aFiles['images'] ) ) {
				$aFiles['images'] = [];
			}
			$aFiles['images'][$finalName] = $finalPath;
		}

		return true;
	}

	public static function onBSUEModulePDFcleanUpDOM( $oTitle, $oPageDOM, &$aParams, $oDOMXPath, &$aClassesToRemove ) {
		$objectTags = $oPageDOM->getElementsByTagName( 'object' );
		$toReplace = [];
		foreach ( $objectTags as $objectTag ) {
			if ( strpos( $objectTag->getAttribute( 'id' ), 'drawio-img-' ) !== false
				&& $objectTag->hasAttribute( 'data' )
			) {
				$toReplace[] = $objectTag;
			}
		}
		foreach ( $toReplace as $objectTag ) {
			$img = $oPageDOM->createElement( 'img' );
			$img->setAttribute( 'src', $objectTag->getAttribute( 'data' ) );
			foreach ( [ 'id', 'style', 'title', 'class' ] as $attr ) {
				if ( $objectTag->hasAttribute( $attr ) ) {
					$img->setAttribute( $attr, $objectTag->getAttribute( $attr ) );
				}
			}
			$objectTag->parentNode->replaceChild( $img, $objectTag );
		}
		return true;
	}

	public static function onImagePageAfterImageLinks( $imagePage, &$html ) {
		$fileName = $imagePage->getFile()->getTitle()->getDBkey();

		if ( str_ends_with( $fileName, '.svg' ) ) {
			$fileName = substr( $fileName, 0, -4 );
		} elseif ( str_ends_with( $fileName, '.png' ) ) {
			$fileName = substr( $fileName, 0, -4 );
		} else {
			return;
		}

		$services = MediaWikiServices::getInstance();
		$dbr = $services->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$linkRenderer = $services->getLinkRenderer();

		$aLinks = [];
		$pagePropsRes = $dbr->select(
			'page_props',
			'pp_page',
			[
				'pp_propname' => 'drawio-image',
				'pp_value' => $fileName
			],
			__METHOD__
		);
		foreach ( $pagePropsRes as $row ) {
			$title = Title::newFromID( $row->pp_page );
			$link = $linkRenderer->makeLink( $title );
			$aLinks[$title->getPrefixedDBkey()] = Html::rawElement( 'li', [], $link );
		}
		ksort( $aLinks );

		$html .= Html::rawElement( 'h2', [], wfMessage( 'drawioeditor-usage' )->escaped() );
		$html .= Html::openElement( 'ul' ) . "\n";
		if ( empty( $aLinks ) ) {
			$html .= Html::rawElement( 'p', [], wfMessage( 'drawio-not-used' )->plain() );
		} else {
			$html .= implode( "\n", $aLinks );
		}
		$html .= Html::closeElement( 'ul' );
	}
}
