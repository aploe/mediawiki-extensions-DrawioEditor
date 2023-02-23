
function DrawioEditor( id, filename, type, interactive, updateHeight, updateWidth,
	updateMaxWidth, baseUrl, latestIsApproved, imageURL ) {
	var that = this;

	this.id = id;
	this.filename = filename;
	this.imgType = type;
	this.interactive = interactive;
	this.updateHeight = updateHeight;
	this.updateWidth = updateWidth;
	this.updateMaxWidth = updateMaxWidth;
	this.baseUrl = baseUrl;
	this.latestIsApproved = latestIsApproved;

	//Could be 'en', 'fr', 'de-formal', 'zh-hant', ...
	var currentUserLanguage = mw.user.options.get( 'language', 'en' ).split( '-' );
	this.language = currentUserLanguage[0];

	if (this.imgType == 'svg') {
		this.imgMimeType = 'image/svg+xml';
	} else if (this.imgType == 'png') {
		this.imgMimeType = 'image/png';
	} else {
		throw new Error('unkown file type');
	}

	this.imageBox = $("#drawio-img-box-" + id);
	this.image = $("#drawio-img-" + id);
	if (interactive) {
		this.imageURL = this.image.attr('data-editurl');
	} else {
		this.imageURL = imageURL || undefined;
	}
	this.imageHref = $("#drawio-img-href-" + id);
	this.placeholder = $("#drawio-placeholder-" + id);

	this.iframeBox = $("#drawio-iframe-box-" + id);
	this.iframeBox.resizable({
		"handles": "s",
	"distance": 0,
	start: function(event, ui) {
			that.showOverlay();
	},
		stop: function(event, ui) {
			$(this).css("width", '');
			that.hideOverlay();
		}
	});
	this.iframeBox.resizable("enable");

	this.iframeOverlay = $("#drawio-iframe-overlay-" + id);
	this.iframeOverlay.hide();

	// Determine if page is secured over https (aploe)
	var iframeviahttps = 0;
	if (location.protocol === 'https:') iframeviahttps = 1;

	var localAttr = this.baseUrl !== 'https://embed.diagrams.net' ? "&local=1" : "";
	this.iframe = $('<iframe>', {
		// Add https to base url (aploe)
		src: this.baseUrl + '/?https=' + iframeviahttps + '&embed=1&proto=json&spin=1&analytics=0&picker=0&lang=' + this.language + localAttr,
		// src: this.baseUrl + '/?embed=1&proto=json&spin=1&analytics=0&picker=0&lang=' + this.language + localAttr,
		id: 'drawio-iframe-' + id,
		class: 'DrawioEditorIframe'
	});
	this.iframe.appendTo(this.iframeBox);

	this.iframeWindow = this.iframe.prop('contentWindow');

	this.show();
}

DrawioEditor.prototype.destroy = function() {
	this.iframe.remove();
	// Add a page refresh to see the new image (aploe)
	location.reload();
}

DrawioEditor.prototype.show = function() {
	this.imageBox.hide();
	this.iframeBox.height(Math.max(this.imageBox.height()+100, 800));
	this.iframeBox.show();
	$( '#approved-displaywarning' ).remove();
	if ( !this.latestIsApproved ) {
		$msg = mw.message( "drawioeditor-approved-editwarning" ).text();
		$('#bodyContent').before("<p id=\"warningmsg\" class=\"successbox\">" + $msg + "</p>");
	}
}

DrawioEditor.prototype.hide = function() {
	this.iframeBox.hide();
	this.imageBox.show();
}

DrawioEditor.prototype.showOverlay = function() {
	this.iframeOverlay.show();
}

DrawioEditor.prototype.hideOverlay = function() {
	this.iframeOverlay.hide();
}

DrawioEditor.prototype.updateImage = function (imageinfo) {
	this.imageURL = imageinfo.url + '?ts=' + imageinfo.timestamp;
	if (this.interactive) {
		this.image.attr("data-editurl", this.imageURL);
	} else {
		this.image.attr("src", this.imageURL);
	}
	this.imageHref.attr("href", imageinfo.descriptionurl);
	if (this.updateHeight)
		this.image.css('height', imageinfo.height);
	if (this.updateWidth)
		this.image.css('width', imageinfo.width);
	if (this.updateMaxWidth)
		this.image.css('max-width', imageinfo.width);
	if (this.placeholder) {
		this.placeholder.hide();
		this.image.show();
	}
}

