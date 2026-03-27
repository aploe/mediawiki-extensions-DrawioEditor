<?php

namespace MediaWiki\Extension\DrawioEditor\Hook;

use MediaWiki\MediaWikiServices;

/**
 * New-style hook handler for BSUEModulePDFAfterFindFiles.
 *
 * Converts DrawioEditor <object data="...svg"> elements to <img> elements
 * and uploads the resolved files directly to the PDF webservice so they
 * arrive before the HTML is sent and the PDF is rendered.
 */
class BSUEModulePDFAfterFindFilesHandler {

	/**
	 * @param mixed        $oSender   PDFServletHookRunner instance
	 * @param \DOMDocument $oHtml     Template DOM that findFiles() is operating on
	 * @param array        &$aFiles   Reference to PDFServlet::$aFiles
	 * @param array        $aParams   PDF params: soap-service-url, document-token, ...
	 * @param \DOMXPath    $oDOMXPath XPath helper for the template DOM
	 * @return bool
	 */
	public function onBSUEModulePDFAfterFindFiles(
		$oSender,
		$oHtml,
		&$aFiles,
		$aParams,
		$oDOMXPath
	): bool {
		error_log( '[DrawioEditor] onBSUEModulePDFAfterFindFiles: hook called' );

		// Snapshot the live NodeList before any DOM mutations
		$objectTags = $oHtml->getElementsByTagName( 'object' );
		$toReplace = [];
		foreach ( $objectTags as $objectTag ) {
			if (
				strpos( $objectTag->getAttribute( 'id' ), 'drawio-img-' ) !== false
				&& $objectTag->hasAttribute( 'data' )
			) {
				$toReplace[] = $objectTag;
			}
		}

		error_log( '[DrawioEditor] found ' . count( $toReplace ) . ' drawio object tag(s)' );

		if ( empty( $toReplace ) ) {
			return true;
		}

		$repoGroup = MediaWikiServices::getInstance()->getRepoGroup();
		$filesToUpload = [];

		foreach ( $toReplace as $objectTag ) {
			// Strip query string from the data URL
			$dataUrl = $objectTag->getAttribute( 'data' );
			$qPos = strpos( $dataUrl, '?' );
			if ( $qPos !== false ) {
				$dataUrl = substr( $dataUrl, 0, $qPos );
			}

			$fileName = wfBaseName( urldecode( $dataUrl ) );
			error_log( '[DrawioEditor] processing "' . $fileName . '"' );

			if ( !$fileName ) {
				continue;
			}

			$file = $repoGroup->findFile( $fileName );
			if ( !$file || !$file->exists() ) {
				error_log( '[DrawioEditor] file not found in repo: ' . $fileName );
				continue;
			}

			// Get local filesystem path for the source file
			$backend  = $file->getRepo()->getBackend();
			$localRef = $backend->getLocalReference( [ 'src' => $file->getPath() ] );
			if ( !$localRef || !file_exists( $localRef->getPath() ) ) {
				error_log( '[DrawioEditor] source file not accessible on disk' );
				continue;
			}
			$srcPath = $localRef->getPath();

			$finalName = null;
			$finalPath = null;

			// --- SVG: rasterise to PNG with rsvg-convert ---
			if ( $file->isVectorized() ) {
				$width = $file->getWidth() ?: 1372;
				$pngName = preg_replace( '/\.svg$/i', '.png', $fileName );
				$tmpPng  = sys_get_temp_dir() . '/drawio_pdf_' . md5( $fileName ) . '.png';

				// Remove stale temp file if present
				if ( file_exists( $tmpPng ) ) {
					unlink( $tmpPng );
				}

				$rsvg = '/usr/bin/rsvg-convert';
				if ( is_executable( $rsvg ) ) {
					$cmd    = escapeshellcmd( $rsvg )
						. ' -w ' . (int)$width
						. ' ' . escapeshellarg( $srcPath )
						. ' -o ' . escapeshellarg( $tmpPng )
						. ' 2>&1';
					$output = shell_exec( $cmd );
					if ( file_exists( $tmpPng ) && filesize( $tmpPng ) > 0 ) {
						$finalPath = $tmpPng;
						$finalName = $pngName;
						error_log( '[DrawioEditor] rsvg-convert OK: ' . $tmpPng . ' (' . filesize( $tmpPng ) . ' bytes) as ' . $finalName );
					} else {
						error_log( '[DrawioEditor] rsvg-convert failed (output: ' . trim( $output ) . '), trying Imagick' );
					}
				}

				// --- Imagick fallback ---
				if ( !$finalPath && class_exists( 'Imagick' ) ) {
					try {
						$imagick = new \Imagick();
						$imagick->setResolution( 150, 150 );
						$imagick->readImage( $srcPath );
						$imagick->setImageFormat( 'png' );
						$pngData = $imagick->getImagesBlob();
						$imagick->destroy();
						if ( $pngData && strlen( $pngData ) > 0 ) {
							file_put_contents( $tmpPng, $pngData );
							$finalPath = $tmpPng;
							$finalName = $pngName;
							error_log( '[DrawioEditor] Imagick OK: ' . strlen( $pngData ) . ' bytes as ' . $finalName );
						}
					} catch ( \Exception $e ) {
						error_log( '[DrawioEditor] Imagick failed: ' . $e->getMessage() );
					}
				}
			}

			// --- Fallback: upload the original file as-is ---
			if ( !$finalPath ) {
				$finalPath = $srcPath;
				$finalName = $fileName;
				error_log( '[DrawioEditor] using original file as fallback: ' . $srcPath );
			}

			// Build <img> to replace the <object>
			$img = $oHtml->createElement( 'img' );
			$img->setAttribute( 'src', 'images/' . $finalName );
			foreach ( [ 'id', 'title', 'class' ] as $attr ) {
				if ( $objectTag->hasAttribute( $attr ) ) {
					$img->setAttribute( $attr, $objectTag->getAttribute( $attr ) );
				}
			}

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
			error_log( '[DrawioEditor] replaced <object> with <img src="images/' . $finalName . '">' );

			$filesToUpload[$finalName] = $finalPath;

			if ( !isset( $aFiles['images'] ) ) {
				$aFiles['images'] = [];
			}
			$aFiles['images'][$finalName] = $finalPath;
		}

		if ( !empty( $filesToUpload ) ) {
			$this->uploadFilesToPDFService( $aParams, $filesToUpload );
		}

		return true;
	}

