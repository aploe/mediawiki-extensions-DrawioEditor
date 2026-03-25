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
			preg_match( '#max-width: (\d*?)px;#', $style, $matches );
			if ( $matches[1] > 690 ) {
				$oImageElement->setAttribute( 'style', 'width: 99%' );
			} else {
				$oImageElement->setAttribute( 'style', 'width: ' . $matches[1] . 'px' );
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