DrawioEditor.prototype.sendMsgToIframe = function(data) {
	this.iframeWindow.postMessage(JSON.stringify(data), this.baseUrl);
}

DrawioEditor.prototype.showDialog = function(title, message) {
	this.hideSpinner();
	this.sendMsgToIframe({
		'action': 'dialog',
		'title': title,
		'message': message,
		'button': 'Discard',
		'modified': true
	});
}

DrawioEditor.prototype.showSpinner = function() {
	this.iframeBox.resizable("disable");
	this.showOverlay();
	this.sendMsgToIframe({
		'action': 'spinner',
	'show': true
	});
}

DrawioEditor.prototype.hideSpinner = function() {
	this.iframeBox.resizable("enable");
	this.hideOverlay();
	this.sendMsgToIframe({
		'action': 'spinner',
		'show': false
	});
}

DrawioEditor.prototype.downloadFromWiki = function() {
	var that = this;
	var xhr = new XMLHttpRequest();
	xhr.onreadystatechange = function() {
		if (this.readyState == 4) {
		if (this.status == 200) {
				var res = this.response;
				var fr = new FileReader();
				fr.onload = function(ev) { that.loadImageFromDataURL(res.type, ev.target.result); };
				fr.readAsDataURL(res);
		} else {
			that.showDialog('Load failed',
				'HTTP request to fetch image failed: ' + this.status +
			'<br>Image: ' + that.imageURL);
		}
		}
	}
	xhr.onload = function() {

	}
	xhr.open('GET', this.imageURL);
	xhr.responseType = 'blob';
	xhr.send();
}

DrawioEditor.prototype.loadImageFromDataURL = function(type, dataurl) {
		if (type != this.imgMimeType) {
		this.showDialog('Load failed',
			'Invalid mime type when loading image from wiki:' +
		'<br>Actual: ' + type + ' / Expected: ' + this.imgMimeType +
		'<br>Image: ' + this.imageURL);
		return;
		}
		if (this.imgType == 'svg') {
			this.sendMsgToIframe({ action: 'load', xml: dataurl});
		} else if (this.imgType == 'png') {
			this.sendMsgToIframe({ action: 'load', xmlpng: dataurl});
		}
}

DrawioEditor.prototype.loadImage = function() {
	if (this.imageURL == undefined) {
		// just load without data if there's no current image
		this.sendMsgToIframe({ action: 'load' });
	return;
	}
	// fetch image from wiki. it must contain both image data and
	// draw.io xml data. see DrawioEditor.saveCallback()
	this.downloadFromWiki();
}

DrawioEditor.prototype.uploadToWiki = function(blob) {
	var that = this;

	var api = new mw.Api();
	api.upload(blob, { filename: this.filename, ignorewarnings: true, format: 'json' } )
		.done( function(data) {
			if (!data.upload) {
				if (data.error) {
						that.showDialog('Save failed',
				   'The wiki returned the follwing error when uploading:<br>' +
				   data.error.info);
			} else {
						that.showDialog('Save failed',
				   'The upload to the wiki failed.' +
				   '<br>Check javascript console for details.');
			}
			console.log('upload to wiki failed');
			console.log(data);
			} else {
				that.updateImage(data.upload.imageinfo);
				that.hideSpinner();
			}
		})
		.fail( function(retStatus, data) {
			that.hideSpinner();
			if( retStatus == "exists" ){
				that.updateImage(data.upload.imageinfo);
			} else {
				if ( data.error ) {
					that.showDialog('Save failed',
					'Upload to wiki failed!' +
				'<br>Error: ' + data.error.info +
				'<br>Check javascript console for details.');
				}
			}
		});

}