	/**
	 * Upload resolved files directly to the PDF webservice.
	 *
	 * @param array $aParams PDF params (soap-service-url, document-token, ...)
	 * @param array $files   [ filename => absolute filesystem path ]
	 */
	private function uploadFilesToPDFService( array $aParams, array $files ): void {
		$soapUrl       = isset( $aParams['soap-service-url'] )
			? rtrim( $aParams['soap-service-url'], '/' )
			: null;
		$documentToken = $aParams['document-token'] ?? null;

		if ( !$soapUrl || !$documentToken ) {
			error_log( '[DrawioEditor] missing soap-service-url or document-token, cannot upload' );
			return;
		}

		$multipart = [
			[ 'name' => 'fileType',      'contents' => 'images' ],
			[ 'name' => 'documentToken', 'contents' => $documentToken ],
			[ 'name' => 'wikiId',        'contents' => \WikiMap::getCurrentWikiId() ],
		];

		$hasFiles = false;
		foreach ( $files as $fileName => $filePath ) {
			$fileSize = file_exists( $filePath ) ? filesize( $filePath ) : -1;
			if ( $fileSize <= 0 ) {
				error_log( '[DrawioEditor] skipping empty/missing file: ' . $filePath );
				continue;
			}
			$fieldname   = md5( $fileName );
			$multipart[] = [
				'name'     => $fieldname,
				'contents' => file_get_contents( $filePath ),
				'filename' => $fileName,
			];
			$multipart[] = [ 'name' => $fieldname . '_name', 'contents' => $fileName ];
			error_log( '[DrawioEditor] queued for upload: ' . $fileName . ' (' . $fileSize . ' bytes)' );
			$hasFiles = true;
		}

		if ( !$hasFiles ) {
			error_log( '[DrawioEditor] no files to upload' );
			return;
		}

		try {
			$userAgent      = MediaWikiServices::getInstance()->getHttpRequestFactory()->getUserAgent();
			$requestOptions = $GLOBALS['bsgUEModulePDFRequestOptions'] ?? [];
			$clientConfig   = array_merge(
				[ 'timeout' => 120, 'headers' => [ 'User-Agent' => $userAgent ] ],
				$requestOptions
			);

			$client       = new \GuzzleHttp\Client( $clientConfig );
			$response     = $client->request( 'POST', $soapUrl . '/UploadAsset', [ 'multipart' => $multipart ] );
			$responseBody = (string)$response->getBody();
			error_log( '[DrawioEditor] upload status: ' . $response->getStatusCode() . ' body: ' . substr( $responseBody, 0, 300 ) );
		} catch ( \Exception $e ) {
			error_log( '[DrawioEditor] upload exception: ' . $e->getMessage() );
		}
	}
}
