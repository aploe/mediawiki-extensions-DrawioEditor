<?php

namespace MediaWiki\Extension\DrawioEditor\Hook;

use MediaWiki\MediaWikiServices;

/**
 * New-style hook handler for BSUEModulePDFAfterFindFiles.
 *
 * Converts DrawioEditor <object data="…svg"> elements to <img> elements
 * and uploads the resolved files directly to the PDF webservice so they
 * arrive before the HTML is sent and the PDF is rendered.
 *
 * Direct upload (calling /UploadAsset ourselves) is used instead of relying
 * on the $aFiles reference chain, which is destroyed by array_merge() inside
 * HookContainer::callLegacyHook() when the hook is registered as a legacy
 * string handler.  Even though this is a new-style handler (called via
 * $handler->onBSUEModulePDFAfterFindFiles( ...$args )), the direct upload
 * provides an unambiguous guarantee that the files reach the servlet.
 *
 * error_log() is used for diagnostics — output appears in the PHP error log
 * (e.g. docker logs <container>, or /var/log/apache2/error.log).
 */
class BSUEModulePDFAfterFindFilesHandler {

	/**
	 * @param mixed        $oSender   PDFServletHookRunner instance
	 * @param \DOMDocument $oHtml     Template DOM that findFiles() is operating on
	 * @param array        &$aFiles   Reference to PDFServlet::$aFiles
	 * @param array        $aParams   PDF params: soap-service-url, document-token, …
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
		file_put_contents( '/tmp/drawio_pdf_debug.log', date( 'Y-m-d H:i:s' ) . " hook called\n", FILE_APPEND );

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

		error_log( '[DrawioEditor] onBSUEModulePDFAfterFindFiles: found ' . count( $toReplace ) . ' drawio object tag(s)' );

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
			error_log( '[DrawioEditor] processing file "' . $fileName . '" from URL "' . $dataUrl . '"' );

			if ( !$fileName ) {
				error_log( '[DrawioEditor] could not extract filename, skipping' );
				continue;
			}

			$file = $repoGroup->findFile( $fileName );
			if ( !$file || !$file->exists() ) {
				error_log( '[DrawioEditor] file not found in repo: ' . $fileName );
				continue;
			}

			error_log( '[DrawioEditor] found file ' . $file->getName()
				. ' (vectorized=' . ( $file->isVectorized() ? 'yes' : 'no' )
				. ', width=' . $file->getWidth() . ')' );

			$finalName = null;
			$finalPath = null;

			// --- SVG → PNG rasterisation ---
			if ( $file->isVectorized() ) {
				$width = $file->getWidth();
				if ( !$width ) {
					$width = 800;
					error_log( '[DrawioEditor] zero-width SVG, using default 800px' );
				}
				try {
					$transform = $file->transform(
						[ 'width' => $width ],
						\File::RENDER_NOW
					);
					error_log( '[DrawioEditor] transform result: '
						. ( $transform ? get_class( $transform ) : 'null' ) );

					if ( $transform && !( $transform instanceof \MediaTransformError ) ) {
						$storagePath = $transform->getStoragePath();
						if ( $storagePath ) {
							$backend = $file->getRepo()->getBackend();
							$fsFile  = $backend->getLocalReference( [ 'src' => $storagePath ] );
							if ( $fsFile && file_exists( $fsFile->getPath() ) ) {
								$finalPath = $fsFile->getPath();
								$finalName = wfBaseName( $finalPath );
								error_log( '[DrawioEditor] rasterised PNG: ' . $finalPath );
							} else {
								error_log( '[DrawioEditor] storagePath set but fsFile not found on disk' );
							}
						}
					} elseif ( $transform instanceof \MediaTransformError ) {
						error_log( '[DrawioEditor] transform error: ' . $transform->toHtml() );
					}
				} catch ( \Exception $e ) {
					error_log( '[DrawioEditor] transform exception: ' . $e->getMessage() );
				}
			}

			// --- Fallback: use the original SVG file directly ---
			if ( !$finalPath ) {
				try {
					$localRef = $file->getRepo()->getLocalReference( $file->getPath() );
					if ( $localRef && file_exists( $localRef->getPath() ) ) {
						$finalPath = $localRef->getPath();
						$finalName = $file->getName();
						error_log( '[DrawioEditor] using original SVG fallback: ' . $finalPath );
					} else {
						error_log( '[DrawioEditor] SVG fallback: localRef missing or file does not exist' );
					}
				} catch ( \Exception $e ) {
					error_log( '[DrawioEditor] SVG fallback exception: ' . $e->getMessage() );
					continue;
				}
			}

			if ( !$finalPath || !$finalName ) {
				error_log( '[DrawioEditor] no valid file path, skipping' );
				continue;
			}

			// Build <img> to replace the <object>
			$img = $oHtml->createElement( 'img' );
			$img->setAttribute( 'src', 'images/' . urlencode( $finalName ) );
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

			// Belt-and-suspenders: also add to $aFiles in case the reference works
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
	 * Replicates the multipart POST format used by BsPDFServlet::uploadFiles().
	 *
	 * @param array $aParams PDF params (soap-service-url, document-token, …)
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

		foreach ( $files as $fileName => $filePath ) {
			if ( !file_exists( $filePath ) ) {
				error_log( '[DrawioEditor] file does not exist on disk: ' . $filePath );
				continue;
			}
			$fieldname   = md5( $fileName );
			$multipart[] = [
				'name'     => $fieldname,
				'contents' => file_get_contents( $filePath ),
				'filename' => $fileName,
			];
			$multipart[] = [ 'name' => $fieldname . '_name', 'contents' => $fileName ];
			error_log( '[DrawioEditor] queued for upload: ' . $fileName . ' (' . $filePath . ')' );
		}

		try {
			$userAgent      = MediaWikiServices::getInstance()->getHttpRequestFactory()->getUserAgent();
			$requestOptions = $GLOBALS['bsgUEModulePDFRequestOptions'] ?? [];
			$clientConfig   = array_merge(
				[ 'timeout' => 120, 'headers' => [ 'User-Agent' => $userAgent ] ],
				$requestOptions
			);

			$client   = new \GuzzleHttp\Client( $clientConfig );
			$response = $client->request( 'POST', $soapUrl . '/UploadAsset', [ 'multipart' => $multipart ] );
			error_log( '[DrawioEditor] upload response status: ' . $response->getStatusCode() );
		} catch ( \Exception $e ) {
			error_log( '[DrawioEditor] upload exception: ' . $e->getMessage() );
		}
	}
}