DrawioEditor.prototype.save = function(datauri) {
	// the data in the data uri contains both the image _and_ draw.io XML, see
	// this.saveCallback()

	parts = /^data:([^;,=]+\/[^;,=]+)?((?:;[^;,=]+=[^;,=]+)+)?(?:;(base64))?,(.+)$/.exec(datauri);

	// currently this save/upload to wiki code assumes that drawio passes data
	// URIs with base64 encoded data. this is currently the case but may not be
	// true forever. the check below errors out if the URI data is not base64
	// encoded (and if the data URI is otherwise deemed invalid.
	if (!parts || parts[1] != this.imgMimeType || parts[3] != 'base64' ||
			typeof parts[4] !== 'string' || parts[4].length < 1) {
	that.showDialog('Save failed', 'Got unexpected data from drawio export.');
	return;
	}

	// convert base64 to uint8 array
	datastr = atob(parts[4]);
	var expr = /"http:\/\/[^"]*?1999[^"]*?"/gmi;
	datastr = datastr.replace( expr, '"http://www.w3.org/1999/xhtml"' );

	// To make the links work in an object tag
	var expr3 = /xlink\=\"http\:\/\/www\.w3\.org\/1999\/xhtml"/gmi;
	datastr = datastr.replace( expr3, 'xlink="http://www.w3.org/1999/xlink"');

	// Add the target="_top" attribute to a tag to avoid links opening only in small object tag on articles
	datastr = datastr.replace(/<a([^>]*)>/gi, function(match, p1) {
		if (!p1.match(/target=/i)) {
		  return '<a' + p1 + ' target="_top">';
		} else {
		  return match;
		}
	});

	data = new Uint8Array(datastr.length)
	for (i = 0; i < datastr.length; i++) {
		data[i] = datastr.charCodeAt(i);
	}

	this.uploadToWiki(new Blob([data], {type: this.imgMimeType}));
}

DrawioEditor.prototype.exit = function() {
	this.hide();
	editor = null;
	$('#warningmsg').hide();
	this.destroy();
}

DrawioEditor.prototype.saveCallback = function() {
	this.showSpinner();

	// xmlsvg and xmlpng are known to work. the xml prefix causes the original
	// chart.io xml data to be added to the file, so it can be reimported later
	// without any data loss.
	var format = 'xml'+ this.imgType;

	this.sendMsgToIframe({
		'action': 'export',
		'embedImages': true,
		'format': format,
	});

	// TODO: prevent exit while saving
}

DrawioEditor.prototype.exportCallback = function(type, data) {
	this.showSpinner();
	this.save(data);
}

DrawioEditor.prototype.exitCallback = function() {
	this.exit();
}

DrawioEditor.prototype.initCallback = function () {
	this.loadImage();
}

// Send new config to editor (aploe)
DrawioEditor.prototype.sendConfig = function() {
	this.sendMsgToIframe({
		'action' : 'configure',
		'config' : {
			"version": "1.0",
			// "css": ".geMenubarContainer { background-color: #9d9d9d !important; color: #fff !important; }"
			"css": ".geMenubarContainer { background-color: #c0143c !important; }" +
			".geMenubarContainer a.geItem { color: #ffffff !important; }" + 
			".geMenubarContainer a.geItem:hover { color: #000000 !important; }" +
			".geMenubarContainer a.geItem:active { background: #c17285 !important; color: #000000 !important }" +
			".geBigButton[title=\"Save (Ctrl+S)\"] { background-color: #c0143c !important; }" +
			".geBigButton[title=\"Save (Ctrl+S)\"]:hover { background-color: #c17285 !important; }" +
			".geBigButton[title=\"Save (Ctrl+S)\"]:active { background-color: #920526 !important; }" +
			".geBigButton { background-color: #920526 !important; }"
		}
		});
}

var editor;

window.editDrawio = function(id, filename, type, interactive, updateHeight, updateWidth, updateMaxWidth, baseUrl, latestIsApproved, imageURL) {
	if (!editor) {
		window.drawioEditorBaseUrl = baseUrl;
		editor = new DrawioEditor(id, filename, type, interactive, updateHeight, updateWidth, updateMaxWidth, baseUrl, latestIsApproved, imageURL);
	} else {
		alert("Only one DrawioEditor can be open at the same time!");
	}
};

function drawioHandleMessage(e) {
	// we only act on event coming from "baseUrl" iframes
	if (window.drawioEditorBaseUrl.indexOf( e.origin ) !== 0)
		return;

	if (!editor)
		return;

	evdata = JSON.parse(e.data);

	switch(evdata['event']) {
		case 'init':
			editor.initCallback();
			break;

		case 'load':
			break;

		case 'save':
			editor.saveCallback();
			break;

		case 'export':
			editor.exportCallback(evdata['format'], evdata['data']);
			break;

		case 'exit':
			editor.exitCallback();
		// editor is null after this callback
			break;

		// Add configure event (aploe)
		case 'configure':
			editor.sendConfig();
			break;

		default:
			alert('Received unknown event from drawio iframe: ' + evdata['event']);
	}
};

window.addEventListener('message', drawioHandleMessage);
