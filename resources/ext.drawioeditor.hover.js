( function () {
	'use strict';

	/**
	 * Hover effect for DrawioEditor SVG object elements.
	 * CSS :hover does not work inside <object> browsing contexts, so we use
	 * JS mouseover/mouseout listeners on the SVG contentDocument instead.
	 *
	 * Supports two SVG structure formats produced by different draw.io versions:
	 *   Format A (newer): <a> → <g> → <path|rect>
	 *   Format B (older): <a> → <path|rect>  (direct child)
	 */
	function setupDrawioHoverEvents( obj ) {
		var doc = obj.contentDocument;
		if ( !doc || !doc.documentElement ) {
			return;
		}

		Array.prototype.forEach.call( doc.querySelectorAll( 'a' ), function ( link ) {
			// Find shape and its hover target depending on SVG format
			var shape = null;
			var effectTarget = null;

			for ( var i = 0; i < link.children.length; i++ ) {
				var tag = link.children[ i ].tagName;
				if ( tag === 'path' || tag === 'rect' ) {
					shape = link.children[ i ];       // Format B: direct child
					effectTarget = shape;
					break;
				}
				if ( tag === 'g' && !shape ) {
					var inner = link.children[ i ].querySelector( 'path, rect' );
					if ( inner ) {
						shape = inner;                // Format A: inside first <g>
						effectTarget = link.children[ i ];
					}
				}
			}

			if ( !shape ) {
				return;
			}

			link.style.cursor = 'pointer';

			// Detect effective fill: "none" or fill-opacity 0 = transparent
			var fillAttr = shape.getAttribute( 'fill' );
			var fillOpacity = parseFloat( shape.getAttribute( 'fill-opacity' ) || '1' );
			var hasFill = fillAttr !== 'none' && fillOpacity > 0;

			if ( hasFill ) {
				// Colored shape: brighten with filter on hover
				effectTarget.style.transition = 'filter 180ms ease';
				link.addEventListener( 'mouseover', function () {
					effectTarget.style.filter = 'brightness(1.15)';
				} );
				link.addEventListener( 'mouseout', function () {
					effectTarget.style.filter = 'none';
				} );
			} else {
				// Transparent shape: show white overlay on hover
				shape.style.transition = 'fill-opacity 180ms ease';
				shape.style.fill = 'white';
				shape.style.fillOpacity = '0';
				link.addEventListener( 'mouseover', function () {
					shape.style.fillOpacity = '0.15';
				} );
				link.addEventListener( 'mouseout', function () {
					shape.style.fillOpacity = '0';
				} );
			}
		} );
	}

	function initHover() {
		$( 'object[id^="drawio-img-"][type="image/svg+xml"]' ).each( function () {
			var obj = this;
			if ( obj.contentDocument ) {
				setupDrawioHoverEvents( obj );
			} else {
				obj.addEventListener( 'load', function () {
					setupDrawioHoverEvents( obj );
				} );
			}
		} );
	}

	// window.load must have fired for contentDocument to be available.
	// If ResourceLoader runs after window.load (cached pages), call immediately.
	if ( document.readyState === 'complete' ) {
		initHover();
	} else {
		$( window ).on( 'load', initHover );
	}

}() );
